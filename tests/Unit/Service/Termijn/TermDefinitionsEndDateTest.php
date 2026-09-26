<?php

/**
 * Counting a term's end date in calendar days.
 *
 * The arithmetic moved off `DateTimeImmutable::modify()` onto `add()` with a
 * `DateInterval`, which removed two psalm suppressions: `modify()` takes an
 * arbitrary string, so its return type is falsable and every call needed one.
 * A refactor that removes a suppression must move no date, so these cases pin
 * the dates the old call answered, including the one nobody would choose.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The calendar-day count behind a term's end date.
 *
 * @covers \OCA\Dossiq\Service\Termijn\TermDefinitions
 *
 * @uses \OCA\Dossiq\Service\Termijn\WorkingDayRoll
 */
class TermDefinitionsEndDateTest extends TestCase {

	/**
	 * Definitions with no engine and no timer, which is every build without
	 * OpenRegister and the path the calendar-day count runs on.
	 *
	 * @return TermDefinitions The definitions.
	 */
	private function definitions(): TermDefinitions {
		return new TermDefinitions(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end definitions()

	/**
	 * A definition that says nothing about its counting mode counts calendar
	 * days, which is what an Awb beslistermijn does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public function testASilentDefinitionCountsCalendarDays(): void {
		$end = $this->definitions()->endDateFor(
			start: new DateTimeImmutable('2026-03-01'),
			days: 14,
			definitie: []
		);

		self::assertSame('2026-03-15', $end->format('Y-m-d'));
	}//end testASilentDefinitionCountsCalendarDays()

	/**
	 * The count crosses a month and a leap day without special-casing either,
	 * which is the whole reason it is date arithmetic and not addition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public function testTheCountCrossesAMonthAndALeapDay(): void {
		$definitions = $this->definitions();

		self::assertSame(
			'2028-03-01',
			$definitions->endDateFor(
				start: new DateTimeImmutable('2028-02-01'),
				days: 29,
				definitie: []
			)->format('Y-m-d'),
			'2028 is a leap year, so 29 days from 1 February lands on 1 March'
		);
		self::assertSame(
			'2027-01-07',
			$definitions->endDateFor(
				start: new DateTimeImmutable('2026-12-24'),
				days: 14,
				definitie: []
			)->format('Y-m-d')
		);
	}//end testTheCountCrossesAMonthAndALeapDay()

	/**
	 * A zero-day term ends the day it starts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public function testAZeroDayTermEndsWhereItStarts(): void {
		$end = $this->definitions()->endDateFor(
			start: new DateTimeImmutable('2026-03-01'),
			days: 0,
			definitie: []
		);

		self::assertSame('2026-03-01', $end->format('Y-m-d'));
	}//end testAZeroDayTermEndsWhereItStarts()

	/**
	 * 🔑 A NEGATIVE DURATION STILL COUNTS FORWARD, AND THAT IS DELIBERATE.
	 *
	 * Nothing declares a negative duration. The call this replaced built
	 * `modify('+-3 days')`, which PHP parses as plus three, so -3 from 1
	 * March answered 4 March. `DateInterval` cannot express `P-3D` at all,
	 * so the replacement had to either reproduce that or change it. It
	 * reproduces it, and this case is why anyone changing it later will know
	 * they are changing something rather than fixing an oversight.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public function testANegativeDurationCountsForwardAsItAlwaysDid(): void {
		$end = $this->definitions()->endDateFor(
			start: new DateTimeImmutable('2026-03-01'),
			days: -3,
			definitie: []
		);

		self::assertSame('2026-03-04', $end->format('Y-m-d'));
	}//end testANegativeDurationCountsForwardAsItAlwaysDid()

	/**
	 * A definition asking for working days with no engine to answer falls
	 * back to calendar days, because a term must still get a date. The
	 * degradation is logged rather than silent: it gives the case a SHORTER
	 * term than it is owed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
	 */
	public function testAWorkingDayTermWithNoEngineFallsBackAndSaysSo(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('SHORTER than it is owed'),
				$this->callback(static fn (array $context): bool => $context['days'] === 10)
			);

		$definitions = new TermDefinitions(
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			logger: $logger,
			roll: $this->workingDayRollThatCannotAnswer(),
		);

		$end = $definitions->endDateFor(
			start: new DateTimeImmutable('2026-03-01'),
			days: 10,
			definitie: ['countingMode' => WorkingDayRoll::MODE_WORKING_DAYS, 'caseType' => 'ct-1']
		);

		self::assertSame('2026-03-11', $end->format('Y-m-d'));
	}//end testAWorkingDayTermWithNoEngineFallsBackAndSaysSo()

	/**
	 * A roll that resolves no organisation calendar.
	 *
	 * @return WorkingDayRoll The roll.
	 */
	private function workingDayRollThatCannotAnswer(): WorkingDayRoll {
		$roll = $this->createMock(originalClassName: WorkingDayRoll::class);
		$roll->method('endAfter')->willReturn(null);

		return $roll;
	}//end workingDayRollThatCannotAnswer()
}//end class
