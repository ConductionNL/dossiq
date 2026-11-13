<?php

/**
 * Dossiq DeadlinePauseService.
 *
 * AWB 4:5 / 4:15 hersteltermijn pause + resume on a TermijnInstance.
 * Pausing extends einddatumActueel by the requested duration in days and
 * flips status to gepauzeerd. Resuming after an aanvulling consumes the
 * elapsed pause days and re-extends einddatumActueel by the *unconsumed*
 * pause days only.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Pause\ChaseSchedule;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\Term\DayCount;
use RuntimeException;

/**
 * AWB 4:5 / 4:15 pause + resume on a TermijnInstance.
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */
class DeadlinePauseService {
	/**
	 * What a resume clears besides the end date. The reason and its counters
	 * go with the pause. A resumed term that still read "chased twice" would
	 * put a reminder count beside a case nobody is waiting on any more, and the
	 * engine cannot cancel the helper timer individually: clearing the reason
	 * here is what makes the listener's re-read decide against a late rung.
	 *
	 * @var array<string, mixed>
	 */
	private const RESUME_CLEARS = [
		'status' => 'lopend',
		'pauseDeadline' => null,
		'pauseTimerId' => null,
		'pauseReason' => '',
		'pauseWaitingOn' => '',
		'chasesSent' => 0,
		'lastChasedAt' => null,
		'chaseEscalatedAt' => null,
	];

	/**
	 * Why a term whose pause ran out resumed, on the timer and on the event.
	 *
	 * @var string
	 */
	public const EXPIRED_RESUME_REASON = 'Pauzetermijn verlopen zonder antwoord; termijn hervat';

	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService TermijnService.
	 * @param TermijnTimerService|null $timerService Engine timer mapping and the
	 *        working-calendar bridge (optional while the engine rolls out).
	 * @param TermDeclarationReader|null $declarations What the case type declares about
	 *        suspending. Awb 4:5 bounds the pause as well as the extension, and
	 *        `caseType.maxSuspensionDays` is where the bound is written. Optional for
	 *        the same reason the extension service's is: a caller that builds this by
	 *        hand keeps working, and simply gets today's unbounded behaviour.
	 * @param PauseReasonReader|null $reasons The reasons the case type allows a term to
	 *        be suspended for. Optional for the same reason: a case type that declares
	 *        none, and a caller that names none, get the behaviour that was here before,
	 *        which is a rationale and no chasing.
	 * @param ChaseSchedule|null $schedule Turns a reason into the rung offsets the engine
	 *        fires the reminders on. Optional beside the reader it belongs to.
	 * @param DayCount $days Counts the days between two moments.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?TermDeclarationReader $declarations = null,
		private readonly ?PauseReasonReader $reasons = null,
		private readonly ?ChaseSchedule $schedule = null,
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * Register a pauze on a TermijnInstance.
	 *
	 * Extends einddatumActueel by `duurDagen`, sets status=gepauzeerd,
	 * records a `pauze` event with dagenImpact=+duurDagen, and stores
	 * the pause deadline for the daily scan to watch.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param int $durationDays Pause days requested.
	 * @param string $rationale Reason.
	 * @param string $documentLink Document link (e.g. hersteltermijnbrief).
	 * @param string $pauseReason The key of the declared reason this pause is registered
	 *        under. The rule; `$rationale` stays the note beside it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When instance missing or duurDagen <= 0.
	 * @throws RefusedException When the case type forbids the suspension, forbids it this
	 *         long, or does not declare the reason named.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function registerPauze(
		string $termInstanceId,
		int $durationDays,
		string $rationale,
		string $documentLink = '',
		string $pauseReason = '',
	): array {
		if ($durationDays <= 0) {
			throw new RuntimeException('Pause duration must be positive (AWB 4:5)');
		}

		$run = new PauseRegistrationRun(termInstanceId: $termInstanceId, instance: $this->pausableInstance(termInstanceId: $termInstanceId));
		$run->durationDays = $durationDays;
		$run->rationale = $rationale;
		$run->documentLink = $documentLink;
		$this->assertSuspensionAllowed(instance: $run->instance, durationDays: $durationDays);
		$run->reason = $this->resolveReason(instance: $run->instance, key: $pauseReason);
		$run->now = new DateTimeImmutable();

		$patch = $this->suspendOnTheEngine(run: $run, patch: $this->pausePatch(run: $run));
		$updated = $this->termService->updateTermijnInstance($termInstanceId, $patch);
		$this->recordPauseEvent(run: $run);

		return $updated ?? $run->instance;
	}//end registerPauze()

