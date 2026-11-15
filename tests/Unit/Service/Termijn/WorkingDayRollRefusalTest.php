<?php

/**
 * How the working-day roll answers when the organisation calendar refuses,
 * is incomplete or cannot be resolved.
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every refusal of the engine degrades to null (or to calendar days where the
 * caller asked for that) and is logged; nothing is guessed.
 *
 * @covers \OCA\Dossiq\Service\Termijn\WorkingDayRoll
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class WorkingDayRollRefusalTest extends TestCase {

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Make the engine's class names resolvable, the gate the roll checks first.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		foreach ([WorkingDayRoll::ENGINE_CALCULATOR, WorkingDayRoll::ENGINE_CALENDARS] as $engineClass) {
			if (class_exists($engineClass) === false) {
				class_alias(RefusingEngine::class, $engineClass);
			}
		}

		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A roll over the given calculator and calendar service.
	 *
	 * @param object|null $calculator The calculator, or null when OpenRegister has none.
	 * @param object|null $calendars  The calendar service, or null when OpenRegister has none.
	 *
	 * @return WorkingDayRoll The subject.
	 */
	private function roll(?object $calculator, ?object $calendars): WorkingDayRoll {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static fn (string $class): ?object => $class === WorkingDayRoll::ENGINE_CALCULATOR ? $calculator : $calendars
		);

		return new WorkingDayRoll(settings: $settings, logger: $this->logger);
	}//end roll()

	/**
	 * A roll whose engine resolves the given calendar and refuses every calculation.
	 *
	 * @param object $calendar The resolved calendar.
	 *
	 * @return WorkingDayRoll The subject.
	 */
	private function withCalendar(object $calendar): WorkingDayRoll {
		return $this->roll(calculator: new RefusingEngine(), calendars: new RefusingEngine(calendar: $calendar));
	}//end withCalendar()

	/**
	 * Expect exactly one warning with this message.
	 *
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function expectWarning(string $message): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with($message, $this->isType('array'));
	}//end expectWarning()

	/**
	 * Without a calculator the calendar is not even resolved.
	 *
	 * @return void
	 */
	public function testWithoutACalculatorTheRollIsUnavailable(): void {
		$calendars = new RefusingEngine(calendar: new WeekdayCalendar());

		$roll = $this->roll(calculator: null, calendars: $calendars);

		$this->assertFalse($roll->isAvailable());
		$this->assertSame(0, $calendars->resolved);
		$this->assertNull($roll->worksOn(new DateTimeImmutable('2026-04-27')));
		$this->assertNull($roll->workingWeekdays());
		$this->assertNull($roll->endAfter(new DateTimeImmutable('2026-04-27'), 3, WorkingDayRoll::MODE_WORKING_DAYS));
	}//end testWithoutACalculatorTheRollIsUnavailable()

	/**
	 * A calendar service that cannot resolve leaves the roll unavailable, and says why once.
	 *
	 * @return void
	 */
	public function testACalendarThatCannotBeResolvedIsLoggedOnce(): void {
		$this->expectWarning('Dossiq termijn: the organisation calendar is unavailable');
		$calendars = new RefusingEngine(calendar: null);

		$roll = $this->roll(calculator: new RefusingEngine(), calendars: $calendars);

		$this->assertFalse($roll->isAvailable());
		$this->assertFalse($roll->isAvailable());
		$this->assertSame(1, $calendars->resolved);
	}//end testACalendarThatCannotBeResolvedIsLoggedOnce()

	/**
	 * The calendar is asked for the default calendar, and once.
	 *
	 * @return void
	 */
	public function testTheDefaultCalendarIsResolvedOnce(): void {
		$calendars = new RefusingEngine(calendar: new WeekdayCalendar());
		$roll      = $this->roll(calculator: new RefusingEngine(), calendars: $calendars);

		$this->assertTrue($roll->isAvailable());
		$this->assertTrue($roll->worksOn(new DateTimeImmutable('2026-04-27')));
		$this->assertFalse($roll->worksOn(new DateTimeImmutable('2026-04-26')));
		$this->assertSame(1, $calendars->resolved);
		$this->assertSame([null, null], $calendars->resolvedWith);
	}//end testTheDefaultCalendarIsResolvedOnce()

	/**
	 * A working-day question the calendar refuses is not answered.
	 *
	 * @return void
	 */
	public function testARefusedWorkingDayQuestionIsNull(): void {
		$this->expectWarning('Dossiq termijn: the organisation calendar refused a working-day question');

		$this->assertNull($this->withCalendar(new WeekdayCalendar(refuse: true))->worksOn(new DateTimeImmutable('2026-04-27')));
	}//end testARefusedWorkingDayQuestionIsNull()

	/**
	 * The working weekdays come back as ISO day integers.
	 *
	 * @return void
	 */
	public function testTheWorkingWeekdaysComeBackAsIntegers(): void {
		$roll = $this->withCalendar(new WeekdayCalendar(weekdays: ['a' => '1', 'b' => '2', 'c' => 3]));

		$this->assertSame([1, 2, 3], $roll->workingWeekdays());
	}//end testTheWorkingWeekdaysComeBackAsIntegers()

	/**
	 * An empty list, a non-list and an older calendar without weekdays all answer null.
	 *
	 * @return void
	 */
	public function testMissingWorkingWeekdaysAreNull(): void {
		$this->assertNull($this->withCalendar(new WeekdayCalendar(weekdays: []))->workingWeekdays());
		$this->assertNull($this->withCalendar(new WeekdayCalendar(weekdays: 'mon-fri'))->workingWeekdays());
		$this->assertNull($this->withCalendar(new OlderCalendar())->workingWeekdays());
	}//end testMissingWorkingWeekdaysAreNull()

	/**
	 * Refused working weekdays are not answered.
	 *
	 * @return void
	 */
	public function testRefusedWorkingWeekdaysAreNull(): void {
		$this->expectWarning('Dossiq termijn: the organisation calendar refused its working weekdays');

		$this->assertNull($this->withCalendar(new WeekdayCalendar(refuse: true))->workingWeekdays());
	}//end testRefusedWorkingWeekdaysAreNull()

	/**
	 * A calendar-day term ends by plain addition, without the engine.
	 *
	 * @return void
	 */
	public function testACalendarDayTermNeedsNoEngine(): void {
		$roll = $this->roll(calculator: null, calendars: null);

		$this->assertSame(
			'2026-05-07',
			$roll->endAfter(new DateTimeImmutable('2026-04-27'), 10, WorkingDayRoll::MODE_CALENDAR_DAYS)?->format('Y-m-d')
		);
	}//end testACalendarDayTermNeedsNoEngine()

	/**
	 * A working-day end the engine refuses is not answered.
	 *
	 * @return void
	 */
	public function testARefusedWorkingDayEndIsNull(): void {
		$this->expectWarning('Dossiq termijn: the organisation calendar refused a working-day end date');

		$this->assertNull(
			$this->withCalendar(new WeekdayCalendar())->endAfter(new DateTimeImmutable('2026-04-27'), 5, WorkingDayRoll::MODE_WORKING_DAYS)
		);
	}//end testARefusedWorkingDayEndIsNull()

	/**
	 * The calendar-day fallback logs that the term ends early, and never counts backwards.
	 *
	 * @return void
	 */
	public function testTheFallbackNeverCountsBackwards(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('counted in calendar days'), ['days' => -4]);
		$roll = $this->roll(calculator: null, calendars: null);

		$this->assertSame(
			'2026-04-27',
			$roll->endAfterOrCalendarDays(new DateTimeImmutable('2026-04-27'), -4, WorkingDayRoll::MODE_WORKING_DAYS)->format('Y-m-d')
		);
	}//end testTheFallbackNeverCountsBackwards()

	/**
	 * A working-day span the engine refuses is not answered.
	 *
	 * @return void
	 */
	public function testARefusedWorkingDaySpanIsNull(): void {
		$this->expectWarning('Dossiq termijn: the organisation calendar refused a working-day span');

		$this->assertNull(
			$this->withCalendar(new WeekdayCalendar())->daysBetween(
				new DateTimeImmutable('2026-04-27'),
				new DateTimeImmutable('2026-05-04'),
				WorkingDayRoll::MODE_WORKING_DAYS
			)
		);
	}//end testARefusedWorkingDaySpanIsNull()

	/**
	 * Without the engine a working-day span is not answered and nothing is logged.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineAWorkingDaySpanIsNull(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->assertNull(
			$this->roll(calculator: null, calendars: null)->daysBetween(
				new DateTimeImmutable('2026-04-27'),
				new DateTimeImmutable('2026-05-04'),
				WorkingDayRoll::MODE_WORKING_DAYS
			)
		);
	}//end testWithoutTheEngineAWorkingDaySpanIsNull()

	/**
	 * A first working moment the engine refuses is not answered.
	 *
	 * @return void
	 */
	public function testARefusedFirstWorkingMomentIsNull(): void {
		$this->expectWarning('Dossiq termijn: the organisation calendar refused to name the first working moment');

		$this->assertNull(
			$this->withCalendar(new WeekdayCalendar())->firstWorkingMomentAtOrAfter(new DateTimeImmutable('2026-04-25T10:00:00+02:00'))
		);
	}//end testARefusedFirstWorkingMomentIsNull()
}//end class

