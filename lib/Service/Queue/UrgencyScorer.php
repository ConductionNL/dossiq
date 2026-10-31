<?php

/**
 * Scores one work queue item
 *
 * The deadline tier comes from the working days left, counted on the
 * administered calendar when it answers and Monday to Friday when it does not.
 * The priority and the days an item has lain still add to the score inside a tier.
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
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Term\DayCount;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use Psr\Log\LoggerInterface;

/**
 * The urgency score of one item. No store is read.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class UrgencyScorer {
	/**
	 * Deadline tier (termijnstatus) constants.
	 */
	private const DEADLINE_TIER_OVERDUE = 'overdue';
	private const DEADLINE_TIER_CRITICAL = 'critical';
	private const DEADLINE_TIER_WARNING = 'warning';
	private const DEADLINE_TIER_NORMAL = 'normal';

	/**
	 * Base score per deadline tier. Higher tiers score higher; the deadline
	 * component further differentiates within a tier by exact day count.
	 * The 250 points between two bases are more than the priority and idle
	 * parts can add together (see UrgencyProfile's bounds), so the tier
	 * always decides first.
	 *
	 * @var array<string, float>
	 */
	private const DEADLINE_TIER_BASE_SCORE = [
		self::DEADLINE_TIER_OVERDUE => 1000.0,
		self::DEADLINE_TIER_CRITICAL => 750.0,
		self::DEADLINE_TIER_WARNING => 500.0,
		self::DEADLINE_TIER_NORMAL => 250.0,
	];

	/**
	 * Priority steps; the profile's priority weight is paid per step.
	 *
	 * @var array<string, int>
	 */
	private const PRIORITY_POINTS = [
		'urgent' => 3,
		'high' => 2,
		'normal' => 1,
		'low' => 0,
	];

	/**
	 * Fallback priority step for an unknown/empty priority value.
	 */
	private const DEFAULT_PRIORITY_POINTS = 1;

	/**
	 * Idle component: days lying still count up to this cap.
	 */
	private const MAX_IDLE_DAYS = 60;

	/**
	 * Safety cap on the business-day walk in businessDaysBetween(), so a
	 * corrupt/far-future deadline can never loop unbounded.
	 */
	private const MAX_BUSINESS_DAY_WALK = 3660;

	/**
	 * Whether the Monday-to-Friday fallback has been said in the log already.
	 *
	 * @var bool
	 */
	private bool $saidFallback = false;

	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param LoggerInterface $logger Logger.
	 * @param WorkingDayRoll|null $calendar The administered calendar the business days are
	 *        counted on (REQ-OTE-04), or null to count Monday to Friday and say so once.
	 * @param DayCount $days The one place days between two dates are counted.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
		private readonly ?WorkingDayRoll $calendar = null,
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * Score a single item deterministically. Pure function, no I/O.
	 *
	 * The deadline tier comes from the working days left against the
	 * profile's thresholds; the score adds the priority and idle parts the
	 * profile weighs. The reference date is the last activity on the item:
	 * the idle part counts the calendar days since it, capped, and a moment in
	 * the future counts as today.
	 *
	 * @param string|null $deadline Resolved deadline (Y-m-d or parseable date), or null.
	 * @param string $priority Priority value (low/normal/high/urgent), any casing.
	 * @param string|null $referenceDate The last activity on the item, or null for no idle part.
	 * @param DateTimeImmutable $now The "now" instant the score is computed against.
	 * @param UrgencyProfile|null $profile Thresholds and weights; null scores with the defaults.
	 *
	 * @return array{
	 *     deadlineTier: string,
	 *     daysUntilDeadline: int|null,
	 *     idleDays: int|null,
	 *     score: float,
	 *     scoreBreakdown: array{deadline: float, priority: float, idle: float}
	 * } Score result.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function scoreItem(
		?string $deadline,
		string $priority,
		?string $referenceDate,
		DateTimeImmutable $now,
		?UrgencyProfile $profile = null,
	): array {
		$profile = ($profile ?? new UrgencyProfile());
		$today = new DateTimeImmutable($now->format('Y-m-d'));

		$daysUntilDeadline = null;
		$deadlineTier = self::DEADLINE_TIER_NORMAL;
		$deadlineComponent = 0.0;

		$deadlineDate = $this->dates->tryParse($this->dates->toCalendarDateOrNull($deadline));
		if ($deadlineDate !== null) {
			$daysUntilDeadline = $this->businessDaysBetween(today: $today, target: $deadlineDate);
			$deadlineTier = $this->deadlineTierFor(daysUntilDeadline: $daysUntilDeadline, profile: $profile);
			$deadlineComponent = (self::DEADLINE_TIER_BASE_SCORE[$deadlineTier] - $daysUntilDeadline);
		}

		$priorityKey = strtolower(trim($priority));
		$priorityComponent = ((self::PRIORITY_POINTS[$priorityKey] ?? self::DEFAULT_PRIORITY_POINTS) * $profile->priorityWeight);

		$idleDays = null;
		$idleComponent = 0.0;
		$referenceParsed = $this->dates->tryParse($this->dates->toCalendarDateOrNull($referenceDate));
		if ($referenceParsed !== null) {
			$idleDays = 0;
			if ($referenceParsed < $today) {
				$idleDays = $this->days->between(from: $today, to: $referenceParsed);
			}

			$idleComponent = (min($idleDays, self::MAX_IDLE_DAYS) * $profile->idleWeight);
		}

		$score = ($deadlineComponent + $priorityComponent + $idleComponent);

		return [
			'deadlineTier' => $deadlineTier,
			'daysUntilDeadline' => $daysUntilDeadline,
			'idleDays' => $idleDays,
			'score' => round($score, 2),
			'scoreBreakdown' => [
				'deadline' => round($deadlineComponent, 2),
				'priority' => round($priorityComponent, 2),
				'idle' => round($idleComponent, 2),
			],
		];
	}//end scoreItem()

	/**
	 * Determine the deadline tier for a given business-day offset.
	 *
	 * @param int $daysUntilDeadline Signed business-day offset (negative = overdue).
	 * @param UrgencyProfile $profile The thresholds to hold it against.
	 *
	 * @return string One of the DEADLINE_TIER_* constants.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	private function deadlineTierFor(int $daysUntilDeadline, UrgencyProfile $profile): string {
		if ($daysUntilDeadline < 0) {
			return self::DEADLINE_TIER_OVERDUE;
		}

		if ($daysUntilDeadline <= $profile->criticalDays) {
			return self::DEADLINE_TIER_CRITICAL;
		}

		if ($daysUntilDeadline <= $profile->warningDays) {
			return self::DEADLINE_TIER_WARNING;
		}

		return self::DEADLINE_TIER_NORMAL;
	}//end deadlineTierFor()

	/**
	 * Count signed business days (Mon–Fri) between two dates.
	 *
	 * Returns 0 when the dates are the same calendar day, a positive count
	 * when `target` is in the future, negative when in the past. Weekend
	 * days are never counted. Bounded by MAX_BUSINESS_DAY_WALK to guard
	 * against pathological input.
	 *
	 * @param DateTimeImmutable $today The reference "today" (date-only).
	 * @param DateTimeImmutable $target The target date (date-only).
	 *
	 * @return int Signed business-day offset.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijnbewaking-schemas/spec.md#requirement-lead-times-and-the-work-queue-count-working-days-on-the-administered-calendar-req-ote-04
	 */
	private function businessDaysBetween(DateTimeImmutable $today, DateTimeImmutable $target): int {
		if ($today->format('Y-m-d') === $target->format('Y-m-d')) {
			return 0;
		}

		$direction = 1;
		if ($target < $today) {
			$direction = -1;
		}

		// THE ADMINISTERED CALENDAR FIRST (REQ-TERM-026, REQ-OTE-04). A closure
		// day the organisation declared is not a working day, and Koningsdag
		// is not one either, which a Monday-to-Friday walk counts as both.
		// The same days the walk below counts: forward the days after today up
		// to and including the target, backward the target up to today. The
		// engine counts the working days in [from, to), so the bounds shift.
		$from = $target->setTime(0, 0);
		$to = $today->setTime(0, 0);
		if ($direction > 0) {
			$from = $today->setTime(0, 0)->modify('+1 day');
			$to = $target->setTime(0, 0)->modify('+1 day');
		}

		$counted = $this->administeredBusinessDays(earlier: $from, later: $to);
		if ($counted !== null) {
			return ($counted * $direction);
		}

		$cursor = $today;
		$count = 0;
		$walked = 0;

		while ($cursor->format('Y-m-d') !== $target->format('Y-m-d') && $walked < self::MAX_BUSINESS_DAY_WALK) {
			$step = '+1 day';
			if ($direction < 0) {
				$step = '-1 day';
			}

			$cursor = $cursor->modify($step);
			$dow = (int)$cursor->format('N');
			if ($dow < 6) {
				$count++;
			}

			$walked++;
		}

		return ($count * $direction);
	}//end businessDaysBetween()

	/**
	 * The working days between two days on the administered calendar, or null.
	 *
	 * Null when no calendar is wired or it does not answer; the caller then
	 * walks Monday to Friday, and this says so once per request.
	 *
	 * @param DateTimeImmutable $earlier The earlier day.
	 * @param DateTimeImmutable $later   The later day.
	 *
	 * @return int|null The unsigned count, or null.
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijnbewaking-schemas/spec.md#requirement-lead-times-and-the-work-queue-count-working-days-on-the-administered-calendar-req-ote-04
	 */
	private function administeredBusinessDays(DateTimeImmutable $earlier, DateTimeImmutable $later): ?int {
		$counted = $this->calendar?->daysBetween(from: $earlier, to: $later, mode: WorkingDayRoll::MODE_WORKING_DAYS);
		if ($counted !== null) {
			return $counted;
		}

		if ($this->saidFallback === false) {
			$this->saidFallback = true;
			$this->logger->warning(
				'Dossiq work queue: the organisation calendar did not answer, so days until a deadline '
				. 'were counted Monday to Friday, without holidays or closure days'
			);
		}

		return null;
	}//end administeredBusinessDays()
}//end class
