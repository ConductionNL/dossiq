<?php

/**
 * The figures the team queue shows above and beside its list
 *
 * How many open cases have no handler, how many of them came in today, how
 * many are due within a week, how long the oldest has waited, and every open
 * case counted per case type with its unassigned share.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\Term\DayCount;

/**
 * Folds the open cases into the team queue's figures. A case is in the queue while it has no handler.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class QueueFigures {
	/**
	 * Constructor.
	 *
	 * @param DayCount $dayCount Counts the days a case has waited.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DayCount $dayCount,
	) {
	}//end __construct()

	/**
	 * Fold the open cases into the queue figures.
	 *
	 * @param array<int, array> $openCases The open cases, as plain arrays.
	 * @param DateTimeImmutable $today Today, at midnight.
	 * @param array<string, string> $typeTitles Case type titles by id.
	 *
	 * @return array<string, mixed> The queue figures.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function fold(array $openCases, DateTimeImmutable $today, array $typeTitles): array {
		$todayText = $today->format('Y-m-d');
		$weekEnd = $today->modify('+7 days')->format('Y-m-d');
		$figures = ['queueCount' => 0, 'queueNewToday' => 0, 'queueDueSoon' => 0, 'queueOldestDays' => 0];
		$byType = [];

		foreach ($openCases as $case) {
			$unassigned = ((string)($case['assignee'] ?? '') === '');
			$byType = $this->tallyType(byType: $byType, type: (string)($case['caseType'] ?? ''), unassigned: $unassigned);
			if ($unassigned === true) {
				$figures = $this->addWaiting(figures: $figures, case: $case, today: $todayText, weekEnd: $weekEnd);
			}
		}

		uasort(
			$byType,
			static function (array $a, array $b): int {
				return ($b['open'] <=> $a['open']);
			}
		);

		$rows = [];
		foreach ($byType as $type => $counts) {
			$type = (string)$type;
			$rows[] = ['caseType' => $type, 'title' => ($typeTitles[$type] ?? $type)] + $counts;
		}

		return $figures + ['queueByType' => $rows];
	}//end fold()

	/**
	 * Count one open case under its type.
	 *
	 * @param array<string, array{open: int, waiting: int}> $byType The counts so far.
	 * @param string $type The case type id; an empty id is not counted.
	 * @param bool $unassigned Whether the case has no handler.
	 *
	 * @return array<string, array{open: int, waiting: int}> The counts.
	 */
	private function tallyType(array $byType, string $type, bool $unassigned): array {
		if ($type === '') {
			return $byType;
		}

		$counts = ($byType[$type] ?? ['open' => 0, 'waiting' => 0]);
		$counts['open']++;
		$counts['waiting'] += (int)$unassigned;
		$byType[$type] = $counts;

		return $byType;
	}//end tallyType()

	/**
	 * Count one case without a handler.
	 *
	 * @param array<string, int> $figures The figures so far.
	 * @param array $case The case.
	 * @param string $today Today, Y-m-d.
	 * @param string $weekEnd Seven days from today, Y-m-d.
	 *
	 * @return array<string, int> The figures.
	 */
	private function addWaiting(array $figures, array $case, string $today, string $weekEnd): array {
		$start = substr((string)($case['startDate'] ?? ''), 0, 10);
		$deadline = substr((string)($case['deadline'] ?? ''), 0, 10);

		$figures['queueCount']++;
		$figures['queueNewToday'] += (int)($start === $today);
		$figures['queueDueSoon'] += (int)($deadline !== '' && $deadline >= $today && $deadline < $weekEnd);
		$figures['queueOldestDays'] = max($figures['queueOldestDays'], $this->daysSince(date: $start, today: $today));

		return $figures;
	}//end addWaiting()

	/**
	 * Whole days from a date to today; 0 for an empty, unreadable or future date.
	 *
	 * @param string $date The date, Y-m-d.
	 * @param string $today Today, Y-m-d.
	 *
	 * @return int The days.
	 */
	private function daysSince(string $date, string $today): int {
		if ($date === '') {
			return 0;
		}

		try {
			$days = $this->dayCount->signed(from: new DateTimeImmutable($date), to: new DateTimeImmutable($today));
		} catch (\Exception $e) {
			return 0;
		}

		return max(0, $days);
	}//end daysSince()
}//end class
