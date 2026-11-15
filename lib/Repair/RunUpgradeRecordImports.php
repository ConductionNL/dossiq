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

use OCA\Dossiq\Kernel\Contracts\CaseRecordImportInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
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
	 * @param CaseRecordImportInterface $import   The generic record import.
	 * @param SettingsServiceInterface  $settings Whether OpenRegister is available.
	 */
	public function __construct(
		private readonly CaseRecordImportInterface $import,
		private readonly SettingsServiceInterface $settings,
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

		$this->reportAnswers(output: $output, answers: $answers);
	}//end run()

	/**
	 * Report every import's answer, or that no case type imports on upgrade.
	 *
	 * @param IOutput $output Where the step reports.
	 * @param array<int, array<string, mixed>> $answers The import answers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-declared-imports-run-on-upgrade-req-cri-007
	 */
	private function reportAnswers(IOutput $output, array $answers): void {
		if ($answers === []) {
			$output->info('No case type imports records on upgrade.');
			return;
		}

		foreach ($answers as $answer) {
			$this->reportAnswer(output: $output, answer: $answer);
		}
	}//end reportAnswers()

	/**
	 * Report one import's counts and each record it did not move.
	 *
	 * @param IOutput $output Where the step reports.
	 * @param array<string, mixed> $answer The import answer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-declared-imports-run-on-upgrade-req-cri-007
	 */
	private function reportAnswer(IOutput $output, array $answer): void {
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
		$this->reportFailures(output: $output, failures: (array)$answer['failed']);
	}//end reportAnswer()

	/**
	 * Report each record an import did not move, with why.
	 *
	 * @param IOutput $output Where the step reports.
	 * @param array<int, mixed> $failures The records not moved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-record-import/spec.md#requirement-declared-imports-run-on-upgrade-req-cri-007
	 */
	private function reportFailures(IOutput $output, array $failures): void {
		foreach ($failures as $failed) {
			$output->warning(
				sprintf('Record %s was not moved: %s', (string)($failed['requestId'] ?? ''), (string)($failed['reason'] ?? ''))
			);
		}
	}//end reportFailures()
}//end class
