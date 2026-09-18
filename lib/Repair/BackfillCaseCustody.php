<?php

/**
 * Dossiq repair step: give every existing case its first holding.
 *
 * The chain of custody starts the day this change ships, which means every case
 * that already exists has a chain with a hole at the front: nothing says who
 * held it before anybody was writing holdings down. A chain with a hole is
 * worse than no chain, because it reads as an answer.
 *
 * So each case gets one holding, opened from the case's own start date rather
 * than from the moment this step ran. Dating it from the run would be the same
 * hole with a confident timestamp on it.
 *
 * Idempotent: a case that already has an open holding is skipped, so a repeated
 * upgrade neither duplicates the chain nor reopens a closed one.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Open the first holding of every case that has none.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */
class BackfillCaseCustody implements IRepairStep {

	/**
	 * The reason written on a backfilled holding.
	 *
	 * Stated rather than left blank: a reader who finds a holding with no
	 * reason cannot tell a backfill from a move somebody forgot to explain.
	 *
	 * @var string
	 */
	public const REASON = 'Opened by the custody backfill from the case as it stood';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService Bridge to OpenRegister.
	 * @param CaseCustodyChain $custody         The chain the holdings are written into.
	 * @param IAppConfig       $appConfig       App configuration.
	 * @param LoggerInterface  $logger          Logger.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseCustodyChain $custody,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name of this repair step.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
	 */
	public function getName(): string {
		return 'Open the first chain-of-custody holding for every existing case';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * Non-fatal by construction: an upgrade must not fail because a projection
	 * could not complete, and a case left without a holding is picked up by the
	 * next run.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('OpenRegister unavailable, so the custody backfill was skipped.');
			return;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$caseSchema = $this->appConfig->getValueString(Application::APP_ID, 'case_schema', '');
		$custodySchema = $this->appConfig->getValueString(Application::APP_ID, 'case_custody_schema', '');
		if ($register === '' || $caseSchema === '' || $custodySchema === '') {
			$output->info('The case or custody schema is not configured, so the custody backfill was skipped.');
			return;
		}

		$objectService->runAsSystem(
			function () use ($objectService, $register, $caseSchema, $output): void {
				$this->backfill(
					objectService: $objectService,
					register: $register,
					caseSchema: $caseSchema,
					output: $output,
				);
			}
		);
	}//end run()

	/**
	 * Walk every case and open the holding it is missing.
	 *
	 * @param object  $objectService OpenRegister's ObjectService.
	 * @param string  $register      The register id.
	 * @param string  $caseSchema    The case schema id.
	 * @param IOutput $output        Progress reporting.
	 *
	 * @return void
	 */
	public function backfill(object $objectService, string $register, string $caseSchema, IOutput $output): void {
		$rows = $objectService->findAll(['filters' => ['register' => $register, 'schema' => $caseSchema]]);
		if (is_array($rows) === false) {
			$output->info('Custody backfill: there was nothing to read.');
			return;
		}

		$tally = ['opened' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			$tally[$this->backfillOne(case: (array)$row)] += 1;
		}

		$output->info(
			'Custody backfill: ' . $tally['opened'] . ' holdings opened, '
			. $tally['skipped'] . ' cases already had one, ' . $tally['failed'] . ' failed.'
		);
	}//end backfill()

	/**
	 * Open one case's first holding, and say which tally it belongs to.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return string One of opened|skipped|failed.
	 */
	private function backfillOne(array $case): string {
		$caseId = trim((string)($case['id'] ?? ($case['uuid'] ?? '')));
		if ($caseId === '') {
			return 'failed';
		}

		try {
			$holding = $this->custody->begin(
				caseId: $caseId,
				organisationUnit: trim((string)($case['assignedGroup'] ?? '')),
				handler: trim((string)($case['assignee'] ?? '')),
				from: $this->startOf(case: $case),
				reason: self::REASON,
				movedBy: '',
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq custody backfill: a case did not get its first holding',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);

			return 'failed';
		}

		if ($holding === null) {
			return 'skipped';
		}

		return 'opened';
	}//end backfillOne()

	/**
	 * When the case started, as well as the stored case can say.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return string The date, or an empty string when the case names none.
	 */
	private function startOf(array $case): string {
		foreach (['startDate', 'registrationDate', 'requestedDate', 'created'] as $key) {
			$value = trim((string)($case[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		$self = ($case['@self'] ?? []);
		if (is_array($self) === true) {
			return trim((string)($self['created'] ?? ''));
		}

		return '';
	}//end startOf()
}//end class
