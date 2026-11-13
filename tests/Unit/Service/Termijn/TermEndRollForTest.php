<?php

/**
 * Rolling a term's end date when its definition allows it, and telling
 * whether an organisation calendar answers at all.
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
use OCA\Dossiq\Service\Termijn\TermEndRoll;
use OCA\Dossiq\Service\TermijnTimerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TermEndRoll::rollTermEndFor() and TermEndRoll::calendarAnswers().
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermEndRoll
 * @uses   \OCA\Dossiq\Service\Termijn\TermEndAsk
 */
class TermEndRollForTest extends TestCase {

	/**
	 * A roll whose settings hand out the given engine classes.
	 *
	 * @param object|null $calendars  The calendar service, or null.
	 * @param object|null $calculator The calculator, or null.
	 *
	 * @return TermEndRoll The roll.
	 */
	private function roll(?object $calendars, ?object $calculator): TermEndRoll {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use ($calendars, $calculator): ?object {
				if ($class === TermijnTimerService::CALENDAR_SERVICE_CLASS) {
					return $calendars;
				}

				return $calculator;
			}
		);

		return new TermEndRoll(settingsService: $settings, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end roll()

	/**
	 * A definition without the flag rolls on the named calendar, asking the engine for zero business days.
	 *
	 * @return void
	 */
	public function testADefinitionWithoutTheFlagRollsOnTheEngine(): void {
		$calendars  = new NamedCalendars();
		$calculator = new ZeroDayCalculator(answer: new DateTimeImmutable('2026-09-14'));
		$saturday   = new DateTimeImmutable('2026-09-12');

		$end = $this->roll(calendars: $calendars, calculator: $calculator)->rollTermEndFor(
			date: $saturday,
			definitie: [],
			calendarSlug: 'nl-national',
			organisation: 'gemeente-a'
		);

		$this->assertSame(expected: '2026-09-14', actual: $end->format('Y-m-d'));
		$this->assertSame(expected: [['nl-national', 'gemeente-a']], actual: $calendars->asked);
		$this->assertSame(
			expected: [['2026-09-12', 0.0, TermijnTimerService::UNIT_BUSINESS_DAYS, $calendars->calendar]],
			actual: $calculator->asked
		);
	}//end testADefinitionWithoutTheFlagRollsOnTheEngine()

	/**
	 * A definition that switches the roll off keeps the date and never asks the engine.
	 *
	 * @return void
	 */
	public function testASwitchedOffRollKeepsTheDate(): void {
		$calendars = new NamedCalendars();
		$saturday  = new DateTimeImmutable('2026-09-12');

		$end = $this->roll(calendars: $calendars, calculator: new ZeroDayCalculator(answer: new DateTimeImmutable('2026-09-14')))
			->rollTermEndFor(date: $saturday, definitie: ['rollToWorkingDay' => false]);

		$this->assertSame(expected: $saturday, actual: $end);
		$this->assertSame(expected: [], actual: $calendars->asked);
	}//end testASwitchedOffRollKeepsTheDate()

	/**
	 * The calendar answers only when both engine classes resolve.
	 *
	 * @return void
	 */
	public function testTheCalendarAnswersOnlyWithBothEngineClasses(): void {
		$calculator = new ZeroDayCalculator(answer: new DateTimeImmutable());

		$this->assertTrue(condition: $this->roll(calendars: new NamedCalendars(), calculator: $calculator)->calendarAnswers());
		$this->assertFalse(condition: $this->roll(calendars: null, calculator: $calculator)->calendarAnswers());
		$this->assertFalse(condition: $this->roll(calendars: new NamedCalendars(), calculator: null)->calendarAnswers());
	}//end testTheCalendarAnswersOnlyWithBothEngineClasses()
}//end class

/**
 * A calendar service that records which calendar was asked for.
 */
class NamedCalendars {

	/**
	 * The slug and organisation of each resolve.
	 *
	 * @var list<array{0: ?string, 1: ?string}>
	 */
	public array $asked = [];

	/**
	 * The calendar handed out.
	 *
	 * @var object
	 */
	public object $calendar;

	/**
	 * Create the service with its calendar.
	 */
	public function __construct() {
		$this->calendar = new \stdClass();
	}//end __construct()

	/**
	 * Resolve a calendar.
	 *
	 * @param string|null $calendarSlug The slug.
	 * @param string|null $organisation The organisation.
	 *
	 * @return object The calendar.
	 */
	public function resolve(?string $calendarSlug, ?string $organisation): object {
		$this->asked[] = [$calendarSlug, $organisation];

		return $this->calendar;
	}//end resolve()
}//end class

/**
 * A calculator that answers one date and records what it was asked.
 */
class ZeroDayCalculator {

	/**
	 * Date, value, unit and calendar of each add.
	 *
	 * @var list<array{0: string, 1: float, 2: string, 3: object}>
	 */
	public array $asked = [];

	/**
	 * Script the calculator.
	 *
	 * @param DateTimeImmutable $answer The date every add returns.
	 */
	public function __construct(private readonly DateTimeImmutable $answer) {
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
		$this->asked[] = [$from->format('Y-m-d'), $value, $unit, $calendar];

		return $this->answer;
	}//end add()
}//end class
