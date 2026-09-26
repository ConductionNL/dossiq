<?php

/**
 * The calendar a planned series fires on.
 *
 * Pure date arithmetic, split out of
 * {@see \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument} so the flow document
 * stays about the flow graph and this stays about dates. It resolves no
 * service and reads no store, so every rule here is testable without an
 * instance.
 *
 * 🔴 A DAY-OF-MONTH ABOVE 28 SKIPS MONTHS, SILENTLY. `0 6 31 * *` names the
 * 31st, and February, April, June, September and November do not have one, so
 * a monthly series anchored on the 31st would quietly miss five occurrences a
 * year. {@see self::usesLastDay()} answers when the cron day field must be `L`
 * instead, and {@see self::occurrence()} answers the same days that `L` fires
 * on, so the Related tab and the scheduler never disagree.
 *
 * 🔴 NOT `+1 month`. PHP's relative month overflows: 31 August plus one month
 * is 1 October. Every occurrence is computed from the ANCHOR rather than from
 * the one before it, so a month that clamps cannot drag the rest of the series
 * earlier with it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
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
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use DateTimeImmutable;
use RuntimeException;

/**
 * Answers which days a recurrence fires on.
 *
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */
class PlannedSeriesCalendar {

	/**
	 * A follow-up that happens once. Today's behaviour.
	 *
	 * @var string
	 */
	public const RECURRENCE_NONE = 'none';

	/**
	 * The recurrences a follow-up may carry, and how many months apart they are.
	 *
	 * @var array<string, int>
	 */
	public const RECURRENCE_MONTHS = [
		self::RECURRENCE_NONE => 0,
		'monthly' => 1,
		'quarterly' => 3,
		'halfYearly' => 6,
		'yearly' => 12,
	];

	/**
	 * Days in each month of a NON-leap year, indexed 1 to 12.
	 *
	 * Non-leap on purpose: it answers "is there a 29th in every February this
	 * series can fire in", and three years in four there is not.
	 *
	 * @var array<int, int>
	 */
	private const MONTH_LENGTHS = [
		1 => 31,
		2 => 28,
		3 => 31,
		4 => 30,
		5 => 31,
		6 => 30,
		7 => 31,
		8 => 31,
		9 => 30,
		10 => 31,
		11 => 30,
		12 => 31,
	];

	/**
	 * How many occurrences past the requested moment are considered.
	 *
	 * The candidate index is computed rather than counted up to, so four steps
	 * is already more than the rounding can be out by. The ceiling exists so a
	 * malformed anchor can never loop.
	 *
	 * @var integer
	 */
	private const LOOKAHEAD = 4;

