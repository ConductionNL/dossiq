<?php

/**
 * Dossiq repair step: run the record imports a case type flags for the upgrade.
 *
 * A case type's `recordImports` declaration may say `runOnUpgrade: true`.
 * This step runs every such declaration of every case type in use, so the
 * records it names become cases on the upgrade that ships the case type,
 * without an operator running `occ dossiq:case:import-records`. A run is
 * idempotent: a stamped record is counted, not moved again.
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
 * @spec openspec/specs/case-record-import/spec.md#requirement-declared-imports-run-on-upgrade-req-cri-007
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Import\CaseRecordImport;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Runs the record imports flagged `runOnUpgrade` and reports their counts.
 *
 * @spec openspec/specs/case-record-import/spec.md#requirement-declared-imports-run-on-upgrade-req-cri-007
 */
class RunUpgradeRecordImports implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param CaseRecordImport $import   The generic record import.
	 * @param SettingsService  $settings Whether OpenRegister is available.
	 */
	public function __construct(
		private readonly CaseRecordImport $import,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-declared-imports-run-on-upgrade-req-cri-007
	 */
	public function getName(): string {
		return 'Dossiq: move the records case types import on upgrade onto cases';
	}//end getName()

	/**
	 * Run the flagged imports; a failure is a warning, never an aborted upgrade.
	 *
	 * @param IOutput $output Where the step reports.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-declared-imports-run-on-upgrade-req-cri-007
	 */
	public function run(IOutput $output): void {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping the record imports.');
			return;
		}

		try {
			$answers = $this->import->runOnUpgrade();
		} catch (Throwable $e) {
			$output->warning('The record imports could not run: ' . $e->getMessage());
			return;
		}

		if ($answers === []) {
			$output->info('No case type imports records on upgrade.');
			return;
		}

		foreach ($answers as $answer) {
			$output->info(
				sprintf(
					'Record import %s (case type %s): %d imported, %d already imported, %d not moved.',
					(string)$answer['key'],
					(string)$answer['caseType'],
					(int)$answer['imported'],
					(int)$answer['alreadyImported'],
					(int)$answer['unmigrated']
				)
			);
			foreach ((array)$answer['failed'] as $failed) {
				$output->warning(
					sprintf('Record %s was not moved: %s', (string)($failed['requestId'] ?? ''), (string)($failed['reason'] ?? ''))
				);
			}
		}
	}//end run()
}//end class
