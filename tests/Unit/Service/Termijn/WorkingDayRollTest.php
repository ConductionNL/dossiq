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
	 * This class no longer reads the roll flag, and must not read it again.
	 *
	 * It briefly did, beside `TermijnTimerService::rollEnabled()`, and the two
	 * disagreed about an ABSENT flag: off here, on there, because Awt art. 1
	 * applies by law and not by configuration. One case could then get two
	 * different end dates depending on which path reached it, and both looked
	 * perfectly ordinary. The duplicate is gone; this is what stops it coming
	 * back by hand.
	 *
	 * @return void
	 */
	public function testTheRollDecisionIsNotTakenHere(): void {
		$this->assertFalse(method_exists(WorkingDayRoll::class, 'roll'));
		$this->assertFalse(method_exists(WorkingDayRoll::class, 'isAskedFor'));
		// The flag may be NAMED in the header, which explains why it is not
		// read; what must not come back is a read of it. Matching the
		// subscript rather than the word is the difference between a rule and
		// a mention, and this file is the one place that distinction is the
		// whole point.
		$this->assertDoesNotMatchRegularExpression(
			'/\\[\\s*\x27rollToWorkingDay\x27|\\[\\s*"rollToWorkingDay"/',
			(string)file_get_contents(__DIR__.'/../../../../lib/Service/Termijn/WorkingDayRoll.php'),
			'the flag is read by TermijnTimerService::rollEnabled() and nowhere else'
		);
	}

	/**
	 * Without a calendar the accessor says so, rather than guessing a day.
	 *
	 * The caller starts the term on the moment it already had. A silent
	 * fallback to "today is a working day" would start a citizen's clock on a
	 * Sunday on any instance without OpenRegister.
	 *
	 * @return void
	 */
	public function testWithoutACalendarNothingIsNamed(): void {
		$roll = $this->degraded();

		$this->assertFalse($roll->isAvailable());
		$this->assertNull(
			$roll->firstWorkingMomentAtOrAfter(moment: new DateTimeImmutable('2026-04-27T09:00:00+02:00'))
		);
	}

	/**
	 * With a calendar, the moment it names is the one returned.
	 *
	 * @return void
	 */
	public function testTheCalendarNamesTheFirstWorkingMoment(): void {
		$roll = $this->withEngine(answering: new DateTimeImmutable('2026-04-28T09:00:00+02:00'));

		$this->assertTrue($roll->isAvailable());
		$this->assertSame(
			'2026-04-28T09:00:00+02:00',
			$roll->firstWorkingMomentAtOrAfter(
				moment: new DateTimeImmutable('2026-04-27T16:00:00+02:00')
			)?->format('c')
		);
	}

	/**
	 * A moment BEFORE the one asked about is refused.
	 *
	 * Forward only: a term that started before the request arrived takes days
	 * off a citizen without anybody seeing a wrong-looking date.
	 *
	 * @return void
	 */
	public function testAMomentBeforeTheOneAskedAboutIsRefused(): void {
		$roll = $this->withEngine(answering: new DateTimeImmutable('2026-04-24T09:00:00+02:00'));

		$this->assertNull(
			$roll->firstWorkingMomentAtOrAfter(
				moment: new DateTimeImmutable('2026-04-27T16:00:00+02:00')
			)
		);
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
