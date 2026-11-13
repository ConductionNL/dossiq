<?php

/**
 * Arm the engine timers of DSO cases whose term already runs.
 *
 * DsoDeadlineJob retires in the release that ships the DSO term timers, so a
 * DSO case open before the upgrade has no timer until this step arms one.
 * Arming supersedes, so the step is safe on every upgrade. A case whose
 * deadline passed while nobody watched is marked overdue here.
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\DsoDeadlineTimerInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Repair\Support\CaseTimerSweep;
use OCA\Dossiq\Repair\Support\CaseTimerSweeper;
use OCA\Dossiq\Service\Dso\DsoDeadlineActs;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Syncs the timer of every open DSO case once.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class ArmDsoDeadlineTimers implements IRepairStep {
	/**
	 * Build the step.
	 *
	 * @param SettingsServiceInterface  $settingsService Reaches OpenRegister and the case schema.
	 * @param DsoDeadlineTimerInterface $timer           Arms each case's timer.
	 * @param DsoDeadlineActs  $acts            Marks the cases already overdue.
	 * @param LoggerInterface  $logger          Logs the counts.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly DsoDeadlineTimerInterface $timer,
		private readonly DsoDeadlineActs $acts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function getName(): string {
		return 'Arm OpenRegister engine timers for running Dossiq DSO terms';
	}//end getName()

	/**
	 * Run the step.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function run(IOutput $output): void {
		$counts = ['armed' => 0, 'overdue' => 0, 'failed' => 0];
		$sweep = new CaseTimerSweep();
		$sweep->migration = 'DSO term timer';
		$sweep->filters = ['dsoStatus' => DsoDeadlineTimerInterface::OPEN_STATUSES, '_limit' => 1000];
		$sweep->each = function (array $case) use (&$counts): void {
			$this->syncOne(case: $case, counts: $counts);
		};
		if ((new CaseTimerSweeper(settingsService: $this->settingsService, logger: $this->logger))->sweep(sweep: $sweep) === false) {
			$output->warning('OpenRegister or the case schema is not available. Skipping DSO term timer migration.');
			return;
		}

		$this->logger->info('Dossiq DSO term timer migration complete', $counts);
		$output->info(
			sprintf('DSO term timer migration: %d armed, %d marked overdue, %d failed.', $counts['armed'], $counts['overdue'], $counts['failed'])
		);
	}//end run()

	/**
	 * Sync one case and count the outcome.
	 *
	 * @param array<string, mixed> $case   The case.
	 * @param array<string, int>   $counts The running counts.
	 *
	 * @return void
	 */
	private function syncOne(array $case, array &$counts): void {
		$outcome = $this->timer->sync(case: $case);
		if ($outcome === DsoDeadlineTimerInterface::ARMED) {
			$counts['armed']++;
			return;
		}

		if ($outcome === DsoDeadlineTimerInterface::DUE) {
			$this->acts->markOverdue(case: $case);
			$counts['overdue']++;
			return;
		}

		if ($outcome === DsoDeadlineTimerInterface::UNAVAILABLE) {
			$counts['failed']++;
		}
	}//end syncOne()
}//end class
