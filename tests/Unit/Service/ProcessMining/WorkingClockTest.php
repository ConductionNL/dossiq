<?php

/**
 * Which clock a dossiq report counts on, and whether it says so.
 *
 * The seam has three postures and they render identically on a page, which is
 * the whole reason it names which one it is in. So each posture is asserted
 * for BOTH things: the number it produces and the name it gives itself.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
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

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use DateTimeImmutable;
use OCA\Dossiq\Service\ProcessMining\WorkingClock;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The working clock, its degraded posture, and the names of both.
 *
 * @covers \OCA\Dossiq\Service\ProcessMining\WorkingClock
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 */
class WorkingClockTest extends TestCase {
	/**
	 * A container that has never heard of the engine.
	 *
	 * @return ContainerInterface The container.
	 */
	private function emptyContainer(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		return $container;
	}

	/**
	 * Without the engine the clock says so, and counts working days times eight.
	 *
	 * Friday 16:00 to Monday 09:00 touches two working days, so the degraded
	 * clock answers 16. The number is WRONG for the question and right for
	 * what it claims to be, which is exactly why {@see WorkingClock::clock()}
	 * has to be readable before anybody prints it.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineTheClockIsWorkingDaysTimesEight(): void {
		$clock = new WorkingClock(
			container: $this->emptyContainer(),
			days: new WorkingDayCalculator()
		);

		$this->assertSame(WorkingClock::CLOCK_DAYS_TIMES_EIGHT, $clock->clock());

		$measured = $clock->hoursBetween(
			from: new DateTimeImmutable('2026-09-11T16:00:00+00:00'),
			to: new DateTimeImmutable('2026-09-14T09:00:00+00:00')
		);

		$this->assertSame(65.0, $measured['wallHours']);
		$this->assertSame(16.0, $measured['workingHours']);
	}

	/**
	 * With the engine on the classpath the clock is the calendar's, and the
	 * engine's answer is the one reported.
	 *
	 * THE ENGINE CLASSES ARE ALIASED INTO EXISTENCE. `WorkingClock` guards
	 * with `class_exists()` before it asks the container, so a test that only
	 * stubbed the container would never reach the engine path and would pass
	 * on a seam that could not resolve anything. `class_alias` makes the
	 * names real for this process, which is the only way to walk the branch
	 * that matters. It runs in its own process so the aliases do not leak
	 * into another test's `class_exists()`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testWithTheEngineTheAnswerIsTheEngines(): void {
		if (class_exists(WorkingClock::ENGINE_CALCULATOR) === false) {
			class_alias(FakeEngineCalculator::class, WorkingClock::ENGINE_CALCULATOR);
		}

		if (class_exists(WorkingClock::ENGINE_CALENDARS) === false) {
			class_alias(FakeEngineCalendars::class, WorkingClock::ENGINE_CALENDARS);
		}

		$calendar = new \stdClass();
		$calculator = new FakeEngineCalculator();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($calculator, $calendar): object {
				if ($id === WorkingClock::ENGINE_CALCULATOR) {
					return $calculator;
				}

				if ($id === WorkingClock::ENGINE_CALENDARS) {
					return new FakeEngineCalendars(calendar: $calendar);
				}

				throw new RuntimeException('not registered');
			}
		);

		$clock = new WorkingClock(container: $container, days: new WorkingDayCalculator());

		$this->assertSame(WorkingClock::CLOCK_CALENDAR, $clock->clock());

		$measured = $clock->hoursBetween(
			from: new DateTimeImmutable('2026-09-11T16:00:00+00:00'),
			to: new DateTimeImmutable('2026-09-14T09:00:00+00:00')
		);

		// The engine's number, not the degraded 16 and not the wall 65.
		$this->assertSame(1.0, $measured['workingHours']);
		$this->assertSame(65.0, $measured['wallHours']);

		// And the engine was handed the calendar the resolver answered with,
		// rather than one this seam invented.
		$this->assertTrue($calculator->sawCalendar($calendar));
	}

	/**
	 * A calculator that refuses one interval does not downgrade the report.
	 *
	 * The interval falls back to the degraded count, and `clock()` still
	 * reports the calendar, because the calculator has not stopped being the
	 * calculator on the strength of one bad pair of dates.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testARefusedIntervalFallsBackWithoutDowngradingTheReport(): void {
		if (class_exists(WorkingClock::ENGINE_CALCULATOR) === false) {
			class_alias(FakeEngineCalculator::class, WorkingClock::ENGINE_CALCULATOR);
		}

		if (class_exists(WorkingClock::ENGINE_CALENDARS) === false) {
			class_alias(FakeEngineCalendars::class, WorkingClock::ENGINE_CALENDARS);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): object {
				if ($id === WorkingClock::ENGINE_CALCULATOR) {
					return new FakeEngineCalculator(refuse: true);
				}

				if ($id === WorkingClock::ENGINE_CALENDARS) {
					return new FakeEngineCalendars(calendar: new \stdClass());
				}

				throw new RuntimeException('not registered');
			}
		);

		$clock = new WorkingClock(container: $container, days: new WorkingDayCalculator());

		$measured = $clock->hoursBetween(
			from: new DateTimeImmutable('2026-09-11T16:00:00+00:00'),
			to: new DateTimeImmutable('2026-09-14T09:00:00+00:00')
		);

		$this->assertSame(16.0, $measured['workingHours']);
		$this->assertSame(WorkingClock::CLOCK_CALENDAR, $clock->clock());
	}

	/**
	 * A negative interval is zero on both clocks.
	 *
	 * A status record out of order is bad data, not a case that went
	 * backwards, and a negative median would be subtracted from a team's
	 * total without anybody noticing.
	 *
	 * @return void
	 */
	public function testABackwardsIntervalIsZero(): void {
		$clock = new WorkingClock(
			container: $this->emptyContainer(),
			days: new WorkingDayCalculator()
		);

		$this->assertSame(
			['workingHours' => 0.0, 'wallHours' => 0.0],
			$clock->hoursBetween(
				from: new DateTimeImmutable('2026-09-14T09:00:00+00:00'),
				to: new DateTimeImmutable('2026-09-11T16:00:00+00:00')
			)
		);
	}

