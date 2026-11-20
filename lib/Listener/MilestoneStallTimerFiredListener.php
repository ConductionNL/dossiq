<?php

/**
 * Dossiq milestone stall timer fired-listener.
 *
 * Consumes OpenRegister's `FlowTimerFiredEvent` for the timers
 * {@see MilestoneStallTimer} arms: on the breach it reads the case fresh and
 * tells the assignee, when the case still waits late on the milestone the
 * timer was armed for. Runs as the background service account.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\MilestoneStallActsInterface;
use OCA\Dossiq\Kernel\Contracts\MilestoneStallTimerInterface;
use OCA\Dossiq\Listener\Support\TimerFire;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Turns a milestone stall breach into the assignee's notification.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class MilestoneStallTimerFiredListener implements IEventListener {

	/**
	 * Build the listener.
	 *
	 * @param MilestoneStallActsInterface       $acts           Reads the case and tells the assignee.
	 * @param BackgroundServiceAccount $serviceAccount The identity a cron-driven fire writes as.
	 * @param LoggerInterface          $logger         Logs a failed fire.
	 */
	public function __construct(
		private readonly MilestoneStallActsInterface $acts,
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one fire.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof FlowTimerFiredEvent) === false) {
			return;
		}

		$metadata = TimerFire::metadataFrom(event: $event, source: MilestoneStallTimerInterface::METADATA_SOURCE);
		$caseId   = (string) ($metadata['caseId'] ?? '');
		if ($metadata === null || $caseId === '' || TimerFire::isBreach(event: $event) === false) {
			return;
		}

		$identifier = (string) ($metadata['milestoneIdentifier'] ?? '');
		$failure    = TimerFire::runAsAccount(
			account: $this->serviceAccount,
			operation: fn () => $this->notifyIfStalled(caseId: $caseId, identifier: $identifier)
		);
		if ($failure !== null) {
			$this->logger->error('Dossiq milestone: timer fire handling failed', ['case' => $caseId, 'error' => $failure->getMessage()]);
		}
	}//end handle()

	/**
	 * Tell the case's handler when the milestone is still stalled.
	 *
	 * @param string $caseId     The case.
	 * @param string $identifier The milestone.
	 *
	 * @return void
	 */
	private function notifyIfStalled(string $caseId, string $identifier): void {
		$case = $this->acts->find(caseId: $caseId);
		if ($case !== null) {
			$this->acts->notifyIfStalled(case: $case, identifier: $identifier);
		}
	}//end notifyIfStalled()
}//end class
