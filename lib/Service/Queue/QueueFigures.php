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
use OCA\Dossiq\Service\Team\ReaderTeamCases;
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
	 * @param DayCount             $dayCount  Counts the days a case has waited.
	 * @param ReaderTeamCases|null $teamCases The open cases of the reader's teams, which the figures count.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DayCount $dayCount,
		private readonly ?ReaderTeamCases $teamCases = null,
	) {
	}//end __construct()

	/**
	 * Fold the open cases of the reader's teams into the queue figures.
	 *
	 * @param string                $userId     The reader.
	 * @param array<int, array>     $openCases  The open cases, as plain arrays.
	 * @param DateTimeImmutable     $today      Today, at midnight.
	 * @param array<string, string> $typeTitles Case type titles by id.
	 *
	 * @return array<string, mixed> The queue figures.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams
	 */
	public function foldFor(string $userId, array $openCases, DateTimeImmutable $today, array $typeTitles): array {
		if ($this->teamCases !== null) {
			$openCases = $this->teamCases->casesOf(userId: $userId, cases: $openCases);
		}

		return $this->fold(openCases: $openCases, today: $today, typeTitles: $typeTitles);
	}//end foldFor()

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
		$run = new QueueFoldRun(today: $today, typeTitles: $typeTitles);
		$run->weekEnd = $today->modify('+7 days')->format('Y-m-d');
		foreach ($openCases as $case) {
			$this->foldCase(run: $run, openCase: $case);
		}

		return $run->figures + ['queueByType' => self::typeRows(run: $run)];
	}//end fold()

	/**
	 * Fold one open case: count it under its type, and when it has no handler, as waiting.
	 *
	 * @param QueueFoldRun $run  The fold.
	 * @param array         $openCase The case.
	 *
	 * @return void
	 */
	private function foldCase(QueueFoldRun $run, array $openCase): void {
		$this->tallyType(run: $run, openCase: $openCase);
		if (self::isUnassigned(openCase: $openCase) === false) {
			return;
		}

		$this->addWaiting(run: $run, openCase: $openCase);
		$this->noteIfOldest(run: $run, openCase: $openCase);
	}//end foldCase()

	/**
	 * Whether a case has no handler.
	 *
	 * @param array $openCase The case.
	 *
	 * @return bool True when no one is assigned.
	 */
	private static function isUnassigned(array $openCase): bool {
		return ((string)($openCase['assignee'] ?? '') === '');
	}//end isUnassigned()

	/**
	 * Name a waiting case the oldest when it started before the oldest so far.
	 *
	 * @param QueueFoldRun $run  The fold.
	 * @param array         $openCase The waiting case.
	 *
	 * @return void
	 */
	private function noteIfOldest(QueueFoldRun $run, array $openCase): void {
		$start = substr((string)($openCase['startDate'] ?? ''), 0, 10);
		if (self::isOlderStart(run: $run, start: $start) === false) {
			return;
		}

		$run->oldestStart = $start;
		$this->noteOldest(run: $run, openCase: $openCase);
	}//end noteIfOldest()

	/**
	 * Whether a waiting case started on a readable day, no later than today, before the oldest so far.
	 *
	 * @param QueueFoldRun $run   The fold.
	 * @param string       $start The case's start day, Y-m-d.
	 *
	 * @return bool True when it is the new oldest.
	 */
	private static function isOlderStart(QueueFoldRun $run, string $start): bool {
		if ($start === '' || $start > $run->todayText) {
			return false;
		}

		return ($run->oldestStart === null || $start < $run->oldestStart);
	}//end isOlderStart()

	/**
	 * The by-type rows, the type with the most open cases first.
	 *
	 * @param QueueFoldRun $run The finished fold.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private static function typeRows(QueueFoldRun $run): array {
		$byType = $run->byType;
		uasort(
			$byType,
			static function (array $a, array $b): int {
				return ($b['open'] <=> $a['open']);
			}
		);

		$rows = [];
		foreach ($byType as $type => $counts) {
			$type = (string)$type;
			$rows[] = ['caseType' => $type, 'title' => ($run->typeTitles[$type] ?? $type)] + $counts;
		}

		return $rows;
	}//end typeRows()

	/**
	 * Count one open case under its type; a case without a type id is not counted.
	 *
	 * @param QueueFoldRun $run  The fold.
	 * @param array         $openCase The case.
	 *
	 * @return void
	 */
	private function tallyType(QueueFoldRun $run, array $openCase): void {
		$type = (string)($openCase['caseType'] ?? '');
		if ($type === '') {
			return;
		}

		$counts = ($run->byType[$type] ?? ['open' => 0, 'waiting' => 0]);
		$counts['open']++;
		$counts['waiting'] += (int)self::isUnassigned(openCase: $openCase);
		$run->byType[$type] = $counts;
	}//end tallyType()

	/**
	 * Count one case without a handler.
	 *
	 * @param QueueFoldRun $run  The fold.
	 * @param array         $openCase The case.
	 *
	 * @return void
	 */
	private function addWaiting(QueueFoldRun $run, array $openCase): void {
		$start = substr((string)($openCase['startDate'] ?? ''), 0, 10);
		$deadline = substr((string)($openCase['deadline'] ?? ''), 0, 10);

		$run->figures['queueCount']++;
		$run->figures['queueNewToday'] += (int)($start === $run->todayText);
		$run->figures['queueDueSoon'] += (int)($deadline !== '' && $deadline >= $run->todayText && $deadline < $run->weekEnd);
		$run->figures['queueOldestDays'] = max($run->figures['queueOldestDays'], $this->daysSince(date: $start, today: $run->todayText));
	}//end addWaiting()

	/**
	 * Name the oldest waiting case: its type's title and the days to its
	 * deadline (negative once passed, null without a readable deadline), for
	 * the "Waiting longest" tile's line (DqTeamwachtrij).
	 *
	 * @param QueueFoldRun $run  The fold.
	 * @param array         $openCase The oldest case so far.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	private function noteOldest(QueueFoldRun $run, array $openCase): void {
		$type = (string)($openCase['caseType'] ?? '');
		$run->figures['queueOldestType'] = ($run->typeTitles[$type] ?? $type);
		$run->figures['queueOldestDueDays'] = null;

		$deadline = substr((string)($openCase['deadline'] ?? ''), 0, 10);
		if ($deadline !== '') {
			try {
				$run->figures['queueOldestDueDays'] = $this->dayCount->signed(from: $run->today, to: new DateTimeImmutable($deadline));
			} catch (\Exception $e) {
				$run->figures['queueOldestDueDays'] = null;
			}
		}
	}//end noteOldest()

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
