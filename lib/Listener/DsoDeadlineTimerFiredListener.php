<?php

/**
 * Dossiq DSO decision term timer fired-listener.
 *
 * Consumes OpenRegister's `FlowTimerFiredEvent` for the timers
 * {@see DsoDeadlineTimer} arms: the warning and critical rungs notify the
 * assignee, the breach marks the case overdue. Told apart by the rung's
 * trigger and offset unit (`preBreach:<n>:businessDays`), and the two bands by
 * which offset is the larger. The case is read FRESH, and the fire writes as
 * the background service account, as DsoDeadlineJob did.
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

use OCA\Dossiq\Listener\Support\TimerFire;
use OCA\Dossiq\Service\Dso\DsoDeadlineActs;
use OCA\Dossiq\Service\Dso\DsoDeadlineTimer;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Turns a DSO term rung into the notification or the overdue marker.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class DsoDeadlineTimerFiredListener implements IEventListener {

	/**
	 * Build the listener.
	 *
	 * @param DsoDeadlineActs          $acts           Reads the case and acts on it.
	 * @param BackgroundServiceAccount $serviceAccount The identity a cron-driven fire writes as.
	 * @param LoggerInterface          $logger         Logs a failed fire.
	 */
	public function __construct(
		private readonly DsoDeadlineActs $acts,
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

		$metadata = TimerFire::metadataFrom(event: $event, source: DsoDeadlineTimer::METADATA_SOURCE);
		$caseId   = (string) ($metadata['caseId'] ?? '');
		if ($metadata === null || $caseId === '') {
			return;
		}

		$rungKey = (string) $event->getRungKey();
		$failure = TimerFire::runAsAccount(
			account: $this->serviceAccount,
			operation: fn () => $this->actOnCase(caseId: $caseId, event: $event)
		);
		if ($failure !== null) {
			$this->logger->error('Dossiq DSO: timer fire handling failed', ['case' => $caseId, 'rung' => $rungKey, 'error' => $failure->getMessage()]);
		}
	}//end handle()

	/**
	 * Read the case fresh and act on the fire when it still exists.
	 *
	 * @param string              $caseId The case.
	 * @param FlowTimerFiredEvent $event  The fire.
	 *
	 * @return void
	 */
	private function actOnCase(string $caseId, FlowTimerFiredEvent $event): void {
		$case = $this->acts->find(caseId: $caseId);
		if ($case === null) {
			return;
		}

		$this->act(case: $case, event: $event);
	}//end actOnCase()

	/**
	 * The domain act for one rung.
	 *
	 * The band is read off the rung's message identity, which the timer
	 * sets per rung, because the two preBreach rungs differ only in offset
	 * and the offsets are settings. The subjects are literals on purpose:
	 * NotifierTest finds every dispatched subject by scanning for them, and
	 * a constant would hide this sender from it.
	 *
	 * @param array<string, mixed> $case  The case, read fresh.
	 * @param FlowTimerFiredEvent  $event The fire: its rung key and its message identity.
	 *
	 * @return void
	 */
	private function act(array $case, FlowTimerFiredEvent $event): void {
		if (TimerFire::isBreach(event: $event) === true) {
			$this->acts->markOverdue(case: $case);
			return;
		}

		$message = (string) $event->getMessage();
		if ($message === DsoDeadlineTimer::MESSAGE_CRITICAL) {
			$this->acts->notify(case: $case, subject: 'dso_deadline_critical');
			return;
		}

		if ($message === DsoDeadlineTimer::MESSAGE_WARNING) {
			$this->acts->notify(case: $case, subject: 'dso_deadline_warning');
		}
	}//end act()
}//end class
