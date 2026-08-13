<?php

/**
 * Procest WOO Deadline Check Job
 *
 * Daily background job that checks WOO case deadlines and sends T-7 warning
 * notifications to the assigned behandelaar per WOO Art. 4.4.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
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

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCA\Procest\Service\WOODeadlineService;
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

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$caseTypeTitle = 'WOO Verzoek';

		if (empty($register) === true || empty($caseSchema) === true) {
			return;
		}

		// Find active WOO cases.
		$cases = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			filters: [
				'caseType.title' => $caseTypeTitle,
				'status' => ['open', 'in_behandeling'],
				'_limit' => 500,
			],
		);

		$warned = $this->warnDueCases(cases: $cases);

		if ($warned > 0) {
			$this->logger->info(
				'WOODeadlineCheckJob: sent ' . $warned . ' deadline warning(s)',
				['app' => Application::APP_ID],
			);
		}
	}//end run()

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
			$handler = $case['handler'] ?? $case['assignedUser'] ?? null;

			if ($caseId === null || $handler === null) {
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
