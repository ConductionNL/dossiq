<?php

/**
 * Dossiq DwellTimeAnalyzer.
 *
 * The dwell-time metric family of the process-mining report: how long cases
 * sit in each status, and which statuses that makes the worst bottlenecks.
 * Split out of ProcessMiningService so that service keeps only the
 * orchestration — the per-visit interval reconstruction, the
 * median/p90/mean aggregation and the bottleneck ranking (dwell time x case
 * volume) live here and nowhere else.
 *
 * Pure computation: every input is passed in, nothing is read from
 * OpenRegister, so the whole family is exercisable without a register.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Status\StatusDwellService;

/**
 * Reconstructs per-status dwell intervals and ranks the resulting bottlenecks.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class DwellTimeAnalyzer {
	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser      $dates The one date write path.
	 * @param WorkingTimeMeasurer|null $workingTime The engine-calendar measurer.
	 *                                      Optional for the same reason $held is:
	 *                                      a caller that only wants the wall-clock
	 *                                      reconstruction builds the analyzer
	 *                                      unchanged, and every interval then
	 *                                      reports its working hours on the
	 *                                      fallback clock rather than on none.
	 * @param StatusDwellService|null $held  The numbers the case itself carries.
	 *                                      Optional so a caller that only wants
	 *                                      the reconstruction — every existing
	 *                                      one — builds the analyzer unchanged;
	 *                                      {@see self::heldTotalsByStatus()}
	 *                                      answers nothing without it rather
	 *                                      than answering something wrong.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
		private readonly ?StatusDwellService $held = null,
		private readonly ?WorkingTimeMeasurer $workingTime = null,
	) {
	}//end __construct()

	/**
	 * The per-status totals the CASES themselves hold, in working days.
	 *
	 * 🔑 THE PAGE AND THE LIST HAVE TO READ ONE NUMBER. The reconstruction
	 * below walks the statusRecord chain and answers in wall-clock hours; the
	 * case holds working days, written as the status changes. Two measurements
	 * of the same thing on two clocks is how a dashboard and a work list start
	 * disagreeing about the same case in front of the same person. So this is
	 * the number the report publishes beside the aggregates, and it is read off
	 * the case rather than computed a second time here.
	 *
	 * A case that carries no held number contributes nothing, which is every
	 * case that predates the change. Their history is still in the chain and
	 * still reaches the aggregates; only the held total is silent about them,
	 * which is the honest answer to "how long has it been in this status" for
	 * a case nobody recorded entering one.
	 *
	 * @param array<string, array<string, mixed>> $casesById Case rows, keyed by id.
	 * @param DateTimeImmutable|null              $now       The moment to count to.
	 *
	 * @return array<string, int> Working days, keyed by statusType id.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function heldTotalsByStatus(array $casesById, ?DateTimeImmutable $now = null): array {
		if ($this->held === null) {
			return [];
		}

		$totals = [];
		foreach ($casesById as $case) {
			if (is_array($case) === false) {
				continue;
			}

			if (($case['statusDwellTotals'] ?? []) === [] && ($case['currentStatusEnteredAt'] ?? '') === '') {
				continue;
			}

			foreach ($this->held->totalsFor(case: $case, now: $now) as $statusId => $days) {
				$totals[$statusId] = (($totals[$statusId] ?? 0) + $days);
			}
		}

		return $totals;
	}//end heldTotalsByStatus()

	/**
	 * Build dwell-time intervals: one entry per (case, status-visit), the
	 * time the case spent in that status before the next recorded
	 * transition (or, for the still-current status, before `$now`/the
	 * case's `endDate`).
	 *
	 * Handles the invariants callers rely on:
	 *  - a case with zero statusRecords contributes nothing (no crash);
	 *  - the still-open current status uses `$now` as its exit boundary;
	 *  - a closed case's final status uses the case's `endDate`;
	 *  - two records with an identical timestamp yield a zero-hour interval;
	 *  - only intervals that ENTERED the status within `[periodFrom, periodTo]`
	 *    are returned — the exit boundary may fall outside the window.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsByCase Chronologically sorted statusRecords, keyed by case id.
	 * @param array<string, array<string, mixed>> $casesById Case rows, keyed by id.
	 * @param DateTimeImmutable $now "Now", for open cases' current status.
	 * @param DateTimeImmutable $periodFrom Inclusive period start.
	 * @param DateTimeImmutable $periodTo Inclusive period end.
	 *
	 * @return array<int, array<string, mixed>> Rows of {caseId, statusId, hours, wallHours, workingHours, clock, actorId}.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function computeDwellIntervals(
		array $recordsByCase,
		array $casesById,
		DateTimeImmutable $now,
		DateTimeImmutable $periodFrom,
		DateTimeImmutable $periodTo,
	): array {
		$windowStart = $periodFrom->setTime(0, 0, 0);
		$windowEnd = $periodTo->setTime(23, 59, 59);

		$intervals = [];
		foreach ($recordsByCase as $caseId => $records) {
			if (count($records) === 0) {
				continue;
			}

			$case = ($casesById[$caseId] ?? []);
			$endDate = ($case['endDate'] ?? null);
			$closedAt = null;
			if (is_string($endDate) === true && $endDate !== '') {
				$closedAt = ($this->dates->tryParse($endDate) ?? $now);
			}

			$intervals = array_merge(
				$intervals,
				$this->dwellIntervalsForCase(
					records: $records,
					caseId: (string)$caseId,
					closedAt: $closedAt,
					now: $now,
					windowStart: $windowStart,
					windowEnd: $windowEnd,
				)
			);
		}//end foreach

		return $intervals;
	}//end computeDwellIntervals()

	/**
	 * Build the dwell-time intervals for a single case's chronologically sorted statusRecords.
	 *
	 * Only visits ENTERED within `[windowStart, windowEnd]` are returned; the exit boundary of the
	 * final visit is the case's close moment when it has one, and `$now` otherwise.
	 *
	 * @param array<int, array<string, mixed>> $records Chronologically sorted statusRecords for one case.
	 * @param string $caseId The case id.
	 * @param DateTimeImmutable|null $closedAt The case's close moment, or null when still open.
	 * @param DateTimeImmutable $now "Now", for the still-open current status.
	 * @param DateTimeImmutable $windowStart Inclusive window start.
	 * @param DateTimeImmutable $windowEnd Inclusive window end.
	 *
	 * @return array<int, array<string, mixed>> Rows of {caseId, statusId, hours, wallHours, workingHours, clock, actorId}.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	private function dwellIntervalsForCase(
		array $records,
		string $caseId,
		?DateTimeImmutable $closedAt,
		DateTimeImmutable $now,
		DateTimeImmutable $windowStart,
		DateTimeImmutable $windowEnd,
	): array {
		$count = count($records);
		$intervals = [];

		for ($i = 0; $i < $count; $i++) {
			$statusId = (string)($records[$i]['statusType'] ?? '');
			if ($statusId === '') {
				continue;
			}

			$enteredAt = $this->extractTimestamp(record: $records[$i]);
			if ($enteredAt === null) {
				continue;
			}

			if ($enteredAt < $windowStart || $enteredAt > $windowEnd) {
				continue;
			}

			$exitedAt = ($closedAt ?? $now);
			if (($i + 1) < $count) {
				$exitedAt = $this->extractTimestamp(record: $records[$i + 1]);
			}

			if ($exitedAt === null) {
				$exitedAt = $enteredAt;
			}

			$hours = (($exitedAt->getTimestamp() - $enteredAt->getTimestamp()) / 3600.0);
			if ($hours < 0.0) {
				$hours = 0.0;
				$exitedAt = $enteredAt;
			}

			// Two numbers from one interval (design D-1): the working hours the
			// organisation actually spent, and the wall clock the case sat
			// through. `hours` stays the wall clock, so every existing caller
			// reads the number it has always read.
			$measured = $this->measureInterval(enteredAt: $enteredAt, exitedAt: $exitedAt, wallHours: $hours);

			$intervals[] = [
				'caseId' => $caseId,
				'statusId' => $statusId,
				'hours' => $hours,
				'wallHours' => $measured['wallHours'],
				'workingHours' => $measured['workingHours'],
				'clock' => $measured['clock'],
				'actorId' => $this->extractActor(record: $records[$i]),
			];
		}//end for

		return $intervals;
	}//end dwellIntervalsForCase()

	/**
	 * Measure one interval on both clocks.
	 *
	 * Without a measurer the working figure is the wall figure's working-day
	 * share at eight hours a day, which is what the analyser could always have
	 * said; it is marked with the fallback clock so the page never labels it
	 * "on the organisation calendar" when it was not.
	 *
	 * @param DateTimeImmutable $enteredAt When the status was entered.
	 * @param DateTimeImmutable $exitedAt  When it was left.
	 * @param float             $wallHours The wall-clock hours between them.
	 *
	 * @return array{workingHours: float, wallHours: float, clock: string}
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	private function measureInterval(
		DateTimeImmutable $enteredAt,
		DateTimeImmutable $exitedAt,
		float $wallHours,
	): array {
		if ($this->workingTime !== null) {
			return $this->workingTime->measure(from: $enteredAt, to: $exitedAt);
		}

		return [
			'workingHours' => round($wallHours, 2),
			'wallHours' => round($wallHours, 2),
			'clock' => WorkingTimeMeasurer::CLOCK_FALLBACK,
		];
	}//end measureInterval()

	/**
	 * The handler a status record names, or an empty string.
	 *
	 * Design D-3: the records already carry the actor, so grouping by handler
	 * needs no new data and no second walk. An unnamed actor is kept as the
	 * empty string rather than dropped, so the per-assignee table can say how
	 * much time belongs to nobody in particular instead of quietly losing it.
	 *
	 * @param array<string, mixed> $record A statusRecord row.
	 *
	 * @return string The actor id, or an empty string.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	private function extractActor(array $record): string {
		foreach (['actorId', 'userId', 'behandelaarId', 'assigneeId', 'createdBy'] as $key) {
			$value = ($record[$key] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		$owner = ($record['@self']['owner'] ?? null);
		if (is_string($owner) === true && trim($owner) !== '') {
			return trim($owner);
		}

		return '';
	}//end extractActor()

	/**
	 * Aggregate the same intervals per HANDLER rather than per status.
	 *
	 * The same intervals, the same arithmetic, a different key: a table that
	 * disagreed with the per-phase one about the same case would be the
	 * two-measurements defect this change exists to remove.
	 *
	 * @param array<int, array<string, mixed>> $intervals Dwell intervals.
	 *
	 * @return array<int, array<string, mixed>> Rows of {actorId, visitCount, medianHours, p90Hours, meanHours, medianWallHours}.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function aggregateDwellStatsByActor(array $intervals): array {
		$byActor = [];
		foreach ($intervals as $interval) {
			$actorId = (string)($interval['actorId'] ?? '');
			$byActor[$actorId][] = $interval;
		}

		$out = [];
		foreach ($byActor as $actorId => $rows) {
			$working = [];
			$wall = [];
			foreach ($rows as $row) {
				$working[] = (float)($row['workingHours'] ?? ($row['hours'] ?? 0.0));
				$wall[] = (float)($row['wallHours'] ?? ($row['hours'] ?? 0.0));
			}

			sort($working);
			sort($wall);

			$out[] = [
				'actorId' => $actorId,
				'visitCount' => count($rows),
				'medianHours' => round(self::percentile(sorted: $working, percentile: 50.0), 1),
				'p90Hours' => round(self::percentile(sorted: $working, percentile: 90.0), 1),
				'meanHours' => round((array_sum($working) / count($working)), 1),
				'medianWallHours' => round(self::percentile(sorted: $wall, percentile: 50.0), 1),
			];
		}

		usort(
			$out,
			static fn (array $left, array $right): int => ($right['medianHours'] <=> $left['medianHours'])
		);

		return $out;
	}//end aggregateDwellStatsByActor()

	/**
	 * Aggregate dwell-time intervals per status into median/p90/mean stats.
	 *
	 * @param array<int, array{caseId: string, statusId: string, hours: float}> $intervals Dwell intervals.
	 * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
	 *
	 * @return array<int, array{statusId: string, statusName: string, visitCount: int, medianHours: float, p90Hours: float, meanHours: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function aggregateDwellStats(array $intervals, array $statusTypeIndex): array {
		$byStatus = [];
		foreach ($intervals as $interval) {
			$statusId = $interval['statusId'];
			if (isset($byStatus[$statusId]) === false) {
				$byStatus[$statusId] = ['working' => [], 'wall' => []];
			}

			// The headline is the WORKING figure, and the wall clock travels
			// beside it rather than instead of it: a reader who wants to know
			// how long the case sat there in real time still has that number,
			// under its own name (spec V1).
			$byStatus[$statusId]['working'][] = (float)($interval['workingHours'] ?? $interval['hours']);
			$byStatus[$statusId]['wall'][] = (float)($interval['wallHours'] ?? $interval['hours']);
		}

		$out = [];
		foreach ($byStatus as $statusId => $lists) {
			$hoursList = $lists['working'];
			$wallList = $lists['wall'];
			sort($hoursList);
			sort($wallList);
			$out[] = [
				'statusId' => $statusId,
				'statusName' => $this->statusLabel(statusId: $statusId, statusTypeIndex: $statusTypeIndex),
				'visitCount' => count($hoursList),
				'medianHours' => round(self::percentile(sorted: $hoursList, percentile: 50.0), 1),
				'p90Hours' => round(self::percentile(sorted: $hoursList, percentile: 90.0), 1),
				'meanHours' => round((array_sum($hoursList) / count($hoursList)), 1),
				'medianWallHours' => round(self::percentile(sorted: $wallList, percentile: 50.0), 1),
				'p90WallHours' => round(self::percentile(sorted: $wallList, percentile: 90.0), 1),
			];
		}

		return $out;
	}//end aggregateDwellStats()

	/**
	 * Rank statuses by bottleneck severity: median dwell time x visit volume.
	 * Highest score first.
	 *
	 * Each `$dwellStats` row is the shape {@see self::aggregateDwellStats()}
	 * returns: statusId, statusName, visitCount, medianHours, p90Hours,
	 * meanHours. Spelled as a loose shape here only to keep the tag on one
	 * line — PHPCS's PEAR sniff cannot parse a wrapped `@param`.
	 *
	 * @param array<int, array<string, mixed>> $dwellStats Per-status dwell stats.
	 *
	 * @return array<int, array{statusId: string, statusName: string, visitCount: int, medianHours: float, score: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function rankBottlenecks(array $dwellStats): array {
		$ranked = [];
		foreach ($dwellStats as $stat) {
			$ranked[] = [
				'statusId' => $stat['statusId'],
				'statusName' => $stat['statusName'],
				'visitCount' => $stat['visitCount'],
				'medianHours' => $stat['medianHours'],
				'score' => round(($stat['medianHours'] * $stat['visitCount']), 1),
			];
		}

		usort(
			$ranked,
			static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
		);

		return $ranked;
	}//end rankBottlenecks()

	/**
	 * Resolve a statusType id to its human-readable label.
	 *
	 * @param string $statusId StatusType UUID.
	 * @param array<string, array<string, mixed>> $statusTypeIndex StatusType rows, keyed by id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	private function statusLabel(string $statusId, array $statusTypeIndex): string {
		if (isset($statusTypeIndex[$statusId]) === false) {
			return $statusId;
		}

		$entry = $statusTypeIndex[$statusId];
		$label = ($entry['name'] ?? ($entry['title'] ?? ''));
		if (is_string($label) === true && $label !== '') {
			return $label;
		}

		return $statusId;
	}//end statusLabel()

	/**
	 * Extract a record's creation timestamp — either the flattened
	 * `createdAt` key or OpenRegister's `@self.created` metadata block.
	 *
	 * @param array<string, mixed> $record A statusRecord row.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	private function extractTimestamp(array $record): ?DateTimeImmutable {
		$raw = ($record['createdAt'] ?? ($record['@self']['created'] ?? ($record['@self']['createdAt'] ?? null)));
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		return $this->dates->tryParse($raw);
	}//end extractTimestamp()

	/**
	 * Percentile of a pre-sorted numeric list (nearest-rank method).
	 *
	 * @param array<int, float> $sorted Ascending-sorted values.
	 * @param float $percentile Percentile in [0, 100].
	 *
	 * @return float
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	private static function percentile(array $sorted, float $percentile): float {
		$count = count($sorted);
		if ($count === 0) {
			return 0.0;
		}

		if ($count === 1) {
			return $sorted[0];
		}

		$rank = (int)ceil(($percentile / 100.0) * $count);
		$rank = max(1, min($count, $rank));

		return $sorted[($rank - 1)];
	}//end percentile()

}//end class