	/**
	 * Validate a recurrence token.
	 *
	 * A token nobody recognises is refused rather than quietly read as `none`.
	 * A series somebody asked for and did not get is the failure this change
	 * exists to end, and falling back would reintroduce it one typo at a time.
	 *
	 * @param string $recurrence The requested token, or the empty string.
	 *
	 * @return string The recurrence, `none` when nothing was asked for.
	 *
	 * @throws RuntimeException `invalid_recurrence` when the token is not one of ours.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function recurrenceOf(string $recurrence): string {
		$trimmed = trim($recurrence);
		if ($trimmed === '') {
			return self::RECURRENCE_NONE;
		}

		if (array_key_exists($trimmed, self::RECURRENCE_MONTHS) === false) {
			throw new RuntimeException('invalid_recurrence');
		}

		return $trimmed;
	}//end recurrenceOf()

	/**
	 * The next date after `$from` that this series fires on.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param DateTimeImmutable $anchor The first occurrence.
	 * @param DateTimeImmutable $from The moment to look forward from, exclusive.
	 *
	 * @return DateTimeImmutable|null The next occurrence, or null when there is none.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function nextOccurrence(string $recurrence, DateTimeImmutable $anchor, DateTimeImmutable $from): ?DateTimeImmutable {
		$step = (int)(self::RECURRENCE_MONTHS[$recurrence] ?? 0);
		$after = $from->format('Y-m-d');
		if ($step === 0) {
			if ($anchor->format('Y-m-d') > $after) {
				return $anchor;
			}

			return null;
		}

		$anchorMonths = (((int)$anchor->format('Y') * 12) + (int)$anchor->format('n'));
		$fromMonths = (((int)$from->format('Y') * 12) + (int)$from->format('n'));
		$first = max(0, intdiv(($fromMonths - $anchorMonths), $step));

		for ($index = $first; $index <= ($first + self::LOOKAHEAD); $index++) {
			$candidate = $this->occurrence(recurrence: $recurrence, anchor: $anchor, index: $index);
			if ($candidate->format('Y-m-d') > $after) {
				return $candidate;
			}
		}

		return null;
	}//end nextOccurrence()

	/**
	 * The occurrence at a given step from the anchor.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param DateTimeImmutable $anchor The first occurrence.
	 * @param integer $index How many steps past the anchor.
	 *
	 * @return DateTimeImmutable The occurrence.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function occurrence(string $recurrence, DateTimeImmutable $anchor, int $index): DateTimeImmutable {
		$step = (int)(self::RECURRENCE_MONTHS[$recurrence] ?? 0);
		$ahead = (((int)$anchor->format('n') - 1) + ($index * $step));
		$year = ((int)$anchor->format('Y') + intdiv($ahead, 12));
		$month = (($ahead % 12) + 1);

		$length = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
		$day = min((int)$anchor->format('j'), $length);
		if ($this->usesLastDay(recurrence: $recurrence, anchor: $anchor) === true) {
			$day = $length;
		}

		return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
	}//end occurrence()

	/**
	 * Whether this series runs on the last day of its months rather than a fixed one.
	 *
	 * True exactly when the anchor day does not exist in every month the series
	 * can fire in: a monthly series on the 31st, a quarterly one that passes
	 * through February on the 30th, a yearly one on 29 February. The cron day
	 * field is `L` in that case, and the arithmetic here answers the same days.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param DateTimeImmutable $anchor The first occurrence.
	 *
	 * @return boolean Whether the last day of the month is used.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function usesLastDay(string $recurrence, DateTimeImmutable $anchor): bool {
		$shortest = 31;
		foreach ($this->monthsOf(recurrence: $recurrence, anchor: $anchor) as $month) {
			$shortest = min($shortest, (int)self::MONTH_LENGTHS[$month]);
		}

		return (int)$anchor->format('j') > $shortest;
	}//end usesLastDay()

	/**
	 * The calendar months a recurrence fires in.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param DateTimeImmutable $anchor The first occurrence.
	 *
	 * @return array<int, int> The month numbers, ascending.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function monthsOf(string $recurrence, DateTimeImmutable $anchor): array {
		$step = (int)(self::RECURRENCE_MONTHS[$recurrence] ?? 0);
		if ($step === 0 || $step === 1) {
			return range(1, 12);
		}

		$months = [];
		$start = (int)$anchor->format('n');
		for ($offset = 0; $offset < 12; $offset += $step) {
			$months[] = ((($start - 1 + $offset) % 12) + 1);
		}

		sort($months);

		return $months;
	}//end monthsOf()

	/**
	 * The next calendar date a pinned cron expression names.
	 *
	 * Only reached for a flow written before a planned follow-up carried a
	 * series, which is why it reads two numeric fields and gives up on anything
	 * else: those flows are all single follow-ups with a pinned day and month.
	 *
	 * @param string $cron The five-field expression.
	 *
	 * @return string The date as `Y-m-d`, or an empty string when it cannot be read.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function dateOfCron(string $cron): string {
		$fields = preg_split('/\s+/', trim($cron));
		if (is_array($fields) === false || count($fields) !== 5) {
			return '';
		}

		$day = (int)$fields[2];
		$month = (int)$fields[3];
		if ($day < 1 || $month < 1) {
			return '';
		}

		$year = (int)date('Y');
		$candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
		if ($candidate >= date('Y-m-d')) {
			return $candidate;
		}

		return sprintf('%04d-%02d-%02d', ($year + 1), $month, $day);
	}//end dateOfCron()

	/**
	 * A recurrence as an English interval, for the flow's own description.
	 *
	 * Not translated: a flow description is stored once, read in the flow
	 * editor and shared by every reader of the instance, so it follows the rest
	 * of the document rather than the session locale.
	 *
	 * @param string $recurrence The recurrence token.
	 *
	 * @return string The interval.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function inWords(string $recurrence): string {
		$words = [
			'monthly' => 'month',
			'quarterly' => 'quarter',
			'halfYearly' => 'half year',
			'yearly' => 'year',
		];

		return (string)($words[$recurrence] ?? 'occurrence');
	}//end inWords()

	/**
	 * The next date to show for a planned flow.
	 *
	 * @param array{recurrence: string, anchor: string, until: string, count: int} $series The series.
	 * @param string $cron The schedule node's cron, for a flow written before series existed.
	 * @param DateTimeImmutable|null $from The moment to measure from.
	 *
	 * @return string The date as `Y-m-d`, or an empty string when it cannot be read.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function nextDate(array $series, string $cron, ?DateTimeImmutable $from): string {
		if ($series['anchor'] === '') {
			return $this->dateOfCron(cron: $cron);
		}

		$moment = $from;
		if ($moment === null) {
			$moment = new DateTimeImmutable('today');
		}

		if ($series['recurrence'] === self::RECURRENCE_NONE) {
			return $series['anchor'];
		}

		$next = $this->nextOccurrence(
			recurrence: $series['recurrence'],
			anchor: new DateTimeImmutable($series['anchor']),
			from: $moment
		);
		if ($next === null) {
			return '';
		}

		return $next->format('Y-m-d');
	}//end nextDate()
}//end class
