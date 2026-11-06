<?php

/**
 * Unit tests for UrgencyScorer.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\UrgencyProfile;
use OCA\Dossiq\Service\Queue\UrgencyScorer;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The urgency score of one item, asked of the scorer itself.
 *
 * @covers \OCA\Dossiq\Service\Queue\UrgencyScorer
 * @uses \OCA\Dossiq\Service\Queue\UrgencyProfile
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Term\DayCount
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class UrgencyScorerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The warnings the scorer logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A scorer, with or without an administered calendar.
	 *
	 * @param WorkingDayRoll|null $calendar The calendar, or null for none.
	 *
	 * @return UrgencyScorer
	 */
	private function scorer(?WorkingDayRoll $calendar = null): UrgencyScorer {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		return new UrgencyScorer(dates: $this->caseDates(), logger: $logger, calendar: $calendar);
	}//end scorer()

	/**
	 * Monday 13 July 2026.
	 *
	 * @return DateTimeImmutable
	 */
	private function monday(): DateTimeImmutable {
		return new DateTimeImmutable('2026-07-13 10:00');
	}//end monday()

	/**
	 * Each tier starts where the default thresholds put it: overdue, three days, seven days, ten days.
	 *
	 * @return void
	 */
	public function testTheTierFollowsTheWorkingDaysLeft(): void {
		$scorer = $this->scorer();
		$tier   = fn (string $deadline): array => array_slice($scorer->scoreItem($deadline, 'low', null, $this->monday()), 0, 2);

		$this->assertSame(expected: ['deadlineTier' => 'overdue', 'daysUntilDeadline' => -1], actual: $tier('2026-07-10'));
		$this->assertSame(expected: ['deadlineTier' => 'critical', 'daysUntilDeadline' => 0], actual: $tier('2026-07-13'));
		$this->assertSame(expected: ['deadlineTier' => 'critical', 'daysUntilDeadline' => 3], actual: $tier('2026-07-16'));
		$this->assertSame(expected: ['deadlineTier' => 'warning', 'daysUntilDeadline' => 4], actual: $tier('2026-07-17'));
		$this->assertSame(expected: ['deadlineTier' => 'warning', 'daysUntilDeadline' => 7], actual: $tier('2026-07-22'));
		$this->assertSame(expected: ['deadlineTier' => 'inSight', 'daysUntilDeadline' => 8], actual: $tier('2026-07-23'));
		$this->assertSame(expected: ['deadlineTier' => 'inSight', 'daysUntilDeadline' => 10], actual: $tier('2026-07-27'));
		$this->assertSame(expected: ['deadlineTier' => 'normal', 'daysUntilDeadline' => 11], actual: $tier('2026-07-28'));
		$this->assertSame(
			expected: ['deadlineTier' => 'normal', 'daysUntilDeadline' => null],
			actual: array_slice($scorer->scoreItem(null, 'low', null, $this->monday()), 0, 2)
		);
	}//end testTheTierFollowsTheWorkingDaysLeft()

	/**
	 * The score is the tier base less the days left, plus priority steps and capped idle days,
	 * each weighed by the profile.
	 *
	 * @return void
	 */
	public function testTheScoreAddsItsThreeParts(): void {
		$scorer  = $this->scorer();
		$profile = new UrgencyProfile(criticalDays: 1, warningDays: 5, priorityWeight: 4, idleWeight: 0.25);

		$this->assertSame(
			expected: [
				'deadlineTier' => 'warning',
				'daysUntilDeadline' => 4,
				'idleDays' => 12,
				'score' => 511.0,
				'scoreBreakdown' => ['deadline' => 496.0, 'priority' => 12.0, 'idle' => 3.0],
			],
			actual: $scorer->scoreItem('2026-07-17', 'Urgent', '2026-07-01', $this->monday(), $profile)
		);
		$this->assertSame(
			expected: ['deadline' => 0.0, 'priority' => 10.0, 'idle' => 30.0],
			actual: $scorer->scoreItem(null, 'onbekend', '2020-01-01', $this->monday())['scoreBreakdown']
		);
		$this->assertSame(expected: 0, actual: $scorer->scoreItem(null, 'low', '2026-08-01', $this->monday())['idleDays']);
		$this->assertSame(expected: 0.0, actual: $scorer->scoreItem(null, 'low', '2026-08-01', $this->monday())['score']);
	}//end testTheScoreAddsItsThreeParts()

	/**
	 * The administered calendar is asked for the days after today up to the target, and for the
	 * target up to today when the deadline has passed; its count is signed by the direction.
	 *
	 * @return void
	 */
	public function testTheCalendarCountsWhenItAnswers(): void {
		$asked    = [];
		$calendar = $this->createMock(originalClassName: WorkingDayRoll::class);
		$calendar->method('daysBetween')->willReturnCallback(
			static function (DateTimeImmutable $from, DateTimeImmutable $to, string $mode) use (&$asked): int {
				$asked[] = [$from->format('Y-m-d H:i'), $to->format('Y-m-d H:i'), $mode];
				return 2;
			}
		);
		$scorer = $this->scorer(calendar: $calendar);

		$this->assertSame(expected: 2, actual: $scorer->scoreItem('2026-07-17', 'low', null, $this->monday())['daysUntilDeadline']);
		$this->assertSame(expected: -2, actual: $scorer->scoreItem('2026-07-08', 'low', null, $this->monday())['daysUntilDeadline']);
		$this->assertSame(expected: 0, actual: $scorer->scoreItem('2026-07-13', 'low', null, $this->monday())['daysUntilDeadline']);
		$this->assertSame(
			expected: [
				['2026-07-14 00:00', '2026-07-18 00:00', WorkingDayRoll::MODE_WORKING_DAYS],
				['2026-07-08 00:00', '2026-07-13 00:00', WorkingDayRoll::MODE_WORKING_DAYS],
			],
			actual: $asked
		);
		$this->assertSame(expected: [], actual: $this->warnings);
	}//end testTheCalendarCountsWhenItAnswers()

	/**
	 * No calendar, or one that does not answer: Monday to Friday, said once per scorer.
	 *
	 * @return void
	 */
	public function testTheWalkIsTheFallbackAndIsSaidOnce(): void {
		$silent = $this->createMock(originalClassName: WorkingDayRoll::class);
		$silent->method('daysBetween')->willReturn(null);

		foreach ([$this->scorer(), $this->scorer(calendar: $silent)] as $scorer) {
			$this->warnings = [];

			$this->assertSame(expected: 10, actual: $scorer->scoreItem('2026-07-27', 'low', null, $this->monday())['daysUntilDeadline']);
			$this->assertSame(expected: -6, actual: $scorer->scoreItem('2026-07-03', 'low', null, $this->monday())['daysUntilDeadline']);
			$this->assertSame(expected: 0, actual: $scorer->scoreItem('2026-07-12', 'low', null, $this->monday())['daysUntilDeadline']);
			$this->assertCount(1, $this->warnings);
			$this->assertStringContainsString(needle: 'counted Monday to Friday', haystack: $this->warnings[0]);
		}
	}//end testTheWalkIsTheFallbackAndIsSaidOnce()

	/**
	 * A deadline far beyond the walk's cap stops counting instead of looping.
	 *
	 * @return void
	 */
	public function testTheWalkIsBounded(): void {
		$days = $this->scorer()->scoreItem('2099-01-01', 'low', null, $this->monday())['daysUntilDeadline'];

		$this->assertGreaterThan(2600, $days);
		$this->assertLessThan(2620, $days);
	}//end testTheWalkIsBounded()
	/**
	 * Waiting on the resident adds its weight, and never so much that the tier stops deciding first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-tabs-carry-the-settings-board-blocks/specs/tenant-configuration/spec.md
	 */
	public function testWaitingOnTheResidentAddsItsWeightWithinTheTierBand(): void {
		$scorer = $this->scorer();
		$profile = (new UrgencyProfile(priorityWeight: 50, idleWeight: 1.5))->withQueueExtras(inSightDays: 10, residentWaitWeight: 50);
		$scored = $scorer->scoreItem('2026-07-28', 'urgent', '2026-01-01', $this->monday(), $profile);

		$waiting = $scorer->withResidentWait(scoring: $scored, waiting: true, profile: $profile);
		$this->assertSame(expected: 9.0, actual: $waiting['scoreBreakdown']['residentWait']);
		$this->assertSame(expected: round($scored['score'] + 9.0, 2), actual: $waiting['score']);

		$still = $scorer->withResidentWait(scoring: $scored, waiting: false, profile: $profile);
		$this->assertSame(expected: 0.0, actual: $still['scoreBreakdown']['residentWait']);
		$this->assertSame(expected: $scored['score'], actual: $still['score']);
	}//end testWaitingOnTheResidentAddsItsWeightWithinTheTierBand()
}//end class
