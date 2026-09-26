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
	 * @param StatusDwellService|null $held  The numbers the case itself carries.
	 *                                      Optional so a caller that only wants
	 *                                      the reconstruction — every existing
	 *                                      one — builds the analyzer unchanged;
	 *                                      {@see self::heldTotalsByStatus()}
	 *                                      answers nothing without it rather
	 *                                      than answering something wrong.
	 * @param WorkingClock|null       $clock Which clock the working-hours column
	 *                                      is on. Optional for the same reason:
	 *                                      a caller that wants wall hours only
	 *                                      builds the analyzer unchanged.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
		private readonly ?StatusDwellService $held = null,
		private readonly ?WorkingClock $clock = null,
	) {
	}//end __construct()

	/**
	 * Which clock the working-hours numbers on this report are on.
	 *
	 * Answered so the page can label its columns, BEFORE any number is
	 * computed: a report that had to compute first and disclaim afterwards
	 * would have already shown the number.
	 *
	 * Built without a clock, this analyzer reports the wall clock in both
	 * columns, and says exactly that. Naming it working hours would be the
	 * failure the whole change is about.
	 *
	 * @return string One of {@see WorkingClock}'s CLOCK_* constants.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function clock(): string {
		if ($this->clock === null) {
			return WorkingClock::CLOCK_WALL;
		}

		return $this->clock->clock();
	}//end clock()

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
	 * @return array<int, array{caseId: string, statusId: string, hours: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
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
	 * @return array<int, array{caseId: string, statusId: string, hours: float}>
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
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

			$measured = $this->measure(enteredAt: $enteredAt, exitedAt: $exitedAt);

			$intervals[] = [
				'caseId' => $caseId,
				'statusId' => $statusId,
				// `actor` is the statusRecord schema's own field, and the
				// only one: a second spelling guessed at here would be
				// dropped by OpenRegister in silence and read as nobody.
				// Empty rather than absent when the record names no actor,
				// because a by-handler table that quietly omitted
				// unattributed time would show less work than was done.
				'actor' => trim((string)($records[$i]['actor'] ?? '')),
				'hours' => $measured['wallHours'],
				'workingHours' => $measured['workingHours'],
			];
		}//end for

		return $intervals;
	}//end dwellIntervalsForCase()

	/**
	 * One interval's two numbers.
	 *
	 * A negative interval reads as zero on both clocks, which is what this
	 * analyzer has always done: a status record out of order is bad data, not
	 * a case that went backwards in time.
	 *
	 * @param DateTimeImmutable $enteredAt When the status was entered.
	 * @param DateTimeImmutable $exitedAt  When it was left.
	 *
	 * @return array{workingHours: float, wallHours: float} The two numbers.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	private function measure(DateTimeImmutable $enteredAt, DateTimeImmutable $exitedAt): array {
		if ($this->clock !== null) {
			return $this->clock->hoursBetween(from: $enteredAt, to: $exitedAt);
		}

		$wall = (($exitedAt->getTimestamp() - $enteredAt->getTimestamp()) / 3600.0);
		if ($wall < 0.0) {
			return ['workingHours' => 0.0, 'wallHours' => 0.0];
		}

		return ['workingHours' => $wall, 'wallHours' => $wall];
	}//end measure()

	/**
	 * Aggregate dwell-time intervals per status into median/p90/mean stats.
	 *
	 * @param array<int, array{caseId: string, statusId: string, hours: float, workingHours?: float}> $intervals Dwell intervals.
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

			// Both lists, kept separate all the way to the percentile. A
			// median of working hours is NOT the working-hours conversion of
			// the median wall hour: the two orderings differ the moment one
			// case sat over a weekend, and converting the wrong one is a
			// number nobody can reproduce.
			$byStatus[$statusId]['working'][] = ($interval['workingHours'] ?? $interval['hours']);
			$byStatus[$statusId]['wall'][] = $interval['hours'];
		}

		$out = [];
		foreach ($byStatus as $statusId => $lists) {
			$out[] = array_merge(
				[
					'statusId' => $statusId,
					'statusName' => $this->statusLabel(statusId: $statusId, statusTypeIndex: $statusTypeIndex),
				],
				$this->summarise(workingHours: $lists['working'], wallHours: $lists['wall'])
			);
		}

		return $out;
	}//end aggregateDwellStats()

	/**
	 * The same intervals, grouped by the person who held them.
	 *
	 * SAME INTERVALS, SAME SUMMARY, DIFFERENT KEY. No new data and no second
	 * reconstruction: a by-assignee table computed from its own walk of the
	 * records would drift from the by-phase one, and the two sit on the same
	 * page.
	 *
	 * Time no record attributed is reported under its own row rather than
	 * dropped, because a table that quietly omits it shows less work than was
	 * done and nothing on it says so.
	 *
	 * @param array<int, array<string, mixed>> $intervals Dwell intervals.
	 *
	 * @return array<int, array<string, mixed>> One row per actor, longest median first.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	public function aggregateDwellStatsByActor(array $intervals): array {
		$byActor = [];
		foreach ($intervals as $interval) {
			$actor = trim((string)($interval['actor'] ?? ''));
			if (isset($byActor[$actor]) === false) {
				$byActor[$actor] = ['working' => [], 'wall' => []];
			}

			$byActor[$actor]['working'][] = ($interval['workingHours'] ?? $interval['hours']);
			$byActor[$actor]['wall'][] = $interval['hours'];
		}

		$out = [];
		foreach ($byActor as $actor => $lists) {
			$out[] = array_merge(
				['actor' => (string)$actor],
				$this->summarise(workingHours: $lists['working'], wallHours: $lists['wall'])
			);
		}

		usort(
			$out,
			static fn (array $left, array $right): int => ($right['medianWorkingHours'] <=> $left['medianWorkingHours'])
		);

		return $out;
	}//end aggregateDwellStatsByActor()

	/**
	 * Median, p90 and mean of one group, on both clocks.
	 *
	 * `medianHours` stays the wall-clock number under its old name, so every
	 * existing reader keeps reading what it always read. The working-hours
	 * numbers arrive under their own names, and the page decides which is the
	 * headline. Renaming the old key would have silently changed the meaning
	 * of every chart already drawn from it.
	 *
	 * @param array<int, float> $workingHours The working-hours values.
	 * @param array<int, float> $wallHours    The wall-clock values.
	 *
	 * @return array<string, float|int> The summary.
	 *
	 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
	 */
	private function summarise(array $workingHours, array $wallHours): array {
		sort($workingHours);
		sort($wallHours);
		$count = count($wallHours);

		return [
			'visitCount' => $count,
			'medianWorkingHours' => round(self::percentile(sorted: $workingHours, percentile: 50.0), 1),
			'p90WorkingHours' => round(self::percentile(sorted: $workingHours, percentile: 90.0), 1),
			'meanWorkingHours' => round((array_sum($workingHours) / $count), 1),
			'medianHours' => round(self::percentile(sorted: $wallHours, percentile: 50.0), 1),
			'p90Hours' => round(self::percentile(sorted: $wallHours, percentile: 90.0), 1),
			'meanHours' => round((array_sum($wallHours) / $count), 1),
		];
	}//end summarise()

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
