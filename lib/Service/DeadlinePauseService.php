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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Pause\ChaseSchedule;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use RuntimeException;

/**
 * AWB 4:5 / 4:15 pause + resume on a TermijnInstance.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
 */
class DeadlinePauseService {
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
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?TermDeclarationReader $declarations = null,
		private readonly ?PauseReasonReader $reasons = null,
		private readonly ?ChaseSchedule $schedule = null,
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
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
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

		$instance = $this->termService->getTermijnInstance($termInstanceId);
		if ($instance === null) {
			throw new RuntimeException('TermijnInstance not found: ' . $termInstanceId);
		}

		if (($instance['status'] ?? '') === 'paused') {
			throw new RuntimeException('TermijnInstance already paused: ' . $termInstanceId);
		}

		$this->assertSuspensionAllowed(instance: $instance, durationDays: $durationDays);
		$reason = $this->resolveReason(instance: $instance, key: $pauseReason);

		$now = new DateTimeImmutable();
		$current = new DateTimeImmutable((string)($instance['endDateCurrent'] ?? $now->format('Y-m-d')));

		// REQ-TOT-002 keeps the arithmetic here, as case data. What moves is
		// only the day it lands on: `endDateCurrent` is the date a handler is
		// judged on, so Algemene termijnenwet art. 1 applies to it and the
		// administered calendar decides, not this service.
		$credited = $current->modify('+' . $durationDays . ' days');
		$newEnd = ($this->timerService?->rollTermEndFor(date: $credited) ?? $credited)->format('Y-m-d');
		$pauseEnd = $now->modify('+' . $durationDays . ' days')->format('Y-m-d');

		// Opschorting maps onto the engine: suspend the beslistermijn timer
		// (it banks the consumed budget, AWB 4:5) and arm the advisory
		// hersteltermijn helper that replaces the scan's pause-expiry watch.
		// The chase counters start at zero on every pause, not only on the first
		// one. A case paused, answered and paused again gets its own budget: a
		// counter carried over from the pause before would spend this pause's
		// reminders on silence that already ended.
		$patch = [
			'endDateCurrent' => $newEnd,
			'status' => 'paused',
			'pauseDeadline' => $pauseEnd,
			'pauzeStartDatum' => $now->format('Y-m-d'),
			'pauzeDuurDagen' => $durationDays,
			'pauseReason' => (string)($reason['key'] ?? ''),
			'pauseWaitingOn' => (string)($reason['waitingOn'] ?? ''),
			'chasesSent' => 0,
			'lastChasedAt' => null,
			'chaseEscalatedAt' => null,
		];

		if ($this->timerService !== null) {
			$this->timerService->suspendBeslistermijn(
				instance: $instance,
				reason: $rationale,
				until: new DateTimeImmutable($pauseEnd)
			);
			$pauseTimerId = $this->timerService->armHersteltermijn(
				instance: $instance,
				durationDays: $durationDays,
				chaseOffsets: $this->chaseOffsetsFor(reason: $reason, durationDays: $durationDays)
			);
			if ($pauseTimerId !== null) {
				$patch['pauseTimerId'] = $pauseTimerId;
			}
		}

		$updated = $this->termService->updateTermijnInstance($termInstanceId, $patch);

		$this->termService->recordEvent(
			termInstanceId: $termInstanceId,
			type: 'pause',
			basis: 'AWB 4:5',
			rationale: $rationale,
			daysImpact: $durationDays,
			moment: $now,
			documentLink: $documentLink,
		);

		return $updated ?? $instance;
	}//end registerPauze()

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
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
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
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
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
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
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
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function resumeAfterPauze(string $termInstanceId, ?DateTimeImmutable $aanvullingDatum = null): array {
		$aanvullingDatum = ($aanvullingDatum ?? new DateTimeImmutable());

		$instance = $this->termService->getTermijnInstance($termInstanceId);
		if ($instance === null) {
			throw new RuntimeException('TermijnInstance not found: ' . $termInstanceId);
		}

		if (($instance['status'] ?? '') !== 'paused') {
			throw new RuntimeException('TermijnInstance not in gepauzeerd state: ' . $termInstanceId);
		}

		$pauzeStart = new DateTimeImmutable((string)($instance['pauzeStartDatum'] ?? $aanvullingDatum->format('Y-m-d')));
		$durationDays = (int)($instance['pauzeDuurDagen'] ?? 0);

		// Days actually used (cap at the requested duration).
		$diff = (int)$pauzeStart->diff($aanvullingDatum)->days;
		$consumed = max(0, min($durationDays, $diff));
		$unused = $durationDays - $consumed;

		// Pull back the unused portion of einddatumActueel, then let the
		// administered calendar decide the day it lands on (Awt art. 1).
		$current = new DateTimeImmutable((string)($instance['endDateCurrent'] ?? $aanvullingDatum->format('Y-m-d')));
		$remaining = $current->modify('-' . $unused . ' days');
		$newEnd = ($this->timerService?->rollTermEndFor(date: $remaining) ?? $remaining)->format('Y-m-d');

		// Resume the engine timer: it re-projects the fire moment from the
		// unconsumed remainder (AWB 4:15), landing on the same date the
		// case-data arithmetic below computes. The hersteltermijn helper
		// cannot be cancelled individually (engine gap, see design D-2);
		// the fired-listener's still-paused guard drops its late fire.
		$this->timerService?->resumeBeslistermijn(
			instance: $instance,
			reason: 'Aanvulling ontvangen; termijn hervat'
		);

		// The reason and its counters go with the pause. A resumed term that
		// still read "chased twice" would put a reminder count beside a case
		// nobody is waiting on any more, and the engine cannot cancel the
		// helper timer individually: clearing the reason here is what makes the
		// listener's re-read decide against a late rung.
		$updated = $this->termService->updateTermijnInstance(
			$termInstanceId,
			[
				'endDateCurrent' => $newEnd,
				'status' => 'lopend',
				'pauseDeadline' => null,
				'pauseTimerId' => null,
				'pauseReason' => '',
				'pauseWaitingOn' => '',
				'chasesSent' => 0,
				'lastChasedAt' => null,
				'chaseEscalatedAt' => null,
			]
		);

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
}//end class