	/**
	 * The instance a pause can be registered on.
	 *
	 * @param string $termInstanceId Instance id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the instance is missing or already paused.
	 */
	private function pausableInstance(string $termInstanceId): array {
		$instance = $this->termService->getTermijnInstance($termInstanceId);
		if ($instance === null) {
			throw new RuntimeException('TermijnInstance not found: ' . $termInstanceId);
		}

		if (($instance['status'] ?? '') === 'paused') {
			throw new RuntimeException('TermijnInstance already paused: ' . $termInstanceId);
		}

		return $instance;
	}//end pausableInstance()

	/**
	 * The end date the pause credits: the current end plus the pause days,
	 * landed on the administered calendar.
	 *
	 * REQ-TOT-002 keeps the arithmetic here, as case data. What moves is
	 * only the day it lands on: `endDateCurrent` is the date a handler is
	 * judged on, so Algemene termijnenwet art. 1 applies to it and the
	 * administered calendar decides, not this service.
	 *
	 * @param PauseRegistrationRun $run The pause being registered.
	 *
	 * @return string The new end date (`Y-m-d`).
	 */
	private function creditedEnd(PauseRegistrationRun $run): string {
		$current = new DateTimeImmutable((string)($run->instance['endDateCurrent'] ?? $run->now->format('Y-m-d')));
		$credited = $current->modify('+' . $run->durationDays . ' days');

		return ($this->timerService?->rollTermEndFor(date: $credited) ?? $credited)->format('Y-m-d');
	}//end creditedEnd()

	/**
	 * The patch that pauses the instance.
	 *
	 * The chase counters start at zero on every pause, not only on the first
	 * one. A case paused, answered and paused again gets its own budget: a
	 * counter carried over from the pause before would spend this pause's
	 * reminders on silence that already ended.
	 *
	 * @param PauseRegistrationRun $run The pause being registered.
	 *
	 * @return array<string, mixed>
	 */
	private function pausePatch(PauseRegistrationRun $run): array {
		$newEnd = $this->creditedEnd(run: $run);
		$run->pauseEnd = $run->now->modify('+' . $run->durationDays . ' days')->format('Y-m-d');

		return [
			'endDateCurrent' => $newEnd,
			'status' => 'paused',
			'pauseDeadline' => $run->pauseEnd,
			'pauzeStartDatum' => $run->now->format('Y-m-d'),
			'pauzeDuurDagen' => $run->durationDays,
			'pauseReason' => (string)($run->reason['key'] ?? ''),
			'pauseWaitingOn' => (string)($run->reason['waitingOn'] ?? ''),
			'chasesSent' => 0,
			'lastChasedAt' => null,
			'chaseEscalatedAt' => null,
		];
	}//end pausePatch()

	/**
	 * Opschorting maps onto the engine: suspend the beslistermijn timer (it
	 * banks the consumed budget, AWB 4:5) and arm the advisory hersteltermijn
	 * helper that replaces the scan's pause-expiry watch.
	 *
	 * @param PauseRegistrationRun $run   The pause being registered.
	 * @param array<string, mixed> $patch The patch that pauses the instance.
	 *
	 * @return array<string, mixed> The patch, with the helper's timer id when one was armed.
	 */
	private function suspendOnTheEngine(PauseRegistrationRun $run, array $patch): array {
		if ($this->timerService === null) {
			return $patch;
		}

		$this->timerService->suspendBeslistermijn(
			instance: $run->instance,
			reason: $run->rationale,
			until: new DateTimeImmutable($run->pauseEnd)
		);
		$pauseTimerId = $this->timerService->armHersteltermijn(
			instance: $run->instance,
			durationDays: $run->durationDays,
			chaseOffsets: $this->chaseOffsetsFor(reason: $run->reason, durationDays: $run->durationDays)
		);
		if ($pauseTimerId !== null) {
			$patch['pauseTimerId'] = $pauseTimerId;
		}

		return $patch;
	}//end suspendOnTheEngine()

