<?php

/**
 * Planning a hearing: the art. 7:4 inspection floor, the inspection window, invitee stamps and the attendance freeze.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\Bezwaar\HearingSchedulePlanner;
use OCA\Dossiq\Service\Hearing\HearingPlan;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\HearingSchedulePlanner
 * @uses   \OCA\Dossiq\Service\Hearing\HearingPlan
 */
class HearingSchedulePlannerTest extends TestCase {

	/**
	 * The planner under test.
	 *
	 * @var HearingSchedulePlanner
	 */
	private HearingSchedulePlanner $planner;

	/**
	 * A fresh planner.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->planner = new HearingSchedulePlanner();
	}//end setUp()

	public function testAnUnreadableScheduledDateIsRefusedByName(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Invalid scheduledDate: garbage');
		$this->planner->parseDateTime(value: 'garbage');
	}//end testAnUnreadableScheduledDateIsRefusedByName()

	public function testAnUnreadableInspectionDateFallsBackToNow(): void {
		$before = new DateTimeImmutable();
		$parsed = $this->planner->parseDate(value: 'garbage');

		self::assertGreaterThanOrEqual($before->getTimestamp(), $parsed->getTimestamp());
		self::assertSame('2026-04-02', $this->planner->parseDate(value: '2026-04-02')->format('Y-m-d'));
	}//end testAnUnreadableInspectionDateFallsBackToNow()

	public function testTheInspectionDeadlineIsSevenDaysBeforeTheHearing(): void {
		$deadline = $this->planner->computeInspectionDeadline(scheduled: new DateTimeImmutable('2026-03-10T14:00:00+01:00'));

		self::assertSame('2026-03-03T14:00:00+01:00', $deadline->format(DateTimeInterface::ATOM));
	}//end testTheInspectionDeadlineIsSevenDaysBeforeTheHearing()

	public function testTheInspectionFloorAdmitsExactlySevenDaysAndRefusesLess(): void {
		$today = new DateTimeImmutable('2026-03-01T10:00:00Z');

		$this->planner->guardInspectionFloor(scheduled: new DateTimeImmutable('2026-03-08T10:00:00Z'), today: $today);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Inzagetermijn (art. 7:4) wordt geschonden');
		$this->planner->guardInspectionFloor(scheduled: new DateTimeImmutable('2026-03-08T09:59:59Z'), today: $today);
	}//end testTheInspectionFloorAdmitsExactlySevenDaysAndRefusesLess()

	public function testInspectionIsAvailableFromTodaysMidnightByDefault(): void {
		$available = $this->planner->resolveAvailableFrom(
			payload: [],
			deadline: new DateTimeImmutable('2026-03-20T00:00:00Z'),
			now: new DateTimeImmutable('2026-03-01T15:30:00Z'),
		);

		self::assertSame('2026-03-01T00:00:00+00:00', $available->format(DateTimeInterface::ATOM));
	}//end testInspectionIsAvailableFromTodaysMidnightByDefault()

	public function testAGivenAvailableFromIsKeptUnlessItPassesTheDeadline(): void {
		$deadline = new DateTimeImmutable('2026-03-20');
		$now = new DateTimeImmutable('2026-03-01');

		self::assertSame('2026-03-05', $this->planner->resolveAvailableFrom(payload: ['inspectionAvailableFrom' => '2026-03-05'], deadline: $deadline, now: $now)->format('Y-m-d'));
		self::assertSame($deadline, $this->planner->resolveAvailableFrom(payload: ['inspectionAvailableFrom' => '2026-03-25'], deadline: $deadline, now: $now));
	}//end testAGivenAvailableFromIsKeptUnlessItPassesTheDeadline()

	public function testInviteesAreStampedOnceAndNonRowsDropped(): void {
		$when = new DateTimeImmutable('2026-03-01T09:00:00+00:00');

		$stamped = $this->planner->stampInvitees(
			invitees: [
				['name' => 'Bezwaarmaker'],
				['name' => 'Gemachtigde', 'invitedAt' => '2026-02-20T08:00:00+00:00'],
				['name' => 'Derde', 'invitedAt' => ''],
				'not a row',
			],
			when: $when,
		);

		self::assertSame(
			[
				['name' => 'Bezwaarmaker', 'invitedAt' => '2026-03-01T09:00:00+00:00'],
				['name' => 'Gemachtigde', 'invitedAt' => '2026-02-20T08:00:00+00:00'],
				['name' => 'Derde', 'invitedAt' => '2026-03-01T09:00:00+00:00'],
			],
			$stamped
		);
	}//end testInviteesAreStampedOnceAndNonRowsDropped()

	public function testAScheduledRecordCarriesThePlanAndTheComputedWindow(): void {
		$scheduled = (new DateTimeImmutable('+30 days'))->setTime(10, 0, 0);
		$plan = new HearingPlan(
			caseId: 'case-1',
			chairpersonId: 'voorzitter',
			invitees: [['name' => 'Bezwaarmaker']],
			payload: ['location' => 'Raadzaal', 'status' => 'concept', 'recordingConsent' => 'granted'],
		);

		$record = $this->planner->scheduledRecord(plan: $plan, scheduledDate: $scheduled->format(DateTimeInterface::ATOM));

		self::assertSame('case-1', $record['case']);
		self::assertSame('voorzitter', $record['chairperson']);
		self::assertSame('Raadzaal', $record['location']);
		self::assertNull($record['videoCallUrl']);
		self::assertSame([], $record['members']);
		// The computed fields override whatever the payload carried.
		self::assertSame('planned', $record['status']);
		self::assertFalse($record['hearingWaived']);
		self::assertSame('granted', $record['recordingConsent']);
		self::assertSame($scheduled->modify('-7 days')->format('Y-m-d'), $record['inspectionDeadline']);
		self::assertSame((new DateTimeImmutable())->format('Y-m-d'), $record['inspectionAvailableFrom']);
		self::assertArrayHasKey('invitedAt', $record['invitees'][0]);
	}//end testAScheduledRecordCarriesThePlanAndTheComputedWindow()

	public function testAScheduledRecordDefaultsRecordingConsentToNotRequested(): void {
		$plan = new HearingPlan(caseId: 'case-1', chairpersonId: 'v', invitees: [], payload: []);

		$record = $this->planner->scheduledRecord(plan: $plan, scheduledDate: (new DateTimeImmutable('+10 days'))->format(DateTimeInterface::ATOM));

		self::assertSame('not_requested', $record['recordingConsent']);
	}//end testAScheduledRecordDefaultsRecordingConsentToNotRequested()

	public function testAHearingTooSoonIsNotScheduled(): void {
		$plan = new HearingPlan(caseId: 'case-1', chairpersonId: 'v', invitees: [], payload: []);

		$this->expectException(RuntimeException::class);
		$this->planner->scheduledRecord(plan: $plan, scheduledDate: (new DateTimeImmutable('+3 days'))->format(DateTimeInterface::ATOM));
	}//end testAHearingTooSoonIsNotScheduled()

	public function testAWaiverRecordsTheReasonAndTheChairpersonFallbacks(): void {
		$fields = $this->planner->waiverFields(reason: 'Bezwaarmaker ziet af', payload: ['chairpersonId' => 'v-2', 'invitees' => [['name' => 'x']]]);

		self::assertSame('afgezien', $fields['status']);
		self::assertTrue($fields['hearingWaived']);
		self::assertSame('Bezwaarmaker ziet af', $fields['waiverReason']);
		self::assertSame('v-2', $fields['chairperson']);
		self::assertSame([['name' => 'x']], $fields['invitees']);
		self::assertSame($fields['inspectionAvailableFrom'], $fields['inspectionDeadline']);

		self::assertSame('v-1', $this->planner->waiverFields(reason: 'r', payload: ['chairperson' => 'v-1', 'chairpersonId' => 'v-2'])['chairperson']);
		self::assertSame('system', $this->planner->waiverFields(reason: 'r', payload: [])['chairperson']);
	}//end testAWaiverRecordsTheReasonAndTheChairpersonFallbacks()

	public function testAttendanceFreezesOneHourAfterTheHearing(): void {
		$past = $this->planner->attendanceFreeze(current: ['scheduledDate' => '2026-01-05T10:00:00+00:00']);
		self::assertSame('2026-01-05T11:00:00+00:00', $past['frozenAt']);
		self::assertTrue($past['isFrozen']);

		$future = $this->planner->attendanceFreeze(current: ['scheduledDate' => (new DateTimeImmutable('+2 days'))->format(DateTimeInterface::ATOM)]);
		self::assertFalse($future['isFrozen']);

		// Without a date, the hearing is taken as now: not frozen within the grace hour.
		self::assertFalse($this->planner->attendanceFreeze(current: [])['isFrozen']);
	}//end testAttendanceFreezesOneHourAfterTheHearing()

	public function testTheSeedDateIsTenOClockTwoWeeksOut(): void {
		$seed = new DateTimeImmutable($this->planner->seedDate());

		self::assertSame('10:00:00', $seed->format('H:i:s'));
		self::assertSame((new DateTimeImmutable('+14 days'))->format('Y-m-d'), $seed->format('Y-m-d'));
	}//end testTheSeedDateIsTenOClockTwoWeeksOut()
}//end class
