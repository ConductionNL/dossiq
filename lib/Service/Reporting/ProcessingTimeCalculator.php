<?php

/**
 * Processing-time figures: target compliance, distribution, trend, throughput,
 * records at risk and the per-type breakdown.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\Term\DayCount;

/**
 * Pure arithmetic over prepared records and record types.
 *
 * A prepared record is `{id, title, identifier, caseType, startDate, endDate,
 * deadline}` with dates as `Y-m-d` or null. A prepared type is `{id, slug,
 * name, targetDays}`. The calculator never loads or labels anything; the
 * report service does both. The table rows live in {@see ProcessingTimeRows}.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class ProcessingTimeCalculator {

	/**
	 * Histogram bins in days; the last one is open-ended.
	 *
	 * @var array<int, array{label: string, min: int, max: int|null}>
	 */
	public const BINS = [
		['label' => '0-7', 'min' => 0, 'max' => 7],
		['label' => '8-14', 'min' => 8, 'max' => 14],
		['label' => '15-21', 'min' => 15, 'max' => 21],
		['label' => '22-28', 'min' => 22, 'max' => 28],
		['label' => '29-42', 'min' => 29, 'max' => 42],
		['label' => '43-56', 'min' => 43, 'max' => 56],
		['label' => '57+', 'min' => 57, 'max' => null],
	];

	/**
	 * Constructor.
	 *
	 * @param DayCount $days Whole-day arithmetic.
	 */
	public function __construct(
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * Parse an ISO 8601 duration to calendar days.
	 *
	 * Months count as 30 days and years as 365. Zero or unreadable is null.
	 *
	 * @param mixed $duration The duration, such as `P30D` or `P1Y2M3D`.
	 *
	 * @return int|null The day count, or null.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function durationToDays(mixed $duration): ?int {
		if (is_string($duration) === false
			|| preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?$/i', $duration, $parts) !== 1
		) {
			return null;
		}

		$total = ((int)($parts[1] ?? 0) * 365)
			+ ((int)($parts[2] ?? 0) * 30)
			+ ((int)($parts[3] ?? 0) * 7)
			+ (int)($parts[4] ?? 0);

		if ($total > 0) {
			return $total;
		}

		return null;
	}//end durationToDays()

	/**
	 * Whole calendar days between a record's start and end, never negative.
	 *
	 * @param array<string, mixed> $record A prepared record.
	 *
	 * @return int|null The day count, or null when a date is missing.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function processingDays(array $record): ?int {
		$start = ($record['startDate'] ?? null);
		$end   = ($record['endDate'] ?? null);
		if (is_string($start) === false || is_string($end) === false) {
			return null;
		}

		return max(0, $this->days->signed(from: new DateTimeImmutable($start), to: new DateTimeImmutable($end)));
	}//end processingDays()

	/**
	 * Target compliance overall and per record type.
	 *
	 * @param array<int, array<string, mixed>>    $completed Completed records.
	 * @param array<string, array<string, mixed>> $types     Prepared types keyed by id.
	 *
	 * @return array{summary: array{overallRate: int|null, withinSla: int, total: int, excluded: int}, byType: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function slaCompliance(array $completed, array $types): array {
		$byType    = [];
		$withinSla = 0;
		$total     = 0;
		$excluded  = 0;

		foreach ($completed as $record) {
			$typeId = (string)($record['caseType'] ?? '');
			$target = ($types[$typeId]['targetDays'] ?? null);
			$actual = $this->processingDays(record: $record);
			if ($target === null || $actual === null) {
				$excluded++;
				continue;
			}

			$total++;
			$isWithin = ($actual <= $target);
			if ($isWithin === true) {
				$withinSla++;
			}

			// Every version of a case type is one row (its family, the shared identifier).
			$key = (string)($types[$typeId]['family'] ?? $typeId);
			$byType[$key] ??= [
				'id'         => $typeId,
				'name'       => (string)($types[$typeId]['name'] ?? ''),
				'total'      => 0,
				'withinSla'  => 0,
				'totalDays'  => 0,
				'targetDays' => $target,
			];
			$byType[$key]['total']++;
			$byType[$key]['totalDays'] += $actual;
			if ($isWithin === true) {
				$byType[$key]['withinSla']++;
			}
		}//end foreach

		$rows = [];
		foreach ($byType as $entry) {
			$rows[] = [
				'id'         => $entry['id'],
				'name'       => $entry['name'],
				'total'      => $entry['total'],
				'withinSla'  => $entry['withinSla'],
				'rate'       => $this->percent(part: $entry['withinSla'], whole: $entry['total']),
				'avgActual'  => (int)round($entry['totalDays'] / $entry['total']),
				'targetDays' => $entry['targetDays'],
			];
		}

		return [
			'summary' => [
				'overallRate' => $this->percent(part: $withinSla, whole: $total),
				'withinSla'   => $withinSla,
				'total'       => $total,
				'excluded'    => $excluded,
			],
			'byType'  => $rows,
		];
	}//end slaCompliance()

	/**
	 * Processing-time histogram over the fixed bins.
	 *
	 * The target is reported only when every record shares one, which is the
	 * case when the page is narrowed to one record type.
	 *
	 * @param array<int, array<string, mixed>>    $completed Completed records.
	 * @param array<string, array<string, mixed>> $types     Prepared types keyed by id.
	 *
	 * @return array{bins: array<int, array{label: string, count: int}>, slaTargetDays: int|null, targetBin: string|null}
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function distribution(array $completed, array $types): array {
		$counts  = array_fill(0, count(self::BINS), 0);
		$targets = [];

		foreach ($completed as $record) {
			$days = $this->processingDays(record: $record);
			if ($days === null) {
				continue;
			}

			$target = ($types[(string)($record['caseType'] ?? '')]['targetDays'] ?? null);
			if ($target !== null) {
				$targets[$target] = true;
			}

			$index = $this->binIndex(days: $days);
			$counts[$index]++;
		}

		$bins = [];
		foreach (self::BINS as $index => $bin) {
			$bins[] = ['label' => $bin['label'], 'count' => $counts[$index]];
		}

		$slaTargetDays = null;
		$targetBin     = null;
		if (count($targets) === 1) {
			$slaTargetDays = (int)array_key_first($targets);
			$targetBin     = self::BINS[$this->binIndex(days: $slaTargetDays)]['label'];
		}

		return ['bins' => $bins, 'slaTargetDays' => $slaTargetDays, 'targetBin' => $targetBin];
	}//end distribution()

	/**
	 * Monthly target compliance over a trailing window ending this month.
	 *
	 * @param array<int, array<string, mixed>>    $completed Completed records.
	 * @param array<string, array<string, mixed>> $types     Prepared types keyed by id.
	 * @param int                                 $months    Months to cover.
	 * @param DateTimeImmutable                   $today     The reference day.
	 *
	 * @return array<int, array{month: string, rate: int|null, withinSla: int, total: int}>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function monthlyTrend(array $completed, array $types, int $months, DateTimeImmutable $today): array {
		$firstOfMonth = $today->modify(modifier: 'first day of this month');
		$buckets      = [];
		for ($offset = (max(1, $months) - 1); $offset >= 0; $offset--) {
			$key           = $firstOfMonth->modify(modifier: '-'.$offset.' months')->format('Y-m');
			$buckets[$key] = ['month' => $key, 'withinSla' => 0, 'total' => 0];
		}

		foreach ($completed as $record) {
			$key = substr((string)($record['endDate'] ?? ''), 0, 7);
			if (isset($buckets[$key]) === false) {
				continue;
			}

			$target = ($types[(string)($record['caseType'] ?? '')]['targetDays'] ?? null);
			$actual = $this->processingDays(record: $record);
			if ($target === null || $actual === null) {
				continue;
			}

			$buckets[$key]['total']++;
			if ($actual <= $target) {
				$buckets[$key]['withinSla']++;
			}
		}

		$rows = [];
		foreach ($buckets as $bucket) {
			$rows[] = [
				'month'     => $bucket['month'],
				'rate'      => $this->percent(part: $bucket['withinSla'], whole: $bucket['total']),
				'withinSla' => $bucket['withinSla'],
				'total'     => $bucket['total'],
			];
		}

		return $rows;
	}//end monthlyTrend()

	/**
	 * Records closed per ISO week over the trailing weeks ending at the most
	 * recent completion.
	 *
	 * @param array<int, array<string, mixed>> $completed Completed records.
	 * @param int                              $weeks     Weeks to cover.
	 *
	 * @return array<int, array{weekLabel: string, week: string, count: int}> Oldest week first, or [] without completions.
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function weeklyThroughput(array $completed, int $weeks = 12): array {
		$ends = [];
		foreach ($completed as $record) {
			$end = ($record['endDate'] ?? null);
			if (is_string($end) === true && $end !== '') {
				$ends[] = $end;
			}
		}

		if ($ends === []) {
			return [];
		}

		$anchor  = $this->mondayOf(date: max($ends));
		$buckets = [];
		for ($offset = ($weeks - 1); $offset >= 0; $offset--) {
			$monday = $anchor->sub(interval: new DateInterval('P'.($offset * 7).'D'));
			$buckets[$monday->format('Y-m-d')] = [
				'weekLabel' => 'W'.(int)$monday->format('W').' '.$monday->format('o'),
				// The ISO week key a chart writes as "w40".
				'week'      => $monday->format('o-\\WW'),
				'count'     => 0,
			];
		}

		foreach ($ends as $end) {
			$key = $this->mondayOf(date: $end)->format('Y-m-d');
			if (isset($buckets[$key]) === true) {
				$buckets[$key]['count']++;
			}
		}

		return array_values($buckets);
	}//end weeklyThroughput()

	/**
	 * The histogram bin a day count falls in.
	 *
	 * @param int $days The day count.
	 *
	 * @return int The bin index; the open-ended last bin catches the rest.
	 */
	private function binIndex(int $days): int {
		foreach (self::BINS as $index => $bin) {
			if ($days >= $bin['min'] && ($bin['max'] === null || $days <= $bin['max'])) {
				return $index;
			}
		}

		return (count(self::BINS) - 1);
	}//end binIndex()

	/**
	 * The Monday of the week a date falls in.
	 *
	 * @param string $date A `Y-m-d` date.
	 *
	 * @return DateTimeImmutable Midnight on that Monday.
	 */
	private function mondayOf(string $date): DateTimeImmutable {
		$day = new DateTimeImmutable(substr($date, 0, 10));

		return $day->sub(interval: new DateInterval('P'.((int)$day->format('N') - 1).'D'));
	}//end mondayOf()

	/**
	 * A rounded percentage, or null for an empty whole.
	 *
	 * @param int $part  The part.
	 * @param int $whole The whole.
	 *
	 * @return int|null The percentage, or null.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function percent(int $part, int $whole): ?int {
		if ($whole === 0) {
			return null;
		}

		return (int)round(($part / $whole) * 100);
	}//end percent()
}//end class
