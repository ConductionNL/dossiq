<?php

/**
 * Dossiq ComplaintPatternFigures.
 *
 * The counts behind the complaint patterns of one quarter: per category,
 * department, channel and month, the rise against the quarter before, and
 * how the quarter's complaints were handled. Pure: no I/O, no wording.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Complaint
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
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Complaint;

use DateTimeImmutable;
use OCA\Dossiq\Service\Term\DayCount;

/**
 * Counts complaint patterns. Never reads or answers an employee.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */
class ComplaintPatternFigures {
	/**
	 * A category or department that rose by more than this share is flagged.
	 */
	public const RISE_FLAG_PERCENT = 50;

	/**
	 * The Awb term for handling a complaint when the complaint names none (art. 9:11).
	 */
	public const AWB_TERM_DAYS = 42;

	/**
	 * The verdicts that find the complaint (partly) well-founded.
	 *
	 * @var list<string>
	 */
	private const UPHELD = ['upheld', 'partly_upheld'];

	/**
	 * Constructor.
	 *
	 * @param DayCount $days Counts the days between two moments.
	 */
	public function __construct(
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * Every figure of one quarter.
	 *
	 * @param QuarterPeriod                       $period       The quarter.
	 * @param list<array<string, mixed>>          $complaints   The complaints received in the twelve months and the quarter before.
	 * @param array<string, array<string, mixed>> $dispositions The final disposition per complaint id.
	 *
	 * @return array{count: int, byCategory: array<string, list<mixed>>, byDepartment: array<string, list<mixed>>,
	 *               byChannel: array<string, list<mixed>>, byMonth: array{labels: list<string>, counts: list<int>},
	 *               handling: array<string, mixed>}
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function compute(QuarterPeriod $period, array $complaints, array $dispositions): array {
		$current = $this->receivedIn(period: $period, complaints: $complaints);
		$previous = $this->receivedIn(period: $period->previous(), complaints: $complaints);
		$channel = $this->breakdown(field: 'receiptChannel', current: $current, previous: []);
		unset($channel['risePercent'], $channel['previousCounts']);

		return [
			'count' => count($current),
			'byCategory' => $this->breakdown(field: 'category', current: $current, previous: $previous),
			'byDepartment' => $this->breakdown(field: 'involvedDepartment', current: $current, previous: $previous),
			'byChannel' => $channel,
			'byMonth' => $this->byMonth(period: $period, complaints: $complaints),
			'handling' => $this->handling(current: $current, dispositions: $dispositions),
		];
	}//end compute()

	/**
	 * The complaints received in one quarter.
	 *
	 * @param QuarterPeriod              $period     The quarter.
	 * @param list<array<string, mixed>> $complaints The complaints.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function receivedIn(QuarterPeriod $period, array $complaints): array {
		return array_values(
			array_filter($complaints, static fn (array $complaint): bool => $period->holds(date: (string)($complaint['receiptDate'] ?? '')))
		);
	}//end receivedIn()

	/**
	 * Counts per value of one field, most first, with the rise against the
	 * quarter before where it passes the flag.
	 *
	 * @param string                     $field    The complaint field.
	 * @param list<array<string, mixed>> $current  This quarter's complaints.
	 * @param list<array<string, mixed>> $previous The quarter before's complaints.
	 *
	 * @return array{labels: list<string>, counts: list<int>, risePercent: list<int|null>, previousCounts: list<int>}
	 */
	private function breakdown(string $field, array $current, array $previous): array {
		$now = $this->countBy(field: $field, complaints: $current);
		$before = $this->countBy(field: $field, complaints: $previous);
		arsort($now);

		$rise = [];
		$earlier = [];
		foreach ($now as $value => $count) {
			$earlier[] = ($before[$value] ?? 0);
			$rise[] = $this->flaggedRise(count: $count, before: ($before[$value] ?? 0));
		}

		return ['labels' => array_map('strval', array_keys($now)), 'counts' => array_values($now), 'risePercent' => $rise, 'previousCounts' => $earlier];
	}//end breakdown()

	/**
	 * The rise in percent when it passes the flag, else null.
	 *
	 * @param int $count  This quarter's count.
	 * @param int $before The quarter before's count.
	 *
	 * @return int|null
	 */
	private function flaggedRise(int $count, int $before): ?int {
		if ($before === 0) {
			return null;
		}

		$rise = (int)round((($count - $before) / $before) * 100);
		if ($rise <= self::RISE_FLAG_PERCENT) {
			return null;
		}

		return $rise;
	}//end flaggedRise()

	/**
	 * How many complaints carry each value of a field.
	 *
	 * @param string                     $field      The field.
	 * @param list<array<string, mixed>> $complaints The complaints.
	 *
	 * @return array<string, int>
	 */
	private function countBy(string $field, array $complaints): array {
		$counts = [];
		foreach ($complaints as $complaint) {
			$value = trim((string)($complaint[$field] ?? ''));
			if ($value !== '') {
				$counts[$value] = (($counts[$value] ?? 0) + 1);
			}
		}

		return $counts;
	}//end countBy()

	/**
	 * Complaints received per month, over the twelve months that end with the quarter.
	 *
	 * @param QuarterPeriod              $period     The quarter.
	 * @param list<array<string, mixed>> $complaints The complaints.
	 *
	 * @return array{labels: list<string>, counts: list<int>}
	 */
	private function byMonth(QuarterPeriod $period, array $complaints): array {
		$months = array_fill_keys($period->trailingMonths(), 0);
		foreach ($complaints as $complaint) {
			$month = substr((string)($complaint['receiptDate'] ?? ''), 0, 7);
			if (array_key_exists($month, $months) === true) {
				$months[$month]++;
			}
		}

		return ['labels' => array_keys($months), 'counts' => array_values($months)];
	}//end byMonth()

	/**
	 * How the quarter's complaints were handled, overall and per category.
	 *
	 * @param list<array<string, mixed>>          $current      This quarter's complaints.
	 * @param array<string, array<string, mixed>> $dispositions The final disposition per complaint id.
	 *
	 * @return array{handled: int, withinAwbPercent: int|null, upheldPercent: int|null, averageDays: int|null,
	 *               resolutionByCategory: list<array<string, mixed>>}
	 */
	private function handling(array $current, array $dispositions): array {
		$zero = ['count' => 0, 'handled' => 0, 'days' => 0, 'within' => 0, 'upheld' => 0];
		$total = $zero;
		$perCategory = [];
		foreach ($current as $complaint) {
			$category = trim((string)($complaint['category'] ?? ''));
			$perCategory[$category] = $this->tally(sum: ($perCategory[$category] ?? $zero), complaint: $complaint, dispositions: $dispositions);
			$total = $this->tally(sum: $total, complaint: $complaint, dispositions: $dispositions);
		}

		$rows = [];
		foreach ($perCategory as $category => $sum) {
			if ($category !== '' && $sum['handled'] > 0) {
				$rows[] = [
					'category' => $category,
					'count' => $sum['count'],
					'averageDays' => $this->share(part: $sum['days'], whole: $sum['handled'], scale: 1),
					'upheldPercent' => $this->share(part: $sum['upheld'], whole: $sum['handled'], scale: 100),
				];
			}
		}

		usort($rows, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']));

		return [
			'handled' => $total['handled'],
			'withinAwbPercent' => $this->share(part: $total['within'], whole: $total['handled'], scale: 100),
			'upheldPercent' => $this->share(part: $total['upheld'], whole: $total['handled'], scale: 100),
			'averageDays' => $this->share(part: $total['days'], whole: $total['handled'], scale: 1),
			'resolutionByCategory' => $rows,
		];
	}//end handling()

	/**
	 * One complaint added to a running sum.
	 *
	 * @param array<string, int>                  $sum          The sum so far.
	 * @param array<string, mixed>                $complaint    The complaint.
	 * @param array<string, array<string, mixed>> $dispositions The final disposition per complaint id.
	 *
	 * @return array<string, int>
	 */
	private function tally(array $sum, array $complaint, array $dispositions): array {
		$sum['count']++;
		$disposition = ($dispositions[(string)($complaint['id'] ?? '')] ?? null);
		$received = $this->dayOf(value: (string)($complaint['receiptDate'] ?? ''));
		$closed = $this->dayOf(value: (string)($disposition['closureDate'] ?? ''));
		if ($disposition === null || $received === null || $closed === null) {
			return $sum;
		}

		$sum['handled']++;
		$sum['days'] += $this->days->between(from: $received, to: $closed);
		$sum['within'] += (int)($closed <= $this->deadlineOf(complaint: $complaint, received: $received));
		$sum['upheld'] += (int)in_array((string)($disposition['opinion'] ?? ''), self::UPHELD, true);

		return $sum;
	}//end tally()

	/**
	 * The day a complaint had to be handled by: its own deadline, or the Awb term.
	 *
	 * @param array<string, mixed> $complaint The complaint.
	 * @param DateTimeImmutable    $received  When it came in.
	 *
	 * @return DateTimeImmutable
	 */
	private function deadlineOf(array $complaint, DateTimeImmutable $received): DateTimeImmutable {
		$own = $this->dayOf(value: (string)($complaint['afhandelDeadline'] ?? ''));
		if ($own !== null) {
			return $own;
		}

		return $received->modify('+'.self::AWB_TERM_DAYS.' days');
	}//end deadlineOf()

	/**
	 * A stored date as a day, or null when it is empty or unreadable.
	 *
	 * @param string $value The stored value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function dayOf(string $value): ?DateTimeImmutable {
		if (trim($value) === '') {
			return null;
		}

		$day = date_create_immutable(substr(trim($value), 0, 10));
		if ($day === false) {
			return null;
		}

		return $day;
	}//end dayOf()

	/**
	 * A rounded share, or null when there is nothing to divide by.
	 *
	 * @param int $part  The part.
	 * @param int $whole The whole.
	 * @param int $scale 100 for a percentage, 1 for an average.
	 *
	 * @return int|null
	 */
	private function share(int $part, int $whole, int $scale): ?int {
		if ($whole === 0) {
			return null;
		}

		return (int)round(($part * $scale) / $whole);
	}//end share()
}//end class