	/**
	 * The degraded clock skips a weekend, so it is not the wall clock either.
	 *
	 * The control for the first test: without this, a degraded path that had
	 * simply returned the wall-clock number would pass everything above.
	 *
	 * @return void
	 */
	public function testTheDegradedClockIsNotTheWallClock(): void {
		$clock = new WorkingClock(
			container: $this->emptyContainer(),
			days: new WorkingDayCalculator()
		);

		$measured = $clock->hoursBetween(
			from: new DateTimeImmutable('2026-09-12T09:00:00+00:00'),
			to: new DateTimeImmutable('2026-09-13T17:00:00+00:00')
		);

		// A Saturday into a Sunday: 32 wall-clock hours, no working ones.
		$this->assertSame(32.0, $measured['wallHours']);
		$this->assertSame(0.0, $measured['workingHours']);
	}
}//end class

/**
 * Stands in for OpenRegister's SlaCalculator.
 *
 * Not a PHPUnit double: it is aliased to a class name this repository cannot
 * load, and `createMock()` needs the real one.
 */
class FakeEngineCalculator {
	/**
	 * The calendars it was handed.
	 *
	 * @var array<int, object>
	 */
	private array $calendars = [];

	/**
	 * Constructor.
	 *
	 * @param boolean $refuse Whether to throw instead of answering.
	 */
	public function __construct(private readonly bool $refuse = false) {

	}//end __construct()

	/**
	 * Measure, or refuse.
	 *
	 * @param \DateTimeInterface $from The start.
	 * @param \DateTimeInterface $to The end.
	 * @param object $calendar The calendar.
	 *
	 * @return float The working hours.
	 */
	public function elapsedBusinessHours(\DateTimeInterface $from, \DateTimeInterface $to, object $calendar): float {
		if ($this->refuse === true) {
			throw new RuntimeException('no calendar for that year');
		}

		$this->calendars[] = $calendar;

		return 1.0;
	}//end elapsedBusinessHours()

	/**
	 * Whether it was handed this exact calendar.
	 *
	 * @param object $calendar The calendar the resolver answered with.
	 *
	 * @return boolean True when it was.
	 */
	public function sawCalendar(object $calendar): bool {
		return in_array($calendar, $this->calendars, true);
	}//end sawCalendar()
}//end class

/**
 * Stands in for OpenRegister's WorkingCalendarService.
 */
class FakeEngineCalendars {
	/**
	 * Constructor.
	 *
	 * @param object $calendar The calendar to answer with.
	 */
	public function __construct(private readonly object $calendar) {

	}//end __construct()

	/**
	 * Resolve the instance's calendar.
	 *
	 * @param string|null $calendarSlug The slug asked for.
	 * @param string|null $organisation The organisation asked for.
	 *
	 * @return object The calendar.
	 */
	public function resolve(?string $calendarSlug, ?string $organisation): object {
		return $this->calendar;
	}//end resolve()
}//end class
