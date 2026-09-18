<?php

/**
 * When the request arrived, and when its clock starts.
 *
 * The failure this watches for is the one the row was opened on: a citizen
 * counting eight weeks from the moment they pressed send while the
 * municipality counts eight weeks from Monday. So the assertions are about the
 * RELATION between the two moments, and about the flag that says whether they
 * differ, rather than about either moment on its own.
 *
 * The third assertion is the one that is easy to leave out. A working calendar
 * that cannot be reached does not know whether the week was open, so stamping
 * `receivedOutsideWorkingHours: false` there would put a claim on the record
 * that nobody computed, and the ontvangstbevestiging would then quote a start
 * date the term does not use. Nothing is stamped instead.
 *
 * MUTATION-CHECKED 2026-09-18: making `stampFor()` fall back to the received
 * moment when the calendar answers null reddens
 * testAnUnreachableCalendarStampsNothing and testACalendarAnsweringEarlierIsRefused
 * on their empty-array assertions, and changing the flag to compare DATES
 * instead of instants reddens testAnEarlyMorningFilingOnTheSameDayIsStillOutside.
 * Restored after. The second mutation is invisible to every OTHER test here,
 * and that is why the same-day case exists rather than being left to the day
 * an intra-day window makes it reachable in production.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Intake\IntakeTermStart;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use PHPUnit\Framework\TestCase;

/**
 * The stamp a case is given at intake.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeTermStart
 * @covers \OCA\Dossiq\Service\Termijn\WorkingDayRoll::firstWorkingMomentAtOrAfter
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */
class IntakeTermStartTest extends TestCase {

	/**
	 * A Sunday filing starts on the Monday, and says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function testASundayFilingStartsOnMonday(): void {
		$sunday = new DateTimeImmutable('2026-03-08T20:14:00+01:00');
		$monday = new DateTimeImmutable('2026-03-09T09:00:00+01:00');

		$stamp = $this->intake(answering: $monday)->stampFor(receivedAt: $sunday);

		self::assertSame($sunday->format('c'), $stamp['receivedAt'], 'What they did is recorded as when they did it.');
		self::assertSame($monday->format('c'), $stamp['termStartsAt']);
		self::assertTrue(
			$stamp['receivedOutsideWorkingHours'],
			'Without this flag nothing anywhere says the two moments are different.',
		);
	}//end testASundayFilingStartsOnMonday()

	/**
	 * A filing inside the working week starts at once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function testAFilingInsideTheWorkingWeekStartsAtOnce(): void {
		$tuesday = new DateTimeImmutable('2026-03-10T10:00:00+01:00');

		$stamp = $this->intake(answering: $tuesday)->stampFor(receivedAt: $tuesday);

		self::assertSame($stamp['receivedAt'], $stamp['termStartsAt'], 'The clock started when they pressed send.');
		self::assertFalse(
			$stamp['receivedOutsideWorkingHours'],
			'An explanation on every confirmation teaches people to stop reading them.',
		);
	}//end testAFilingInsideTheWorkingWeekStartsAtOnce()

	/**
	 * Two moments on the same DAY still count as outside the working hours.
	 *
	 * Not reachable today and written anyway: the engine calendar holds
	 * working weekdays and non-working dates and no intra-day window, so its
	 * answer currently always lands on a different date when it moves at all.
	 * The window is openregister `working-calendar-admin`, and the day it
	 * ships a request filed at seven in the morning will be placed at nine on
	 * the SAME day. A flag comparing dates rather than instants answers false
	 * there, tells that applicant the clock started when they pressed send,
	 * and nothing anywhere reports it. This is the assertion that fails first
	 * when somebody simplifies the comparison.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function testAnEarlyMorningFilingOnTheSameDayIsStillOutside(): void {
		$sevenAm = new DateTimeImmutable('2026-03-10T07:00:00+01:00');
		$nineAm = new DateTimeImmutable('2026-03-10T09:00:00+01:00');

		$stamp = $this->intake(answering: $nineAm)->stampFor(receivedAt: $sevenAm);

		self::assertSame($nineAm->format('c'), $stamp['termStartsAt']);
		self::assertTrue(
			$stamp['receivedOutsideWorkingHours'],
			'Same day, different moment: a flag comparing dates would say the clock started at seven.',
		);
	}//end testAnEarlyMorningFilingOnTheSameDayIsStillOutside()

	/**
	 * A calendar that does not answer stamps nothing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function testAnUnreachableCalendarStampsNothing(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$intake = new IntakeTermStart(calendar: new WorkingDayRoll(settings: $settings));

		self::assertSame(
			[],
			$intake->stampFor(receivedAt: new DateTimeImmutable('2026-03-08T20:14:00+01:00')),
			'An absent stamp is visibly absent; a false flag is confidently wrong.',
		);
	}//end testAnUnreachableCalendarStampsNothing()

	/**
	 * A calendar answering before the moment asked about is refused.
	 *
	 * A start before the request arrived would count days against a citizen
	 * that they had not yet used.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function testACalendarAnsweringEarlierIsRefused(): void {
		$sunday = new DateTimeImmutable('2026-03-08T20:14:00+01:00');
		$friday = new DateTimeImmutable('2026-03-06T09:00:00+01:00');

		self::assertSame([], $this->intake(answering: $friday)->stampFor(receivedAt: $sunday));
	}//end testACalendarAnsweringEarlierIsRefused()

	/**
	 * A case that already carries the stamp is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function testAStampedCaseIsNotStampedAgain(): void {
		$intake = $this->intake(answering: new DateTimeImmutable('2026-03-09T09:00:00+01:00'));

		self::assertTrue($intake->isStamped(case: ['termStartsAt' => '2026-01-05T09:00:00+01:00']));
		self::assertFalse($intake->isStamped(case: ['receivedAt' => '2026-01-04T20:00:00+01:00']));
		self::assertFalse($intake->isStamped(case: ['termStartsAt' => '   ']));
	}//end testAStampedCaseIsNotStampedAgain()

	/**
	 * The arrival moment is read off the case before anything is invented.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#requirement-a-case-records-when-it-arrived-and-when-its-clock-starts-req-term-040
	 */
	public function testTheArrivalMomentComesFromTheCase(): void {
		$intake = $this->intake(answering: new DateTimeImmutable('2026-03-09T09:00:00+01:00'));

		self::assertSame(
			'2026-03-08T20:14:00+01:00',
			$intake->arrivalOf(case: ['registrationDate' => '2026-03-08T20:14:00+01:00'])->format('c'),
		);
		self::assertSame(
			'2026-02-02T08:00:00+01:00',
			$intake->arrivalOf(case: ['@self' => ['created' => '2026-02-02T08:00:00+01:00']])->format('c'),
			'The platform\'s own creation moment is the fallback, not the moment this ran.',
		);
	}//end testTheArrivalMomentComesFromTheCase()