/**
 * Stands in for both engine services: a calculator that refuses every
 * calculation, or a calendar service that resolves the given calendar (or
 * refuses when it has none).
 */
class RefusingEngine {

	/**
	 * How often resolve() was called.
	 *
	 * @var integer
	 */
	public int $resolved = 0;

	/**
	 * The arguments of the last resolve() call.
	 *
	 * @var array<int, mixed>
	 */
	public array $resolvedWith = [];

	/**
	 * Constructor.
	 *
	 * @param object|null $calendar The calendar resolve() returns; null to refuse.
	 */
	public function __construct(private readonly ?object $calendar=null) {
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
		$this->resolved++;
		$this->resolvedWith = [$slug, $organisation];
		if ($this->calendar === null) {
			throw new RuntimeException('Working calendar does not exist');
		}

		return $this->calendar;
	}//end resolve()

	/**
	 * Refuse to add.
	 *
	 * @return never
	 */
	public function add(): never {
		throw new RuntimeException('calculator refused');
	}//end add()

	/**
	 * Refuse to measure.
	 *
	 * @return never
	 */
	public function measure(): never {
		throw new RuntimeException('calculator refused');
	}//end measure()
}//end class

/**
 * A Monday-to-Friday calendar that can be told to refuse, with configurable weekdays.
 */
