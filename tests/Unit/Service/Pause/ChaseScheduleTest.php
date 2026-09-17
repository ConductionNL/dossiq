<?php

/**
 * The reminder schedule is arithmetic, and the count is what guards it.
 *
 * The fixture pair the change design names: a 14-day pause, a 5-day interval
 * and a budget of 2 puts reminders on day 5 and day 10, which are 9 and 4 days
 * before the hersteltermijn ends. Both the engine rungs and the daily sweep
 * read that same schedule, so it is checked once here rather than twice there.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Pause
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pause;

use DateTimeImmutable;
use OCA\Dossiq\Service\Pause\ChaseSchedule;
use OCA\Dossiq\Service\Pause\PauseReason;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * REQ-TERM-011: a pause reason carries its own reminder schedule.
 *
 * @covers \OCA\Dossiq\Service\Pause\ChaseSchedule
 * @covers \OCA\Dossiq\Service\Pause\PauseReason
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class ChaseScheduleTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The schedule under test.
	 *
	 * @var ChaseSchedule
	 */
	private ChaseSchedule $schedule;

	/**
	 * Build the schedule on the app's own working calendar.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schedule = new ChaseSchedule(
			calendar: new WorkingDayCalculator(),
			dates: $this->caseDates(),
		);
	}//end setUp()

	/**
	 * One reason, declared the way a case type declares it.
	 *
	 * @param array<string, mixed> $overrides What this case needs different.
	 *
	 * @return array<string, mixed> The normalised reason.
	 */
	private function reason(array $overrides = []): array {
		return PauseReason::normalise(
			row: array_merge(
				[
					'key' => 'aanvulling-aanvrager',
					'name' => 'Aanvulling gevraagd',
					'category' => 'applicant',
					'legalBasis' => 'Awb 4:5',
					'chaseIntervalDays' => 5,
					'chaseBudget' => 2,
					'countsWorkingDays' => false,
					'chaseText' => 'Wij hebben uw aanvulling nog niet ontvangen.',
					'escalateTo' => 'handler',
				],
				$overrides
			)
		);
	}//end reason()

	/**
	 * The fixture pair: 14 days, interval 5, budget 2 puts the rungs at 9 and 4
	 * days before the pause ends.
	 *
	 * @return void
	 */
	public function testTheRungOffsetsForAFourteenDayPause(): void {
		$this->assertSame([9, 4], $this->schedule->rungOffsets(reason: $this->reason(), durationDays: 14));
	}//end testTheRungOffsetsForAFourteenDayPause()

	/**
	 * A reminder that would land on or after the day the pause ends is not a
	 * reminder: it is the expiry the helper already fires.
	 *
	 * @return void
	 */
	public function testARungThatWouldLandOnTheExpiryIsDropped(): void {
		$this->assertSame([5], $this->schedule->rungOffsets(reason: $this->reason(), durationDays: 10));
		$this->assertSame([], $this->schedule->rungOffsets(reason: $this->reason(), durationDays: 5));
	}//end testARungThatWouldLandOnTheExpiryIsDropped()

	/**
	 * A reason that declares no text, no interval or no budget chases nobody,
	 * and arms nothing.
	 *
	 * @return void
	 */
	public function testAReasonWithoutAScheduleChasesNobody(): void {
		$this->assertFalse(PauseReason::chases(reason: $this->reason(['chaseText' => ''])));
		$this->assertFalse(PauseReason::chases(reason: $this->reason(['chaseIntervalDays' => 0])));
		$this->assertFalse(PauseReason::chases(reason: $this->reason(['chaseBudget' => 0])));
		$this->assertSame(
			[],
			$this->schedule->rungOffsets(reason: $this->reason(['chaseText' => '']), durationDays: 14)
		);
	}//end testAReasonWithoutAScheduleChasesNobody()

	/**
	 * The first reminder is counted from the pause start.
	 *
	 * @return void
	 */
	public function testTheFirstReminderIsCountedFromThePauseStart(): void {
		$instance = ['pauzeStartDatum' => '2026-09-01', 'chasesSent' => 0];

		$this->assertSame(
			'2026-09-06',
			$this->schedule->nextChaseOn(instance: $instance, reason: $this->reason())?->format('Y-m-d')
		);
		$this->assertFalse(
			$this->schedule->chaseDue(
				instance: $instance,
				reason: $this->reason(),
				now: new DateTimeImmutable('2026-09-05')
			)
		);
		$this->assertTrue(
			$this->schedule->chaseDue(
				instance: $instance,
				reason: $this->reason(),
				now: new DateTimeImmutable('2026-09-06')
			)
		);
	}//end testTheFirstReminderIsCountedFromThePauseStart()

	/**
	 * A later reminder is counted from the last one, not from the start, so a
	 * reminder that went out late does not drag the next one forward.
	 *
	 * @return void
	 */
	public function testALaterReminderIsCountedFromTheLastOne(): void {
		$instance = [
			'pauzeStartDatum' => '2026-09-01',
			'chasesSent' => 1,
			'lastChasedAt' => '2026-09-09T09:00:00+02:00',
		];

		$this->assertSame(
			'2026-09-14',
			$this->schedule->nextChaseOn(instance: $instance, reason: $this->reason())?->format('Y-m-d')
		);
	}//end testALaterReminderIsCountedFromTheLastOne()

	/**
	 * A working-day interval skips the weekend: five working days from Tuesday
	 * 1 September 2026 is Tuesday the 8th, not Sunday the 6th.
	 *
	 * @return void
	 */
	public function testAWorkingDayIntervalSkipsTheWeekend(): void {
		$instance = ['pauzeStartDatum' => '2026-09-01', 'chasesSent' => 0];

		$this->assertSame(
			'2026-09-08',
			$this->schedule->nextChaseOn(
				instance: $instance,
				reason: $this->reason(['countsWorkingDays' => true])
			)?->format('Y-m-d')
		);
	}//end testAWorkingDayIntervalSkipsTheWeekend()

	/**
	 * The budget is a ceiling: once it is spent, no further reminder is due
	 * however long the pause runs on.
	 *
	 * @return void
	 */
	public function testNoReminderIsDueOnceTheBudgetIsSpent(): void {
		$instance = [
			'pauzeStartDatum' => '2026-09-01',
			'chasesSent' => 2,
			'lastChasedAt' => '2026-09-11T09:00:00+02:00',
		];

		$this->assertNull($this->schedule->nextChaseOn(instance: $instance, reason: $this->reason()));
		$this->assertFalse(
			$this->schedule->chaseDue(
				instance: $instance,
				reason: $this->reason(),
				now: new DateTimeImmutable('2026-10-01')
			)
		);
	}//end testNoReminderIsDueOnceTheBudgetIsSpent()

	/**
	 * The escalation waits one more interval after the last reminder, and
	 * happens once.
	 *
	 * @return void
	 */
	public function testTheEscalationWaitsOneIntervalAndHappensOnce(): void {
		$instance = [
			'pauzeStartDatum' => '2026-09-01',
			'chasesSent' => 2,
			'lastChasedAt' => '2026-09-11T09:00:00+02:00',
		];

		$this->assertFalse(
			$this->schedule->escalationDue(
				instance: $instance,
				reason: $this->reason(),
				now: new DateTimeImmutable('2026-09-15')
			)
		);
		$this->assertTrue(
			$this->schedule->escalationDue(
				instance: $instance,
				reason: $this->reason(),
				now: new DateTimeImmutable('2026-09-16')
			)
		);

		$instance['chaseEscalatedAt'] = '2026-09-16T09:00:00+02:00';
		$this->assertFalse(
			$this->schedule->escalationDue(
				instance: $instance,
				reason: $this->reason(),
				now: new DateTimeImmutable('2026-09-30')
			)
		);
	}//end testTheEscalationWaitsOneIntervalAndHappensOnce()

	/**
	 * A reason that only categorises itself still lands in the right queue
	 * bucket, and a budget typed as a wild number is capped.
	 *
	 * @return void
	 */
	public function testAReasonInfersItsPartyAndCapsItsBudget(): void {
		$third = PauseReason::normalise(row: ['key' => 'advies', 'category' => 'thirdParty']);
		$internal = PauseReason::normalise(row: ['key' => 'intern', 'category' => 'internal']);
		$wild = PauseReason::normalise(row: ['key' => 'x', 'chaseBudget' => 500]);

		$this->assertSame('thirdParty', $third['waitingOn']);
		$this->assertSame('us', $internal['waitingOn']);
		$this->assertSame(PauseReason::MAX_BUDGET, $wild['chaseBudget']);
	}//end testAReasonInfersItsPartyAndCapsItsBudget()
}//end class
