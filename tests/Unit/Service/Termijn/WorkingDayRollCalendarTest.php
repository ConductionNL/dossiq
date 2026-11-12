<?php

/**
 * How WorkingDayRoll degrades when the organisation calendar is missing,
 * incomplete or refuses an answer.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the calendar questions and failure paths of WorkingDayRoll.
 *
 * @covers \OCA\Dossiq\Service\Termijn\WorkingDayRoll
 * @uses   \OCA\Dossiq\Service\SettingsService
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 */
class WorkingDayRollCalendarTest extends TestCase {

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Make sure the engine class names exist, so the roll asks the settings for them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (class_exists(WorkingDayRoll::ENGINE_CALCULATOR) === false) {
			class_alias(ScriptedCalculator::class, WorkingDayRoll::ENGINE_CALCULATOR);
		}

		if (class_exists(WorkingDayRoll::ENGINE_CALENDARS) === false) {
			class_alias(ScriptedCalendars::class, WorkingDayRoll::ENGINE_CALENDARS);
		}
	}//end setUp()

	/**
	 * A roll whose settings hand out the given calculator and calendar service.
	 *
	 * @param object|null $calculator The calculator, or null for none.
	 * @param object|null $calendars  The calendar service, or null for none.
	 * @param int|null    $lookups    Expected number of engine lookups, or null to not check.
	 *
	 * @return WorkingDayRoll The roll.
	 */
	private function roll(?object $calculator, ?object $calendars, ?int $lookups = null): WorkingDayRoll {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$expect   = $this->any();
		if ($lookups !== null) {
			$expect = $this->exactly(count: $lookups);
		}

		$settings->expects($expect)->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use ($calculator, $calendars): ?object {
				if ($class === WorkingDayRoll::ENGINE_CALCULATOR) {
					return $calculator;
				}

				return $calendars;
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new WorkingDayRoll(settings: $settings, logger: $logger);
	}//end roll()

	/**
	 * A roll over a scripted calendar.
	 *
	 * @param object $calendar The calendar.
	 * @param ScriptedCalculator|null $calculator The calculator, or a default one.
	 *
	 * @return WorkingDayRoll The roll.
	 */
	private function over(object $calendar, ?ScriptedCalculator $calculator = null): WorkingDayRoll {
		return $this->roll(calculator: $calculator ?? new ScriptedCalculator(), calendars: new ScriptedCalendars(calendar: $calendar));
	}//end over()

	/**
	 * The engine is looked up once, however often the roll is asked.
	 *
	 * @return void
	 */
	public function testTheEngineIsResolvedOnce(): void {
		$roll = $this->roll(calculator: new ScriptedCalculator(), calendars: new ScriptedCalendars(calendar: new ScriptedCalendar()), lookups: 2);

		$this->assertTrue(condition: $roll->isAvailable());
		$this->assertTrue(condition: $roll->isAvailable());
		$this->assertSame(expected: [1, 2, 3, 4, 5], actual: $roll->workingWeekdays());
	}//end testTheEngineIsResolvedOnce()

	/**
	 * A calculator without a calendar service leaves the roll unavailable.
	 *
	 * @return void
	 */
	public function testACalculatorWithoutCalendarsIsUnavailable(): void {
		$roll = $this->roll(calculator: new ScriptedCalculator(), calendars: null);

		$this->assertFalse(condition: $roll->isAvailable());
		$this->assertNull(actual: $roll->worksOn(new DateTimeImmutable('2026-09-10')));
		$this->assertNull(actual: $roll->workingWeekdays());
	}//end testACalculatorWithoutCalendarsIsUnavailable()

	/**
	 * A calendar service that cannot resolve the calendar leaves the roll unavailable, with a warning.
	 *
	 * @return void
	 */
	public function testAFailingCalendarResolutionIsUnavailable(): void {
		$roll = $this->roll(calculator: new ScriptedCalculator(), calendars: new ScriptedCalendars(calendar: null));

		$this->assertFalse(condition: $roll->isAvailable());
		$this->assertSame(
			expected: [['Dossiq termijn: the organisation calendar is unavailable', ['error' => 'no calendar']]],
			actual: $this->warnings
		);
	}//end testAFailingCalendarResolutionIsUnavailable()

	/**
	 * The calendar's answer to a working-day question is passed on as a bool.
	 *
	 * @return void
	 */
	public function testWorksOnFollowsTheCalendar(): void {
		$roll = $this->over(calendar: new ScriptedCalendar());

		$this->assertTrue(condition: $roll->worksOn(new DateTimeImmutable('2026-09-10')));
		$this->assertFalse(condition: $roll->worksOn(new DateTimeImmutable('2026-09-12')));
	}//end testWorksOnFollowsTheCalendar()

	/**
	 * A refused working-day question is no answer, with a warning.
	 *
	 * @return void
	 */
	public function testARefusedWorkingDayQuestionIsNull(): void {
		$roll = $this->over(calendar: new ScriptedCalendar(refuses: true));

		$this->assertNull(actual: $roll->worksOn(new DateTimeImmutable('2026-09-10')));
		$this->assertSame(
			expected: [['Dossiq termijn: the organisation calendar refused a working-day question', ['error' => 'calendar refused']]],
			actual: $this->warnings
		);
	}//end testARefusedWorkingDayQuestionIsNull()

	/**
	 * Working weekdays come back as a list of ints.
	 *
	 * @return void
	 */
	public function testWorkingWeekdaysAreAListOfInts(): void {
		$roll = $this->over(calendar: new ScriptedCalendar(weekdays: [3 => '2', 7 => '4']));

		$this->assertSame(expected: [2, 4], actual: $roll->workingWeekdays());
	}//end testWorkingWeekdaysAreAListOfInts()

	/**
	 * No weekdays, or something other than a list, is no answer.
	 *
	 * @return void
	 */
	public function testEmptyOrMalformedWeekdaysAreNull(): void {
		$this->assertNull(actual: $this->over(calendar: new ScriptedCalendar(weekdays: []))->workingWeekdays());
		$this->assertNull(actual: $this->over(calendar: new ScriptedCalendar(weekdays: 'mon-fri'))->workingWeekdays());
	}//end testEmptyOrMalformedWeekdaysAreNull()

	/**
	 * A calendar that does not expose its weekdays is no answer, without a warning.
	 *
	 * @return void
	 */
	public function testACalendarWithoutWeekdaysIsNull(): void {
		$roll = $this->over(calendar: new WeekdaylessCalendar());

		$this->assertTrue(condition: $roll->isAvailable());
		$this->assertNull(actual: $roll->workingWeekdays());
		$this->assertSame(expected: [], actual: $this->warnings);
	}//end testACalendarWithoutWeekdaysIsNull()

	/**
	 * Refused weekdays are no answer, with a warning.
	 *
	 * @return void
	 */
	public function testRefusedWeekdaysAreNull(): void {
		$roll = $this->over(calendar: new ScriptedCalendar(refuses: true));

		$this->assertNull(actual: $roll->workingWeekdays());
		$this->assertSame(expected: 'Dossiq termijn: the organisation calendar refused its working weekdays', actual: $this->warnings[0][0]);
	}//end testRefusedWeekdaysAreNull()

	/**
	 * A refused working-day end date is no answer; the lead-time fallback counts calendar days instead.
	 *
	 * @return void
	 */
	public function testARefusedEndDateFallsBackToCalendarDays(): void {
		$roll  = $this->over(calendar: new ScriptedCalendar(), calculator: new ScriptedCalculator(refuses: true));
		$start = new DateTimeImmutable('2026-09-10T09:00:00+02:00');

		$this->assertNull(actual: $roll->endAfter(start: $start, days: 5, mode: WorkingDayRoll::MODE_WORKING_DAYS));
		$this->assertSame(
			expected: '2026-09-15',
			actual: $roll->endAfterOrCalendarDays(start: $start, days: 5, mode: WorkingDayRoll::MODE_WORKING_DAYS)->format('Y-m-d')
		);
		$this->assertSame(expected: 'Dossiq termijn: the organisation calendar refused a working-day end date', actual: $this->warnings[0][0]);
		$this->assertSame(expected: ['days' => 5], actual: $this->warnings[array_key_last(array: $this->warnings)][1]);
	}//end testARefusedEndDateFallsBackToCalendarDays()

	/**
	 * An answered end date is used as is, without a warning, and asks the calculator in business days.
	 *
	 * @return void
	 */
	public function testAnAnsweredEndDateIsUsed(): void {
		$calculator = new ScriptedCalculator(answer: new DateTimeImmutable('2026-09-21T09:00:00+02:00'));
		$roll       = $this->over(calendar: new ScriptedCalendar(), calculator: $calculator);

		$end = $roll->endAfterOrCalendarDays(start: new DateTimeImmutable('2026-09-10T09:00:00+02:00'), days: 7, mode: WorkingDayRoll::MODE_WORKING_DAYS);

		$this->assertSame(expected: '2026-09-21', actual: $end->format('Y-m-d'));
		$this->assertSame(expected: [[7.0, 'businessDays']], actual: $calculator->added);
		$this->assertSame(expected: [], actual: $this->warnings);
	}//end testAnAnsweredEndDateIsUsed()

	/**
	 * A negative lead time without a calendar ends on the start day.
	 *
	 * @return void
	 */
	public function testANegativeFallbackLeadTimeEndsOnTheStart(): void {
		$roll = $this->roll(calculator: null, calendars: null);

		$end  = $roll->endAfterOrCalendarDays(start: new DateTimeImmutable('2026-09-10'), days: -3, mode: WorkingDayRoll::MODE_WORKING_DAYS);

		$this->assertSame(expected: '2026-09-10', actual: $end->format('Y-m-d'));
	}//end testANegativeFallbackLeadTimeEndsOnTheStart()

	/**
	 * A measured working-day span is rounded to whole days; a refused one is no answer.
	 *
	 * @return void
	 */
	public function testAWorkingDaySpanIsRoundedOrNull(): void {
		$from = new DateTimeImmutable('2026-09-10');
		$to   = new DateTimeImmutable('2026-09-17');

		$calendar = WorkingCalendar::fromArray(
			['slug' => 'nl-national', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8, 'timezone' => 'Europe/Amsterdam']
		);
		$measured = $this->over(calendar: $calendar, calculator: new ScriptedCalculator(measured: 4.6));
		$this->assertSame(expected: 5, actual: $measured->daysBetween(from: $from, to: $to, mode: WorkingDayRoll::MODE_WORKING_DAYS));

		$refused = $this->over(calendar: $calendar, calculator: new ScriptedCalculator(refuses: true));
		$this->assertNull(actual: $refused->daysBetween(from: $from, to: $to, mode: WorkingDayRoll::MODE_WORKING_DAYS));
		$this->assertSame(expected: 'Dossiq termijn: the organisation calendar refused a working-day span', actual: $this->warnings[0][0]);
	}//end testAWorkingDaySpanIsRoundedOrNull()

	/**
	 * A refused first working moment is no answer, with the moment in the warning.
	 *
	 * @return void
	 */
	public function testARefusedFirstWorkingMomentIsNull(): void {
		$roll = $this->over(calendar: new ScriptedCalendar(), calculator: new ScriptedCalculator(refuses: true));

		$this->assertNull(actual: $roll->firstWorkingMomentAtOrAfter(moment: new DateTimeImmutable('2026-09-12T10:00:00+02:00')));
		$this->assertSame(
			expected: [[
				'Dossiq termijn: the organisation calendar refused to name the first working moment',
				['moment' => '2026-09-12T10:00:00+02:00', 'error' => 'calculator refused'],
			]],
			actual: $this->warnings
		);
	}//end testARefusedFirstWorkingMomentIsNull()
	/**
	 * An answer before the moment asked about is refused with both moments logged; the moment itself is kept.
	 *
	 * @return void
	 */
	public function testOnlyAnAnswerAtOrAfterTheMomentIsKept(): void {
		$moment  = new DateTimeImmutable('2026-09-12T10:00:00+02:00');
		$earlier = $this->over(calendar: new ScriptedCalendar(), calculator: new ScriptedCalculator(answer: $moment->modify('-1 hour')));
		$same    = $this->over(calendar: new ScriptedCalendar(), calculator: new ScriptedCalculator(answer: $moment));

		$this->assertNull(actual: $earlier->firstWorkingMomentAtOrAfter(moment: $moment));
		$this->assertSame(
			expected: [[
				'Dossiq termijn: the organisation calendar named a working moment before the one asked about',
				['moment' => '2026-09-12T10:00:00+02:00', 'answered' => '2026-09-12T09:00:00+02:00'],
			]],
			actual: $this->warnings
		);
		$this->assertEquals(expected: $moment, actual: $same->firstWorkingMomentAtOrAfter(moment: $moment));
	}//end testOnlyAnAnswerAtOrAfterTheMomentIsKept()
}//end class