	/**
	 * The subject, wired to a calendar that answers one instant.
	 *
	 * @param DateTimeImmutable $answering What the engine calendar answers.
	 *
	 * @return IntakeTermStart The subject.
	 */
	private function intake(DateTimeImmutable $answering): IntakeTermStart {
		return new IntakeTermStart(calendar: $this->roll(answering: $answering));
	}//end intake()

	/**
	 * A roll around a calculator that answers a fixed instant.
	 *
	 * Not a PHPUnit double: the engine class is not on this repository's
	 * classpath, so `createMock()` has nothing to mock. The seam resolves BY
	 * NAME, which is the production behaviour, so the classes are aliased into
	 * existence for this process, exactly as WorkingDayRollTest does it.
	 *
	 * @param DateTimeImmutable $answering What the calculator answers.
	 *
	 * @return WorkingDayRoll The roll.
	 */
	private function roll(DateTimeImmutable $answering): WorkingDayRoll {
		if (class_exists(WorkingDayRoll::ENGINE_CALCULATOR) === false) {
			class_alias(FakeIntakeCalculator::class, WorkingDayRoll::ENGINE_CALCULATOR);
		}

		if (class_exists(WorkingDayRoll::ENGINE_CALENDARS) === false) {
			class_alias(FakeIntakeCalendars::class, WorkingDayRoll::ENGINE_CALENDARS);
		}

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use ($answering): object {
				if ($class === WorkingDayRoll::ENGINE_CALCULATOR) {
					return new FakeIntakeCalculator(answering: $answering);
				}

				return new FakeIntakeCalendars();
			}
		);

		return new WorkingDayRoll(settings: $settings);
	}//end roll()
}//end class

/**
 * Stands in for OpenRegister's SlaCalculator.
 */
class FakeIntakeCalculator {

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $answering What to answer.
	 */
	public function __construct(private readonly DateTimeImmutable $answering) {
	}//end __construct()

	/**
	 * Answer the fixed instant.
	 *
	 * @param \DateTimeInterface $from     The moment asked about.
	 * @param float              $value    The amount, zero for the walk.
	 * @param string             $unit     The unit.
	 * @param object             $calendar The calendar.
	 *
	 * @return DateTimeImmutable The answer.
	 */
	public function add(\DateTimeInterface $from, float $value, string $unit, object $calendar): DateTimeImmutable {
		return $this->answering;
	}//end add()
}//end class

/**
 * Stands in for OpenRegister's WorkingCalendarService.
 */
class FakeIntakeCalendars {

	/**
	 * Answer a calendar object.
	 *
	 * @param string|null $slug         The calendar slug.
	 * @param string|null $organisation The organisation.
	 *
	 * @return object The calendar.
	 */
	public function resolve(?string $slug, ?string $organisation): object {
		return new \stdClass();
	}//end resolve()
}//end class
