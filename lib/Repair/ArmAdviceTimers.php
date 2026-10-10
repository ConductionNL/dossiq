<?php

/**
 * Arm the engine timers of advice requests already waiting.
 *
 * AdviceDeadlineJob retires in the release that ships the advice timers, so
 * a request open before the upgrade has no timer until this step arms one.
 * Arming supersedes ({@see AdviceTimer::sync()} cancels before it arms), so
 * the step is safe to run on every upgrade: a second run arms one timer per
 * request, not two. A request whose deadline passed while nobody watched is
 * expired here, as the job would have on its next run.
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
use OCA\Dossiq\Service\Advice\AdviceTimer;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Syncs the timer of every open advice request once.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class ArmAdviceTimers implements IRepairStep {
	use RunsUnderSystemIdentity;

	/**
	 * Build the step.
	 *
	 * @param SettingsService $settingsService Reports OpenRegister and its object service.
	 * @param AdviceService   $adviceService   Lists the open requests and expires overdue ones.
	 * @param AdviceTimer     $timer           Arms each request's timer.
	 * @param LoggerInterface $logger          Logs the counts.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly AdviceService $adviceService,
		private readonly AdviceTimer $timer,
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
		return 'Arm OpenRegister engine timers for open Dossiq advice requests';
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
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping advice timer migration.');
			return;
		}

		$counts = ['armed' => 0, 'expired' => 0, 'failed' => 0];

		// No session, so OpenRegister sees Anonymous: the read and the expiry
		// writes run under the system identity every other writing step uses.
		$this->withSystemIdentity(
			objectService: $this->settingsService->getObjectService(),
			work: function () use (&$counts): void {
				foreach ($this->adviceService->getOpenAdvice() as $advice) {
					$this->syncOne(advice: $advice, counts: $counts);
				}
			}
		);

		$this->logger->info('Dossiq advice timer migration complete', $counts);
		$output->info(
			sprintf(
				'Advice timer migration: %d armed, %d expired, %d failed.',
				$counts['armed'],
				$counts['expired'],
				$counts['failed']
			)
		);
	}//end run()

	/**
	 * Sync one request and count the outcome.
	 *
	 * @param array<string, mixed> $advice The request.
	 * @param array<string, int>   $counts The running counts.
	 *
	 * @return void
	 */
	private function syncOne(array $advice, array &$counts): void {
		$outcome = $this->timer->sync(advice: $advice);
		if ($outcome === AdviceTimer::ARMED) {
			$counts['armed']++;
			return;
		}

		if ($outcome === AdviceTimer::OVERDUE) {
			$this->adviceService->expireAdvice((string) ($advice['id'] ?? ($advice['uuid'] ?? '')));
			$counts['expired']++;
			return;
		}

		if ($outcome === AdviceTimer::UNAVAILABLE) {
			$counts['failed']++;
		}
	}//end syncOne()
}//end class