/**
 * A calculator that answers or refuses as scripted, and records what it was asked to add.
 */
class ScriptedCalculator {

	/**
	 * Values and units asked to add.
	 *
	 * @var list<array{0: float, 1: string}>
	 */
	public array $added = [];

	/**
	 * Script the calculator.
	 *
	 * @param DateTimeImmutable|null $answer   The date add() returns, or null for the start date.
	 * @param float                  $measured What measure() returns.
	 * @param bool                   $refuses  Whether every call throws.
	 */
	public function __construct(
		private readonly ?DateTimeImmutable $answer = null,
		private readonly float $measured = 0.0,
		private readonly bool $refuses = false,
	) {
	}//end __construct()

	/**
	 * Add a value to a date.
	 *
	 * @param DateTimeInterface $from     The start.
	 * @param float             $value    The value.
	 * @param string            $unit     The unit.
	 * @param object            $calendar The calendar.
	 *
	 * @return DateTimeImmutable The scripted answer.
	 */
	public function add(DateTimeInterface $from, float $value, string $unit, object $calendar): DateTimeImmutable {
		if ($this->refuses === true) {
			throw new RuntimeException(message: 'calculator refused');
		}

		$this->added[] = [$value, $unit];

		return $this->answer ?? DateTimeImmutable::createFromInterface($from);
	}//end add()

