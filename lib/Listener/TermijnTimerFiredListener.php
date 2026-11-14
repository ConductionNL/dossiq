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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\AanvullingsverzoekResolutionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseObjectReaderInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlineEscalationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlinePauseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\PauseChaseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnTimerServiceInterface;
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The class over the limit is the background
 *   service account's refusal: a fire from cron writes as that account or not at all.
 */
class TermijnTimerFiredListener implements IEventListener {
	use SearchesObjects;

	/**
	 * Instance statuses on which no escalation or breach applies any more.
	 *
	 * @var array<int, string>
	 */
	private const TERMINAL_STATUSES = ['completed', 'withdrawn'];

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
	 */
	public function __construct(
		private readonly TermijnServiceInterface $termService,
		private readonly DeadlineEscalationServiceInterface $escalationService,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly ?AanvullingsverzoekResolutionServiceInterface $aanvullingen = null,
		private readonly ?PauseChaseServiceInterface $chases = null,
		private readonly ?DeadlinePauseServiceInterface $pauses = null,
		private readonly ?CaseObjectReaderInterface $cases = null,
	) {
	}//end __construct()

	/**
	 * Handle a timer fire.
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
	 */
	private function dispatch(FlowTimerFiredEvent $event): void {
		$metadata = $this->termijnMetadata(event: $event);
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

		$this->routeRung(
			fire: new TermijnRungFire(rungKey: (string)$event->getRungKey(), instance: $instance, instanceId: $instanceId),
			metadata: $metadata
		);
	}//end dispatch()

	/**
	 * The metadata of a fire from a dossiq term timer, or null for any other timer.
	 *
	 * @param FlowTimerFiredEvent $event The fire.
	 *
	 * @return array<string, mixed>|null The timer metadata.
	 */
	private function termijnMetadata(FlowTimerFiredEvent $event): ?array {
		$timer = $event->getTimer();
		$metadata = ($timer->getMetadata() ?? []);
		if ((string)$timer->getAppId() !== 'dossiq'
			|| (string)($metadata['source'] ?? '') !== TermijnTimerServiceInterface::METADATA_SOURCE
		) {
			return null;
		}

		return $metadata;
	}//end termijnMetadata()

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

	/**
	 * Route a rung by the kind of term its timer carries.
	 *
	 * @param TermijnRungFire      $fire     The rung's key, the TermijnInstance row and its id.
	 * @param array<string, mixed> $metadata The timer metadata.
	 *
	 * @return void
	 */
	private function routeRung(TermijnRungFire $fire, array $metadata): void {
		$kind = (string)($metadata['kind'] ?? '');
		if ($kind === TermijnTimerServiceInterface::KIND_HERSTELTERMIJN) {
			$this->handleHersteltermijnRung(fire: $fire);
			return;
		}

		$this->handleBeslistermijnRung(fire: $fire);
	}//end routeRung()

	/**
	 * A hersteltermijn rung: a reminder inside the term, or the term running out.
	 *
	 * @param TermijnRungFire $fire The rung's key, the TermijnInstance row and its id.
	 *
	 * @return void
	 */
	private function handleHersteltermijnRung(TermijnRungFire $fire): void {
		// Two kinds of rung live on the helper now. `slaBreached:0` is the
		// hersteltermijn running out; every `preBreach:<days>` rung is a
		// reminder due inside it. They are told apart by the trigger rather
		// than by the message, because the rung KEY is the one part of a
		// fire the engine guarantees and the listener already parses.
		if ($this->isChaseRung(rungKey: $fire->rungKey) === true) {
			$this->handleChase(instance: $fire->instance);
			return;
		}

		$this->handlePauseExpiry(instance: $fire->instance, instanceId: $fire->instanceId);
	}//end handleHersteltermijnRung()

	/**
	 * A beslistermijn rung: threshold bookkeeping, breach flip, accrual sync.
	 *
	 * @param TermijnRungFire $fire The rung's key, the TermijnInstance row and its id.
	 *
	 * @return void
	 */
	private function handleBeslistermijnRung(TermijnRungFire $fire): void {
		$instance = $fire->instance;
		$instanceId = $fire->instanceId;
		if (in_array((string)($instance['status'] ?? ''), self::TERMINAL_STATUSES, true) === true) {
			return;
		}

		$bucket = $this->bucketFromRungKey(rungKey: $fire->rungKey);
		if ($bucket === null) {
			return;
		}

		if ($bucket === 0 && (string)($instance['status'] ?? '') !== 'exceeded') {
			$this->termService->updateTermijnInstance($instanceId, ['status' => 'exceeded']);
			$this->termService->recordEvent(
				termInstanceId: $instanceId,
				type: 'exceeded',
				basis: 'AWB 4:13',
				rationale: 'Termijn overschreden zonder beschikking',
				daysImpact: 0,
			);
			$instance['status'] = 'exceeded';
		}

		// Re-read so notifyThreshold sees the freshest notificatiesVerstuurd.
		$latest = ($this->termService->getTermijnInstance($instanceId) ?? $instance);
		$this->escalationService->notifyThreshold($latest, $bucket);
	}//end handleBeslistermijnRung()

