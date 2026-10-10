<?php

/**
 * Arm the milestone stall timers of the cases already waiting.
 *
 * BottleneckDetectionJob retires in the release that ships the milestone
 * timers, so a case waiting on a milestone before the upgrade has no timer
 * until this step arms one. Arming supersedes, so the step is safe on every
 * upgrade. A case already stalled is told here, once.
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

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\Milestone\MilestoneStallActs;
use OCA\Dossiq\Service\Milestone\MilestoneStallTimer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs the milestone timer of every case once.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class ArmMilestoneStallTimers implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * Build the step.
	 *
	 * @param SettingsService  $settingsService Reaches OpenRegister and the case schema.
	 * @param MilestoneStallTimer $timer           Arms each case's timer.
	 * @param MilestoneStallActs  $acts            Tells the assignees of cases already stalled.
	 * @param LoggerInterface  $logger          Logs the counts.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly MilestoneStallTimer $timer,
		private readonly MilestoneStallActs $acts,
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
		return 'Arm OpenRegister engine timers for Dossiq milestone stalls';
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
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue('case_schema');
		if ($this->settingsService->isOpenRegisterAvailable() === false || $objectService === null || $register === '' || $schema === '') {
			$output->warning('OpenRegister or the case schema is not available. Skipping milestone stall timer migration.');
			return;
		}

		$counts = ['armed' => 0, 'stalled' => 0, 'failed' => 0];

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $register, $schema, &$counts): void {
				try {
					$cases = $this->searchObjectsAsArrays(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						filters: ['_limit' => 1000],
					);
				} catch (Throwable $e) {
					$this->logger->error('Dossiq milestone stall timer migration: the cases could not be read', ['error' => $e->getMessage()]);
					$cases = [];
				}

				foreach ($cases as $case) {
					$this->syncOne(case: $case, counts: $counts);
				}
			}
		);

		$this->logger->info('Dossiq milestone stall timer migration complete', $counts);
		$output->info(
			sprintf('Milestone stall timer migration: %d armed, %d stalled and told, %d failed.', $counts['armed'], $counts['stalled'], $counts['failed'])
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
		if ($outcome === MilestoneStallTimer::ARMED) {
			$counts['armed']++;
			return;
		}

		if ($outcome === MilestoneStallTimer::DUE) {
			$this->acts->notifyIfStalled(case: $case);
			$counts['stalled']++;
			return;
		}

		if ($outcome === MilestoneStallTimer::UNAVAILABLE) {
			$counts['failed']++;
		}
	}//end syncOne()
}//end class
