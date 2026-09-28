<?php

/**
 * Dossiq WOO Deadline Check Job
 *
 * Daily background job that checks WOO case deadlines and sends T-7 warning
 * notifications to the assigned behandelaar per WOO Art. 4.4.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WOODeadlineService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job that checks WOO case deadlines and emits T-7 warnings.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-4
 */
class WOODeadlineCheckJob extends TimedJob {

	use SearchesObjects;

	/**
	 * The title every version of the seeded Woo request case type carries.
	 *
	 * `lib/Settings/templates/woo-verzoek.json` seeds it. A case refers to its
	 * case type by uuid, so this title is only ever used to FIND those uuids,
	 * never as a filter on the case itself.
	 *
	 * @var string
	 */
	private const WOO_CASE_TYPE_TITLE = 'WOO Verzoek';

	/**
	 * The most rows one read asks for.
	 *
	 * @var int
	 */
	private const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param WOODeadlineService $deadlineService The WOO deadline service
	 * @param SettingsService $settingsService The settings service
	 * @param IAppManager $appManager The app manager
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly WOODeadlineService $deadlineService,
		private readonly SettingsService $settingsService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the WOO deadline warning check.
	 *
	 * Finds all active WOO cases and calls WOODeadlineService::checkAndWarn
	 * for each, emitting T-7 notifications to the assigned behandelaar.
	 *
	 * @param mixed $argument The job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-4
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$schemas = [
			'register' => $this->settingsService->getConfigValue('register'),
			'case' => $this->settingsService->getConfigValue('case_schema'),
			'caseType' => $this->settingsService->getConfigValue('case_type_schema'),
			'statusType' => $this->settingsService->getConfigValue('status_type_schema'),
		];
		if (in_array('', $schemas, true) === true) {
			return;
		}

		$warned = $this->warnDueCases(cases: $this->openWooCases(objectService: $objectService, schemas: $schemas));

		if ($warned > 0) {
			$this->logger->info(
				'WOODeadlineCheckJob: sent ' . $warned . ' deadline warning(s)',
				['app' => Application::APP_ID],
			);
		}
	}//end run()

	/**
	 * The Woo cases that are still open.
	 *
	 * Three reads, because a case holds its case type and its status as uuid
	 * references and OpenRegister filters a column on what it stores:
	 *
	 *   1. the uuids of every Woo request case type version, found by title
	 *      on the caseType schema, where `title` is a column;
	 *   2. the uuids of those case types' status types that are not final;
	 *   3. the cases on one of those case types in one of those statuses.
	 *
	 * The job used to ask the case for `caseType.title` and for `status` in
	 * `open`/`in_handling`. The first is a relation path, and OpenRegister
	 * answers a filter on a key the schema does not declare with no rows; the
	 * second compares status NAMES against a uuid column. Either one alone
	 * was enough to make every run find nothing.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $schemas       The register and the three schema ids.
	 *
	 * @return array<int, array<string, mixed>> The open Woo cases.
	 */
	private function openWooCases(object $objectService, array $schemas): array {
		$caseTypeIds = $this->idsOf(
			rows: $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $schemas['register'],
				schema: $schemas['caseType'],
				filters: ['title' => self::WOO_CASE_TYPE_TITLE, '_limit' => self::LIMIT],
			)
		);
		if ($caseTypeIds === []) {
			return [];
		}

		$openStatusIds = [];
		$statusTypes = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $schemas['register'],
			schema: $schemas['statusType'],
			filters: ['caseType' => $caseTypeIds, '_limit' => self::LIMIT],
		);
		foreach ($statusTypes as $statusType) {
			if (in_array(($statusType['isFinal'] ?? false), [true, 1, '1', 'true'], true) === true) {
				continue;
			}

			$openStatusIds = array_merge($openStatusIds, $this->idsOf(rows: [$statusType]));
		}

		if ($openStatusIds === []) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $schemas['register'],
			schema: $schemas['case'],
			filters: [
				'caseType' => $caseTypeIds,
				'status' => $openStatusIds,
				'_limit' => self::LIMIT,
			],
		);
	}//end openWooCases()

	/**
	 * The uuids of a list of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, string> The uuids, without empties.
	 */
	private function idsOf(array $rows): array {
		$ids = [];
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}//end idsOf()

	/**
	 * Emit a T-7 deadline warning for every case that still needs one.
	 *
	 * @param array<int, array<string, mixed>> $cases The active WOO cases
	 *
	 * @return int The number of warnings sent
	 */
	private function warnDueCases(array $cases): int {
		$warned = 0;
		foreach ($cases as $case) {
			$caseId = $case['id'] ?? $case['uuid'] ?? null;
			// The case schema names its handler `assignee`; `handler` and
			// `assignedUser` are fields it does not declare, so they never held one.
			$handler = $case['assignee'] ?? null;

			if ($caseId === null || is_string($handler) === false || $handler === '') {
				continue;
			}

			$result = $this->deadlineService->checkAndWarn(
				caseId: $caseId,
				handler: $handler,
			);

			if (($result['warned'] ?? false) === true) {
				$warned++;
			}
		}//end foreach

		return $warned;
	}//end warnDueCases()
}//end class
