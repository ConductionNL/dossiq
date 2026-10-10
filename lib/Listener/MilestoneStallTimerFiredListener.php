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

use OCA\Dossiq\Service\Milestone\MilestoneStallActs;
use OCA\Dossiq\Service\Milestone\MilestoneStallTimer;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

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
	 * @param MilestoneStallActs       $acts           Reads the case and tells the assignee.
	 * @param BackgroundServiceAccount $serviceAccount The identity a cron-driven fire writes as.
	 * @param LoggerInterface          $logger         Logs a failed fire.
	 */
	public function __construct(
		private readonly MilestoneStallActs $acts,
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

		$timer    = $event->getTimer();
		$metadata = ($timer->getMetadata() ?? []);
		$caseId   = (string) ($metadata['caseId'] ?? '');
		if ((string) $timer->getAppId() !== 'dossiq'
			|| (string) ($metadata['source'] ?? '') !== MilestoneStallTimer::METADATA_SOURCE
			|| $caseId === ''
			|| str_starts_with((string) $event->getRungKey(), 'slaBreached:') === false
		) {
			return;
		}

		$identifier = (string) ($metadata['milestoneIdentifier'] ?? '');

		try {
			$this->serviceAccount->runAsWhenNobodyIsSignedIn(
				operation: function () use ($caseId, $identifier): void {
					$case = $this->acts->find(caseId: $caseId);
					if ($case !== null) {
						$this->acts->notifyIfStalled(case: $case, identifier: $identifier);
					}
				}
			);
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged and told to the admins. Nothing was written.
			return;
		} catch (Throwable $e) {
			$this->logger->error('Dossiq milestone: timer fire handling failed', ['case' => $caseId, 'error' => $e->getMessage()]);
		}
	}//end handle()
}//end class