	/**
	 * Whether a rung on the hersteltermijn helper is one of the reminders.
	 *
	 * @param string $rungKey The rung's stable key, `trigger:offset:unit`.
	 *
	 * @return bool True for a reminder rung.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	private function isChaseRung(string $rungKey): bool {
		return (str_starts_with($rungKey, 'preBreach:') === true);
	}//end isChaseRung()

	/**
	 * A reminder rung fired on a suspended term.
	 *
	 * THE RUNG DOES NOT DECIDE, IT ONLY ASKS. It says a moment the engine was
	 * given has arrived; whether a reminder is due is answered against the
	 * count on the instance, re-read here. So a rung that fires after a resume,
	 * twice, or beside a daily sweep that already sent one, sends nothing.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	private function handleChase(array $instance): void {
		$this->chases?->chaseIfDue(instance: $instance);
	}//end handleChase()

	/**
	 * The hersteltermijn helper fired: the pause ran out without an
	 * aanvulling. The engine cannot cancel a single timer, so a helper
	 * outliving an on-time aanvulling fires anyway — the still-paused
	 * guard here is what drops it, and so a term the handler resumed
	 * earlier is neither resumed again nor told about (REQ-TERM-080).
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 * @param string $instanceId Its id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expired-pause-resumes-the-term/tasks.md
	 */
	private function handlePauseExpiry(array $instance, string $instanceId): void {
		if ((string)($instance['status'] ?? '') !== 'paused') {
			return;
		}

		$this->termService->recordEvent(
			termInstanceId: $instanceId,
			type: 'pause-expired',
			basis: 'AWB 4:5',
			rationale: 'Pauzetermijn verlopen zonder aanvulling',
			daysImpact: 0,
		);

		$caseId = trim((string)($instance['case'] ?? ''));
		$this->expireRequest(caseId: $caseId, instanceId: $instanceId);
		$this->resumeExpired(caseId: $caseId, instanceId: $instanceId);
	}//end handlePauseExpiry()

	/**
	 * Mark the request behind an expired pause as run out.
	 *
	 * The timer says the hersteltermijn ran out. The REQUEST is what the
	 * file has to show when an application is refused for incompleteness
	 * under Awb 4:5, so the day it ran out is recorded there too, as a
	 * state. Nothing is deleted and nothing is rewritten: the items and the
	 * dates stay exactly as they were sent, which is the whole point of
	 * keeping an unanswered request.
	 *
	 * A failure here must not poison the engine sweep, and must not undo
	 * the event before it: the term is correct either way, and the request
	 * can still be expired by hand.
	 *
	 * @param string $caseId The case the term belongs to.
	 * @param string $instanceId The term instance id.
	 *
	 * @return void
	 */
	private function expireRequest(string $caseId, string $instanceId): void {
		if ($this->aanvullingen === null || $caseId === '') {
			return;
		}

		try {
			$this->aanvullingen->expireIfRunOut(caseId: $caseId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq: the aanvullingsverzoek behind an expired pause could not be marked expired',
				['case' => $caseId, 'instance' => $instanceId, 'error' => $e->getMessage()]
			);
		}
	}//end expireRequest()

	/**
	 * Resume the term whose pause ran out, and tell the case's handler.
	 *
	 * The handler is the case's assignee at the moment the pause ran out. A
	 * case without one, or one that cannot be read, still resumes: the term
	 * is the statutory part, the notice is the courtesy. A failure is logged
	 * and must not poison the engine sweep.
	 *
	 * @param string $caseId The case the term belongs to.
	 * @param string $instanceId The term instance id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expired-pause-resumes-the-term/tasks.md
	 */
	private function resumeExpired(string $caseId, string $instanceId): void {
		if ($this->pauses === null) {
			return;
		}

		try {
			$this->pauses->resumeAfterExpiry(termInstanceId: $instanceId, notice: $this->noticeFor(caseId: $caseId));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq termijn: a term whose pause ran out could not be resumed',
				['case' => $caseId, 'instance' => $instanceId, 'error' => $e->getMessage()]
			);
		}
	}//end resumeExpired()

	/**
	 * Who the resume is told to, and the case it names.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, string> `notify` and `caseTitle`, empty when the case cannot be read.
	 *
	 * @spec openspec/changes/expired-pause-resumes-the-term/tasks.md
	 */
	private function noticeFor(string $caseId): array {
		$case = null;
		if ($this->cases !== null && $caseId !== '') {
			$case = $this->cases->fetchCaseById(caseUuid: $caseId);
		}

		if ($case === null) {
			return [];
		}

		$title = trim((string)($case['title'] ?? ''));
		if ($title === '') {
			$title = trim((string)($case['identifier'] ?? ''));
		}

		return ['notify' => trim((string)($case['assignee'] ?? '')), 'caseTitle' => $title];
	}//end noticeFor()

	/**
	 * Map an engine rung key onto the domain threshold bucket.
	 *
	 * Keys follow `trigger:offset:unit` (`preBreach:14:calendarDays`) or
	 * `slaBreached:0`; the offset IS the bucket. Unknown shapes map to
	 * null and are ignored rather than guessed.
	 *
	 * @param string $rungKey The rung's stable key.
	 *
	 * @return int|null The bucket (14/7/2/0), or null.
	 */
	private function bucketFromRungKey(string $rungKey): ?int {
		$parts = explode(':', $rungKey);
		$trigger = (string)($parts[0] ?? '');
		if (isset($parts[1]) === false || is_numeric($parts[1]) === false) {
			return null;
		}

		if ($trigger === 'slaBreached') {
			return 0;
		}

		if ($trigger === 'preBreach') {
			return (int)$parts[1];
		}

		return null;
	}//end bucketFromRungKey()
}//end class
