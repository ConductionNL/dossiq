<?php

/**
 * Dossiq Term Rung Actions.
 *
 * What one rung of a known term timer does, once `TermijnTimerFiredListener`
 * has told the fire is a dossiq term's and found its TermijnInstance. A
 * beslistermijn rung does the threshold bookkeeping through
 * {@see DeadlineEscalationServiceInterface::notifyThreshold()} and the breach
 * rung flips the instance to `exceeded`; a hersteltermijn rung sends a due
 * reminder, or, when the pause ran out, records `pause-expired`, marks the
 * request run out and resumes the term.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\Dossiq\Kernel\Contracts\DeadlineEscalationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnTimerServiceInterface;
use OCA\Dossiq\Listener\TermijnRungFire;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use Psr\Log\LoggerInterface;

/**
 * Maps a rung of a known term onto the AWB termijn domain actions.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class TermRungActions {

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
	 * @param TermPauseFollowUp $followUp The optional collaborators of a suspended term: the
	 *        request behind it, its reminders, its resume and the case it belongs to.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly TermijnServiceInterface $termService,
		private readonly DeadlineEscalationServiceInterface $escalationService,
		private readonly LoggerInterface $logger,
		private readonly TermPauseFollowUp $followUp,
	) {
	}//end __construct()

	/**
	 * The metadata of a fire from a dossiq term timer, or null for any other timer.
	 *
	 * @param FlowTimerFiredEvent $event The fire.
	 *
	 * @return array<string, mixed>|null The timer metadata.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function termMetadata(FlowTimerFiredEvent $event): ?array {
		$timer = $event->getTimer();
		$metadata = ($timer->getMetadata() ?? []);
		if ((string)$timer->getAppId() !== 'dossiq'
			|| (string)($metadata['source'] ?? '') !== TermijnTimerServiceInterface::METADATA_SOURCE
		) {
			return null;
		}

		return $metadata;
	}//end termMetadata()

	/**
	 * Route a rung of a known term by the kind of term its timer carries.
	 *
	 * @param string               $rungKey    The rung's stable key, `trigger:offset:unit`.
	 * @param array<string, mixed> $instance   The TermijnInstance row.
	 * @param string               $instanceId Its id.
	 * @param array<string, mixed> $metadata   The timer metadata.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function route(string $rungKey, array $instance, string $instanceId, array $metadata): void {
		$fire = new TermijnRungFire(rungKey: $rungKey, instance: $instance, instanceId: $instanceId);
		$kind = (string)($metadata['kind'] ?? '');
		if ($kind === TermijnTimerServiceInterface::KIND_HERSTELTERMIJN) {
			$this->handleHersteltermijnRung(fire: $fire);
			return;
		}

		$this->handleBeslistermijnRung(fire: $fire);
	}//end route()

	/**
	 * A hersteltermijn rung: a reminder inside the term, or the term running out.
	 *
	 * @param TermijnRungFire $fire The rung's key, the TermijnInstance row and its id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function handleChase(array $instance): void {
		$this->followUp->chases?->chaseIfDue(instance: $instance);
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function expireRequest(string $caseId, string $instanceId): void {
		if ($this->followUp->aanvullingen === null || $caseId === '') {
			return;
		}

		try {
			$this->followUp->aanvullingen->expireIfRunOut(caseId: $caseId);
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function resumeExpired(string $caseId, string $instanceId): void {
		if ($this->followUp->pauses === null) {
			return;
		}

		try {
			$this->followUp->pauses->resumeAfterExpiry(termInstanceId: $instanceId, notice: $this->noticeFor(caseId: $caseId));
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function noticeFor(string $caseId): array {
		$case = null;
		if ($this->followUp->cases !== null && $caseId !== '') {
			$case = $this->followUp->cases->fetchCaseById(caseUuid: $caseId);
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
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
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