	/**
	 * Measure a span.
	 *
	 * @param object $span The span.
	 * @param string $unit The unit.
	 *
	 * @return float The scripted measure.
	 */
	public function measure(object $span, string $unit): float {
		if ($this->refuses === true) {
			throw new RuntimeException(message: 'calculator refused');
		}

		return $this->measured;
	}//end measure()
}//end class

/**
 * A calendar service that hands out one calendar, or fails to.
 */
class ScriptedCalendars {

	/**
	 * Script the service.
	 *
	 * @param object|null $calendar The calendar, or null to throw.
	 */
	public function __construct(private readonly ?object $calendar = null) {
	}//end __construct()

	/**
	 * Resolve the calendar.
	 *
	 * @param string|null $slug         The calendar slug.
	 * @param string|null $organisation The organisation.
	 *
	 * @return object The calendar.
	 */
	public function resolve(?string $slug, ?string $organisation): object {
		if ($this->calendar === null) {
			throw new RuntimeException(message: 'no calendar');
		}

		return $this->calendar;
	}//end resolve()
}//end class

/**
 * A Monday-to-Friday calendar that can refuse every question.
 */
class ScriptedCalendar {

	/**
	 * Script the calendar.
	 *
	 * @param mixed $weekdays What getWorkingWeekdays() returns.
	 * @param bool  $refuses  Whether every question throws.
	 */
	public function __construct(
		private readonly mixed $weekdays = [1, 2, 3, 4, 5],
		private readonly bool $refuses = false,
	) {
	}//end __construct()

	/**
	 * Whether a day is a working day; 1 or 0, as a loosely typed engine may answer.
	 *
	 * @param DateTimeInterface $moment The day.
	 *
	 * @return int 1 on a weekday, 0 otherwise.
	 */
	public function isWorkingDay(DateTimeInterface $moment): int {
		if ($this->refuses === true) {
			throw new RuntimeException(message: 'calendar refused');
		}

		if ((int) $moment->format('N') < 6) {
			return 1;
		}

		return 0;
	}//end isWorkingDay()

	/**
	 * The working weekdays.
	 *
	 * @return mixed The scripted weekdays.
	 */
	public function getWorkingWeekdays(): mixed {
		if ($this->refuses === true) {
			throw new RuntimeException(message: 'calendar refused');
		}

		return $this->weekdays;
	}//end getWorkingWeekdays()
}//end class

/**
 * A calendar from an engine that does not expose its weekdays.
 */
class WeekdaylessCalendar {

	/**
	 * Every day is a working day.
	 *
	 * @param DateTimeInterface $moment The day.
	 *
	 * @return bool Always true.
	 */
	public function isWorkingDay(DateTimeInterface $moment): bool {
		return true;
	}//end isWorkingDay()
}//end class