	/**
	 * Record the `pause` event with the days it added.
	 *
	 * @param PauseRegistrationRun $run The pause being registered.
	 *
	 * @return void
	 */
	private function recordPauseEvent(PauseRegistrationRun $run): void {
		$this->termService->recordEvent(
			termInstanceId: $run->termInstanceId,
			type: 'pause',
			basis: 'AWB 4:5',
			rationale: $run->rationale,
			daysImpact: $run->durationDays,
			moment: $run->now,
			documentLink: $run->documentLink,
		);
	}//end recordPauseEvent()

	/**
	 * The pause reasons the case's case type declares, as a picker offers them,
	 * each with the days it usually gives when it declares them.
	 *
	 * @param string $caseId Case UUID
	 *
	 * @return array<int, array{key: string, name: string, legalBasis: string, defaultDays?: int}> Empty when none are declared.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function offeredReasons(string $caseId): array {
		if ($this->reasons === null) {
			return [];
		}

		return array_map(
			static function (array $reason): array {
				$offered = [
					'key' => (string)$reason['key'],
					'name' => (string)$reason['name'],
					'legalBasis' => (string)$reason['legalBasis'],
				];
				if (is_int($reason['defaultDays'] ?? null) === true) {
					$offered['defaultDays'] = $reason['defaultDays'];
				}

				return $offered;
			},
			$this->reasons->forCase(caseId: $caseId)
		);
	}//end offeredReasons()

	/**
	 * The rationale a suspension from the case page is registered with.
	 *
	 * Without a pause reason, the handler's text is the rationale and must not
	 * be empty. With one, it must be a reason the case type declares, and its
	 * name stands in when no text was given.
	 *
	 * @param string $caseId Case UUID
	 * @param string $reason The handler's text
	 * @param string $pauseReason The picked reason key, or ''
	 *
	 * @return string The rationale.
	 *
	 * @throws RuntimeException `reason_required` without a reason, `pause_reason_not_declared`
	 *                          for a key the case type does not declare.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function suspendRationale(string $caseId, string $reason, string $pauseReason): string {
		if ($pauseReason === '') {
			if (trim($reason) === '') {
				throw new RuntimeException('reason_required');
			}

			return $reason;
		}

		foreach ($this->offeredReasons(caseId: $caseId) as $declared) {
			if ($declared['key'] !== $pauseReason) {
				continue;
			}

			if (trim($reason) === '') {
				return $declared['name'];
			}

			return $reason;
		}

		throw new RuntimeException('pause_reason_not_declared');
	}//end suspendRationale()

	/**
	 * Refuse a suspension the case type does not allow, or does not allow this
	 * long (REQ-TERM-066).
	 *
	 * Awb 4:5 bounds the pause as well as the extension, so a case type carries
	 * a maximum beside `suspensionAllowed`. The refusal names the maximum, per
	 * ADR-050: a handler who asked for sixty days needs to read that
	 * twenty-eight is the ceiling, not that the answer was no.
	 *
	 * @param array<string, mixed> $instance The instance being suspended.
	 * @param int $durationDays How many days were asked for.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the case type forbids it, or forbids it this long.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	private function assertSuspensionAllowed(array $instance, int $durationDays): void {
		if ($this->declarations === null) {
			return;
		}

		$declared = $this->declarations->forCase(caseId: (string)($instance['case'] ?? ''));

		if ($declared['suspensionAllowed'] === false) {
			throw new RefusedException(
				rule: 'suspension-not-allowed',
				sentence: 'This case type does not allow the term to be suspended.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$maximum = (int)$declared['maxSuspensionDays'];
		if ($maximum > 0 && $durationDays > $maximum) {
			throw new RefusedException(
				rule: 'suspension-beyond-declared-maximum',
				sentence: 'This case type allows a suspension of at most ' . $maximum
					. ' days, and you asked for ' . $durationDays . '.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertSuspensionAllowed()

	/**
	 * The declared reason this pause is registered under.
	 *
	 * A CASE TYPE THAT DECLARES NO REASONS TAKES ANY PAUSE, including one that
	 * names a reason. That is deliberate: the vocabulary is administered per
	 * case type and most types have not been administered yet, so refusing
	 * there would break every suspension on the day this shipped. What IS
	 * refused is a reason a case type declares reasons and does not declare
	 * this one: that is a handler picking from a stale list, and accepting it
	 * would store a key nothing resolves and chase nobody.
	 *
	 * @param array<string, mixed> $instance The instance being suspended.
	 * @param string $key The reason key the caller named.
	 *
	 * @return array<string, mixed> The normalised reason, empty when none is in force.
	 *
	 * @throws RefusedException When the case type declares reasons but not this one.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	private function resolveReason(array $instance, string $key): array {
		$wanted = trim($key);
		if ($this->reasons === null || $wanted === '') {
			return [];
		}

		$caseId = trim((string)($instance['case'] ?? ''));
		$declared = $this->reasons->forCase(caseId: $caseId);
		if ($declared === []) {
			return [];
		}

		foreach ($declared as $reason) {
			if ($reason['key'] === $wanted) {
				return $reason;
			}
		}//end foreach

		throw new RefusedException(
			rule: 'pause-reason-not-declared',
			sentence: 'This case type does not have a pause reason called ' . $wanted
				. '. Pick one of the reasons it declares.',
			status: RefusedException::STATUS_UNPROCESSABLE,
		);
	}//end resolveReason()

	/**
	 * The rung offsets the reminders for this pause fire on.
	 *
	 * @param array<string, mixed> $reason The normalised reason, possibly empty.
	 * @param int $durationDays The pause length.
	 *
	 * @return array<int, int> The offsets, empty when nothing is chased.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	private function chaseOffsetsFor(array $reason, int $durationDays): array {
		if ($this->schedule === null || $reason === []) {
			return [];
		}

		return $this->schedule->rungOffsets(reason: $reason, durationDays: $durationDays);
	}//end chaseOffsetsFor()

	/**
	 * Resume after pauze with the aanvulling-datum.
	 *
	 * Computes consumed vs. unconsumed pause days; adds only the
	 * unconsumed portion to einddatumActueel; sets status=lopend and
	 * records the `hervat` event.
	 *
	 * @param string $termInstanceId Instance id.
	 * @param DateTimeImmutable|null $aanvullingDatum When aanvulling received (default now).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When instance missing or not paused.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function resumeAfterPauze(string $termInstanceId, ?DateTimeImmutable $aanvullingDatum = null): array {
		$aanvullingDatum = ($aanvullingDatum ?? new DateTimeImmutable());
		$instance = $this->pausedInstance(termInstanceId: $termInstanceId);
		$unused = $this->unusedPauseDays(instance: $instance, received: $aanvullingDatum);
		$current = new DateTimeImmutable((string)($instance['endDateCurrent'] ?? $aanvullingDatum->format('Y-m-d')));
		$newEnd = $this->resumedEnd(current: $current, unused: $unused);

		// Resume the engine timer: it re-projects the fire moment from the
		// unconsumed remainder (AWB 4:15), landing on the same date the
		// case-data arithmetic computes. The hersteltermijn helper
		// cannot be cancelled individually (engine gap, see design D-2);
		// the fired-listener's still-paused guard drops its late fire.
		$this->timerService?->resumeBeslistermijn(
			instance: $instance,
			reason: 'Aanvulling ontvangen; termijn hervat'
		);
		$updated = $this->termService->updateTermijnInstance($termInstanceId, (['endDateCurrent' => $newEnd] + self::RESUME_CLEARS));

		$this->termService->recordEvent(
			termInstanceId: $termInstanceId,
			type: 'hervat',
			basis: 'AWB 4:15',
			rationale: 'Aanvulling ontvangen; termijn hervat',
			daysImpact: (-1 * $unused),
			moment: $aanvullingDatum,
		);

		return $updated ?? $instance;
	}//end resumeAfterPauze()

	/**
	 * Resume a term whose pause ran out without an answer (REQ-TERM-080).
	 *
	 * Every pause day was used, so the end date the pause pushed out stands:
	 * nothing is pulled back, and the administered calendar still decides the
	 * day it lands on. The engine timer resumes, the pause is cleared exactly
	 * as a handler's resume clears it, and a `hervat` event records that the
	 * term resumed because the pause ended. The event carries the notice, so
	 * the notification rule on the event schema tells the handler the case and
	 * the new end date.
	 *
	 * @param string                 $termInstanceId Instance id.
	 * @param array<string, string>  $notice         Who to tell: `notify` (the
	 *        handler's user id) and `caseTitle`. The new end date is added here.
	 * @param DateTimeImmutable|null $moment         When the pause ran out (default now).
	 *
	 * @return array<string, mixed> The resumed instance.
	 *
	 * @throws RuntimeException When the instance is missing or no longer paused.
	 *
	 * @spec openspec/changes/expired-pause-resumes-the-term/tasks.md
	 */
	public function resumeAfterExpiry(string $termInstanceId, array $notice = [], ?DateTimeImmutable $moment = null): array {
		$moment = ($moment ?? new DateTimeImmutable());
		$instance = $this->pausedInstance(termInstanceId: $termInstanceId);
		$current = new DateTimeImmutable((string)($instance['endDateCurrent'] ?? $moment->format('Y-m-d')));
		$newEnd = $this->resumedEnd(current: $current, unused: 0);

		$this->timerService?->resumeBeslistermijn(instance: $instance, reason: self::EXPIRED_RESUME_REASON);
		$updated = $this->termService->updateTermijnInstance($termInstanceId, (['endDateCurrent' => $newEnd] + self::RESUME_CLEARS));

		$this->termService->recordEvent(
			termInstanceId: $termInstanceId,
			type: 'hervat',
			basis: 'AWB 4:15',
			rationale: self::EXPIRED_RESUME_REASON,
			daysImpact: 0,
			moment: $moment,
			notice: (['endDate' => $newEnd] + $notice),
		);

		return $updated ?? $instance;
	}//end resumeAfterExpiry()

