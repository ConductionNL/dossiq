<?php

/**
 * Dossiq Termijn Timer Fired Listener.
 *
 * The domain side of the engine's clock: consumes OpenRegister
 * FlowTimerFiredEvent for dossiq termijn timers. PreBreach rungs route
 * through {@see DeadlineEscalationService::notifyThreshold()} so the
 * instance's `notificatiesVerstuurd` list stays the ONE dedup source
 * (which also absorbs catch-up fires after the migration repair step);
 * the breach rung flips the instance to `exceeded` and records the
 * event; the hersteltermijn helper's rung records `pause-expired` and
 * resumes the term only while the instance is still paused; and every
 * handled fire syncs the dwangsom accrual derivation.
 *
 * The listener never throws out of handle(): a domain failure is logged
 * and must not poison the engine sweep.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/archive/2026-11-21-termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\AanvullingsverzoekResolutionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlineEscalationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlinePauseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\PauseChaseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Listener\Support\TermPauseFollowUp;
use OCA\Dossiq\Listener\Support\TermRungActions;
use OCA\Dossiq\Listener\Support\TimerFire;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Maps engine rung fires onto the AWB termijn domain actions.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-11-21-termijnbewaking-op-engine-timers/tasks.md
 */
class TermijnTimerFiredListener implements IEventListener {
	use SearchesObjects;

	/**
	 * What a rung of a known term does: the breach, the reminder, the expired pause.
	 *
	 * @var TermRungActions
	 */
	private readonly TermRungActions $actions;

	/**
	 * Constructor.
	 *
	 * @param TermijnServiceInterface $termService Instance lookup, updates and events.
	 * @param DeadlineEscalationServiceInterface $escalationService Threshold bookkeeping and dispatch.
	 * @param LoggerInterface $logger Logger.
	 * @param BackgroundServiceAccount $serviceAccount Who writes when the fire comes
	 *        from cron with nobody signed in. OpenRegister's FlowTimerWorker is a
	 *        background job, and OpenRegister refuses a write from nobody.
	 * @param AanvullingsverzoekResolutionServiceInterface|null $aanvullingen The request
	 *        the hersteltermijn belonged to, so the day it runs out is recorded
	 *        on the request and not only on the timer. Optional, so an instance
	 *        without the schema fires exactly as it did before.
	 * @param PauseChaseServiceInterface|null $chases The reminders on a suspended term. The
	 *        rung only says a moment arrived; this decides whether a reminder is
	 *        actually due, against the count the instance carries. Optional, so a
	 *        pause with no declared reason fires exactly as it did before.
	 * @param DeadlinePauseServiceInterface|null $pauses Resumes a term whose pause ran out
	 *        (REQ-TERM-080). Optional, so a build without it records the expiry
	 *        and leaves the term paused, as it did before.
	 * @param CaseObjectReaderInterface|null $cases Reads the case behind the term, for the
	 *        handler the resume is told to and the title it names. Optional: without
	 *        it the term still resumes, and nobody is told.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly TermijnServiceInterface $termService,
		DeadlineEscalationServiceInterface $escalationService,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
		?AanvullingsverzoekResolutionServiceInterface $aanvullingen = null,
		?PauseChaseServiceInterface $chases = null,
		?DeadlinePauseServiceInterface $pauses = null,
		?CaseObjectReaderInterface $cases = null,
	) {
		$this->actions = new TermRungActions(
			termService: $termService,
			escalationService: $escalationService,
			logger: $logger,
			followUp: new TermPauseFollowUp(aanvullingen: $aanvullingen, chases: $chases, pauses: $pauses, cases: $cases)
		);
	}//end __construct()

	/**
	 * Handle a timer fire.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-21-termijnbewaking-op-engine-timers/tasks.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof FlowTimerFiredEvent) === false) {
			return;
		}

		// The fire comes from OpenRegister's FlowTimerWorker, a cron job
		// with nobody signed in, so the instance update and the event
		// were refused as Anonymous. A signed-in caller keeps writing as
		// themselves. Without a usable account it is already logged as an
		// error and told to the admins; nothing was written for this fire.
		$failure = TimerFire::runAsAccount(
			account: $this->serviceAccount,
			operation: fn () => $this->dispatch(event: $event)
		);
		if ($failure !== null) {
			$this->logger->error(
				'Dossiq termijn: timer fire handling failed',
				['error' => $failure->getMessage(), 'rung' => (string)$event->getRungKey()]
			);
		}
	}//end handle()

	/**
	 * Route one fire to its domain action.
	 *
	 * @param FlowTimerFiredEvent $event The fire.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function dispatch(FlowTimerFiredEvent $event): void {
		$metadata = $this->actions->termMetadata(event: $event);
		if ($metadata === null) {
			return;
		}

		$instanceId = (string)($metadata['termijnInstanceId'] ?? '');
		if ($instanceId === '' || $event->getKind() !== 'rung') {
			return;
		}

		$instance = $this->knownInstance(event: $event, instanceId: $instanceId);
		if ($instance === null) {
			return;
		}

		$this->actions->route(
			rungKey: (string)$event->getRungKey(),
			instance: $instance,
			instanceId: $instanceId,
			metadata: $metadata
		);
	}//end dispatch()

	/**
	 * The TermijnInstance a fire names, warning when it is unknown.
	 *
	 * @param FlowTimerFiredEvent $event The fire.
	 * @param string $instanceId The instance id.
	 *
	 * @return array<string, mixed>|null The TermijnInstance row, or null when unknown.
	 */
	private function knownInstance(FlowTimerFiredEvent $event, string $instanceId): ?array {
		$instance = $this->termService->getTermijnInstance($instanceId);
		if ($instance === null) {
			$this->logger->warning(
				'Dossiq termijn: timer fired for an unknown instance',
				['instance' => $instanceId, 'rung' => (string)$event->getRungKey()]
			);
		}

		return $instance;
	}//end knownInstance()
}//end class
