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
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?TermDeclarationReader $declarations = null,
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
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When instance missing or duurDagen <= 0.
	 * @throws RefusedException When the case type forbids the suspension, or forbids it this long.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-03-pause-extension/tasks.md
	 */
	public function registerPauze(
		string $termInstanceId,
		int $durationDays,
		string $rationale,
		string $documentLink = '',
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
		$patch = [
			'endDateCurrent' => $newEnd,
			'status' => 'paused',
			'pauseDeadline' => $pauseEnd,
			'pauzeStartDatum' => $now->format('Y-m-d'),
			'pauzeDuurDagen' => $durationDays,
		];

		if ($this->timerService !== null) {
			$this->timerService->suspendBeslistermijn(
				instance: $instance,
				reason: $rationale,
				until: new DateTimeImmutable($pauseEnd)
			);
			$pauseTimerId = $this->timerService->armHersteltermijn(instance: $instance, durationDays: $durationDays);
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

		$updated = $this->termService->updateTermijnInstance(
			$termInstanceId,
			[
				'endDateCurrent' => $newEnd,
				'status' => 'lopend',
				'pauseDeadline' => null,
				'pauseTimerId' => null,
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
