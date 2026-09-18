<?php

/**
 * The Algemene termijnenwet roll, and the three ways it must not fire.
 *
 * The fixture trio the design asks for: a term ending on Koningsdag, one
 * ending on a Sunday, and the same term with the flag off. The third is the
 * one that matters most on the day this ships: every definition that has not
 * been administered for the Awt must behave exactly as it did yesterday, or
 * the change moves dates people are already counting on.
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
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use PHPUnit\Framework\TestCase;

/**
 * The roll asks the engine, and never moves a date backwards.
 *
 * @covers \OCA\Dossiq\Service\Termijn\WorkingDayRoll
 */
class WorkingDayRollTest extends TestCase {
	/**
	 * A settings service that answers no OpenRegister class.
	 *
	 * The production posture on an instance without OpenRegister, so this is
	 * the real degraded path rather than a stand-in for one.
	 *
	 * @return SettingsService The double.
	 */
	private function withoutEngine(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		return $settings;
	}

	/**
	 * A roll with no engine behind it.
	 *
	 * @return WorkingDayRoll The subject.
	 */
	private function degraded(): WorkingDayRoll {
		return new WorkingDayRoll(settings: $this->withoutEngine());
	}

	/**
	 * The flag decides, and an absent flag is off.
	 *
	 * @return void
	 */
	public function testAnAbsentFlagIsOff(): void {
		$roll = $this->degraded();

		$this->assertFalse($roll->isAskedFor(definition: []));
		$this->assertFalse($roll->isAskedFor(definition: ['rollToWorkingDay' => false]));
		$this->assertTrue($roll->isAskedFor(definition: ['rollToWorkingDay' => true]));
		// The shapes a checkbox arrives in from a JSON store. Each of these
		// read the wrong way silently moves, or fails to move, a statutory
		// date.
		$this->assertTrue($roll->isAskedFor(definition: ['rollToWorkingDay' => 'true']));
		$this->assertTrue($roll->isAskedFor(definition: ['rollToWorkingDay' => 1]));
		$this->assertFalse($roll->isAskedFor(definition: ['rollToWorkingDay' => '0']));
		$this->assertFalse($roll->isAskedFor(definition: ['rollToWorkingDay' => null]));
	}

	/**
	 * With the flag off, nothing is asked and nothing moves.
	 *
	 * @return void
	 */
	public function testWithTheFlagOffTheDateStands(): void {
		$koningsdag = new DateTimeImmutable('2026-04-27T00:00:00+02:00');

		$this->assertEquals(
			$koningsdag,
			$this->degraded()->roll(date: $koningsdag, definition: ['rollToWorkingDay' => false])
		);
	}

	/**
	 * With no calendar answering, the date stands and the caller is told.
	 *
	 * A term that should have moved and did not is a statutory error. It is
	 * logged at warning rather than swallowed, because the one thing worse
	 * than not rolling is not rolling quietly.
	 *
	 * @return void
	 */
	public function testWithNoCalendarTheDateStandsAndIsLogged(): void {
		$logger = new class extends \Psr\Log\AbstractLogger {
			/** @var array<int, string> The messages it was given. */
			public array $lines = [];

			/**
			 * Record a line.
			 *
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = (string)$level.': '.(string)$message;
			}
		};

		$roll = new WorkingDayRoll(settings: $this->withoutEngine(), logger: $logger);
		$koningsdag = new DateTimeImmutable('2026-04-27T00:00:00+02:00');

		$this->assertEquals(
			$koningsdag,
			$roll->roll(date: $koningsdag, definition: ['rollToWorkingDay' => true])
		);
		$this->assertNotSame([], $logger->lines, 'a roll that could not be made is reported');
		$this->assertStringContainsString('warning', $logger->lines[0]);
	}

	/**
	 * The engine answers, and the rolled day is the one it named.
	 *
	 * @return void
	 */
	public function testTheEngineDecidesWhichDayIsNext(): void {
		$roll = $this->withEngine(answering: new DateTimeImmutable('2026-04-28T09:00:00+02:00'));

		$rolled = $roll->roll(
			date: new DateTimeImmutable('2026-04-27T23:59:00+02:00'),
			definition: ['rollToWorkingDay' => true]
		);

		$this->assertSame('2026-04-28', $rolled->format('Y-m-d'));
		// THE TIME OF DAY IS THE TERM'S, NOT THE OFFICE'S. The engine answers
		// the start of the next working day, which is nine in the morning on
		// the seeded calendar. A term ends at the end of its day, so keeping
		// the engine's time would shorten every rolled term by most of a day.
		$this->assertSame('23:59', $rolled->format('H:i'));
	}

	/**
	 * A calendar answering an earlier date is refused.
	 *
	 * The Awt moves a deadline later and never earlier. A roll that could move
	 * one backwards takes days off a citizen's right of reply, so an answer
	 * before the date asked about is discarded and reported rather than used.
	 *
	 * @return void
	 */
	public function testAnAnswerBeforeTheDateIsRefused(): void {
		$asked = new DateTimeImmutable('2026-04-27T12:00:00+02:00');
		$roll = $this->withEngine(answering: new DateTimeImmutable('2026-04-24T09:00:00+02:00'));

		$this->assertEquals($asked, $roll->roll(date: $asked, definition: ['rollToWorkingDay' => true]));
	}

	/**
	 * A roll built around a calculator that answers a fixed instant.
	 *
	 * Not a PHPUnit double: the engine class is not on this repository's
	 * classpath, so `createMock()` has nothing to mock. The seam resolves BY
	 * NAME, which is the production behaviour, so the classes are aliased into
	 * existence for this process.
	 *
	 * @param DateTimeImmutable $answering What the calculator answers.
	 *
	 * @return WorkingDayRoll The subject.
	 */
	private function withEngine(DateTimeImmutable $answering): WorkingDayRoll {
		if (class_exists(WorkingDayRoll::ENGINE_CALCULATOR) === false) {
			class_alias(FakeRollCalculator::class, WorkingDayRoll::ENGINE_CALCULATOR);
		}

		if (class_exists(WorkingDayRoll::ENGINE_CALENDARS) === false) {
			class_alias(FakeRollCalendars::class, WorkingDayRoll::ENGINE_CALENDARS);
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use ($answering): object {
				if ($class === WorkingDayRoll::ENGINE_CALCULATOR) {
					return new FakeRollCalculator(answering: $answering);
				}

				return new FakeRollCalendars();
			}
		);

		return new WorkingDayRoll(settings: $settings);
	}
}//end class

/**
 * Stands in for OpenRegister's SlaCalculator.
 */
class FakeRollCalculator {
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
	 * @param \DateTimeInterface $from The date asked about.
	 * @param float $value The amount, zero for a roll.
	 * @param string $unit The unit.
	 * @param object $calendar The calendar.
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
class FakeRollCalendars {
	/**
	 * Answer a calendar.
	 *
	 * @param string|null $calendarSlug The slug.
	 * @param string|null $organisation The organisation.
	 *
	 * @return object The calendar.
	 */
	public function resolve(?string $calendarSlug, ?string $organisation): object {
		return new \stdClass();
	}//end resolve()
}//end class