class WeekdayCalendar {

	/**
	 * Constructor.
	 *
	 * @param boolean $refuse   Whether every question is refused.
	 * @param mixed   $weekdays What getWorkingWeekdays() answers.
	 */
	public function __construct(private readonly bool $refuse=false, private readonly mixed $weekdays=[1, 2, 3, 4, 5]) {
	}//end __construct()

	/**
	 * Whether the moment falls on Monday to Friday.
	 *
	 * @param DateTimeInterface $moment The moment.
	 *
	 * @return boolean
	 */
	public function isWorkingDay(DateTimeInterface $moment): bool {
		if ($this->refuse === true) {
			throw new RuntimeException('calendar refused');
		}

		return (int)$moment->format('N') < 6;
	}//end isWorkingDay()

	/**
	 * The working weekdays.
	 *
	 * @return mixed
	 */
	public function getWorkingWeekdays(): mixed {
		if ($this->refuse === true) {
			throw new RuntimeException('calendar refused');
		}

		return $this->weekdays;
	}//end getWorkingWeekdays()
}//end class

/**
 * A calendar from an OpenRegister that does not expose its working weekdays.
 */
class OlderCalendar {

	/**
	 * Every day is a working day.
	 *
	 * @param DateTimeInterface $moment The moment.
	 *
	 * @return boolean
	 */
	public function isWorkingDay(DateTimeInterface $moment): bool {
		return true;
	}//end isWorkingDay()
}//end class