	/**
	 * The instance a resume applies to.
	 *
	 * @param string $termInstanceId Instance id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the instance is missing or not paused.
	 */
	private function pausedInstance(string $termInstanceId): array {
		$instance = $this->termService->getTermijnInstance($termInstanceId);
		if ($instance === null) {
			throw new RuntimeException('TermijnInstance not found: ' . $termInstanceId);
		}

		if (($instance['status'] ?? '') !== 'paused') {
			throw new RuntimeException('TermijnInstance not in gepauzeerd state: ' . $termInstanceId);
		}

		return $instance;
	}//end pausedInstance()

	/**
	 * The pause days not used when the aanvulling arrived: the requested days
	 * minus the days actually used, capped at the requested duration.
	 *
	 * @param array<string, mixed> $instance The paused instance.
	 * @param DateTimeImmutable    $received When the aanvulling arrived.
	 *
	 * @return int
	 */
	private function unusedPauseDays(array $instance, DateTimeImmutable $received): int {
		$pauzeStart = new DateTimeImmutable((string)($instance['pauzeStartDatum'] ?? $received->format('Y-m-d')));
		$durationDays = (int)($instance['pauzeDuurDagen'] ?? 0);
		$diff = $this->days->between(from: $pauzeStart, to: $received);
		$consumed = max(0, min($durationDays, $diff));

		return $durationDays - $consumed;
	}//end unusedPauseDays()

	/**
	 * Pull back the unused portion of the end, then let the administered
	 * calendar decide the day it lands on (Awt art. 1).
	 *
	 * @param DateTimeImmutable $current The current end.
	 * @param int               $unused  The unused pause days.
	 *
	 * @return string The resumed end (`Y-m-d`).
	 */
	private function resumedEnd(DateTimeImmutable $current, int $unused): string {
		$remaining = $current->modify('-' . $unused . ' days');

		return ($this->timerService?->rollTermEndFor(date: $remaining) ?? $remaining)->format('Y-m-d');
	}//end resumedEnd()
}//end class
