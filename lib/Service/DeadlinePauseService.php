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
use RuntimeException;

/**
 * AWB 4:5 / 4:15 pause + resume on a TermijnInstance.
 */
class DeadlinePauseService {
	/**
	 * Constructor.
	 *
	 * @param TermijnService $termService TermijnService.
	 */
	public function __construct(
		private readonly TermijnService $termService,
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

		$now = new DateTimeImmutable();
		$current = new DateTimeImmutable((string)($instance['endDateCurrent'] ?? $now->format('Y-m-d')));
		$newEnd = $current->modify('+' . $durationDays . ' days')->format('Y-m-d');
		$pauseEnd = $now->modify('+' . $durationDays . ' days')->format('Y-m-d');

		$updated = $this->termService->updateTermijnInstance(
			$termInstanceId,
			[
				'endDateCurrent' => $newEnd,
				'status' => 'paused',
				'pauseDeadline' => $pauseEnd,
				'pauzeStartDatum' => $now->format('Y-m-d'),
				'pauzeDuurDagen' => $durationDays,
			]
		);

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

		// Pull back the unused portion of einddatumActueel.
		$current = new DateTimeImmutable((string)($instance['endDateCurrent'] ?? $aanvullingDatum->format('Y-m-d')));
		$newEnd = $current->modify('-' . $unused . ' days')->format('Y-m-d');

		$updated = $this->termService->updateTermijnInstance(
			$termInstanceId,
			[
				'endDateCurrent' => $newEnd,
				'status' => 'lopend',
				'pauseDeadline' => null,
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
