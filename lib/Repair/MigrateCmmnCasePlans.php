<?php

/**
 * Repair step: drain `casePlanState` blobs into OpenRegister plan-item rows.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\CasePlanDrain\CasePlanMigrationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the drain on upgrade. Logs and continues: an upgrade never stops on
 * one case it cannot map (design.md section 3).
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */
class MigrateCmmnCasePlans implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param CasePlanMigrationService $migration The drain.
	 * @param LoggerInterface          $logger    Logger.
	 */
	public function __construct(
		private readonly CasePlanMigrationService $migration,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function getName(): string {
		return 'Move Dossiq CMMN case plans into OpenRegister plan items';
	}//end getName()

	/**
	 * Drain, then report the counts and every unmappable case.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
	 */
	public function run(IOutput $output): void {
		try {
			$reports = $this->migration->migrateAll(dryRun: false);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq CMMN case-plan drain did not run', ['exception' => $e->getMessage()]);
			$output->warning('CMMN case-plan drain did not run: ' . $e->getMessage());
			return;
		}

		$counts = [CasePlanMigrationService::MIGRATED => 0, CasePlanMigrationService::UNMAPPABLE => 0];
		foreach ($reports as $report) {
			$outcome = (string)$report['outcome'];
			$counts[$outcome] = (($counts[$outcome] ?? 0) + 1);
			if ($outcome === CasePlanMigrationService::UNMAPPABLE) {
				$this->logger->warning('Dossiq CMMN case left with its blob', ['case' => $report['caseId'], 'reason' => $report['reason']]);
				$output->warning(sprintf('CMMN case %s kept its blob: %s', $report['caseId'], $report['reason']));
			}
		}

		$output->info(
			sprintf(
				'CMMN case-plan drain: %d migrated, %d unmappable (run occ dossiq:cmmn:migrate-case-plans --strict to list them again).',
				$counts[CasePlanMigrationService::MIGRATED],
				$counts[CasePlanMigrationService::UNMAPPABLE]
			)
		);
	}//end run()
}//end class
