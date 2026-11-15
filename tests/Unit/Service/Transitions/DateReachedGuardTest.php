<?php

/**
 * The date-reached guard: a closing date, a standstill term, and what it says when the date is missing or not reached.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Service\Transitions\DateReachedGuard;
use OCA\Dossiq\Service\Transitions\GuardResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\DateReachedGuard
 * @uses   \OCA\Dossiq\Service\Transitions\GuardResult
 */
class DateReachedGuardTest extends TestCase {

	/**
	 * Evaluate a guard config against a case on a given day.
	 *
	 * The date reader is the boundary: it reads a stored `Y-m-d` (or the day
	 * of a date-time) and says what day it is in the administrator's zone.
	 *
	 * @param array<string, mixed> $config The guard.
	 * @param array<string, mixed> $case   The case.
	 * @param string               $today  Today, `Y-m-d`.
	 *
	 * @return GuardResult
	 */
	private function evaluate(array $config, array $case, string $today='2026-11-14'): GuardResult {
		$dates = $this->createMock(CaseDateNormaliserInterface::class);
		$dates->method('todayAsCalendarDate')->willReturn($today);
		$dates->method('toCalendarDateOrNull')->willReturnCallback(
			static fn (mixed $value): ?string => (is_string($value) === true && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) === 1) ? $match[1] : null
		);

		return (new DateReachedGuard(l10n: new SourceL10n(), dates: $dates))->evaluate(guardConfig: $config, case: $case, userId: 'anna');
	}//end evaluate()

	/**
	 * A standstill end ahead holds the final award, naming the day.
	 *
	 * @return void
	 */
	public function testADayAheadHoldsTheStep(): void {
		$result = $this->evaluate(
			config: ['type' => 'dateReached', 'field' => 'opschortendeTermijnEinde'],
			case: ['opschortendeTermijnEinde' => '2026-11-21']
		);

		self::assertFalse($result->passed);
		self::assertSame('Not possible before 2026-11-21', $result->failureMessage);
		self::assertSame(['field' => 'opschortendeTermijnEinde', 'day' => '2026-11-21', 'after' => false], $result->details);
	}//end testADayAheadHoldsTheStep()

	/**
	 * On the day itself and after it the step is possible.
	 *
	 * @return void
	 */
	public function testTheStepIsPossibleFromTheDayOn(): void {
		$config = ['field' => 'opschortendeTermijnEinde'];

		$onTheDay = $this->evaluate(config: $config, case: ['opschortendeTermijnEinde' => '2026-11-14']);
		self::assertTrue($onTheDay->passed);
		self::assertNull($onTheDay->failureMessage);
		self::assertSame(['field' => 'opschortendeTermijnEinde', 'day' => '2026-11-14', 'after' => false], $onTheDay->details);

		self::assertTrue($this->evaluate(config: $config, case: ['opschortendeTermijnEinde' => '2026-10-01'])->passed);
	}//end testTheStepIsPossibleFromTheDayOn()

	/**
	 * With `after`, a closing date still holds evaluation on the day itself and frees it the day after.
	 *
	 * @return void
	 */
	public function testAfterWaitsUntilTheDayHasPassed(): void {
		$config = ['type' => 'dateReached', 'field' => 'sluiting', 'after' => true];

		$onTheDay = $this->evaluate(config: $config, case: ['sluiting' => '2026-11-14']);
		self::assertFalse($onTheDay->passed);
		self::assertSame('Only possible after 2026-11-14', $onTheDay->failureMessage);

		$ahead = $this->evaluate(config: $config, case: ['sluiting' => '2026-11-20']);
		self::assertSame('Only possible after 2026-11-20', $ahead->failureMessage);

		$dayAfter = $this->evaluate(config: $config, case: ['sluiting' => '2026-11-14'], today: '2026-11-15');
		self::assertTrue($dayAfter->passed);
		self::assertSame(['field' => 'sluiting', 'day' => '2026-11-14', 'after' => true], $dayAfter->details);
	}//end testAfterWaitsUntilTheDayHasPassed()

	/**
	 * Only a literal true turns `after` on.
	 *
	 * @return void
	 */
	public function testOnlyALiteralTrueTurnsAfterOn(): void {
		foreach (['true', 1, 'yes'] as $after) {
			self::assertTrue($this->evaluate(config: ['field' => 'sluiting', 'after' => $after], case: ['sluiting' => '2026-11-14'])->passed);
		}
	}//end testOnlyALiteralTrueTurnsAfterOn()

	/**
	 * The day is read from the case's answered properties when it is not a field of the case.
	 *
	 * @return void
	 */
	public function testTheDayIsReadFromTheAnsweredProperties(): void {
		$case = ['properties' => ['skip', ['name' => 'other', 'value' => '2026-01-01'], ['name' => 'opschortendeTermijnEinde', 'value' => '2026-12-01']]];

		$result = $this->evaluate(config: ['field' => 'opschortendeTermijnEinde'], case: $case);

		self::assertFalse($result->passed);
		self::assertSame('Not possible before 2026-12-01', $result->failureMessage);
	}//end testTheDayIsReadFromTheAnsweredProperties()

	/**
	 * A case without the day, or with something the reader cannot read as one, is refused naming the field.
	 *
	 * @return void
	 */
	public function testAMissingOrUnreadableDayIsRefused(): void {
		$cases = [[], ['sluiting' => ''], ['sluiting' => 'next week-ish'], ['properties' => 'none'], ['properties' => [['name' => 'sluiting']]]];
		foreach ($cases as $case) {
			$result = $this->evaluate(config: ['field' => 'sluiting'], case: $case);

			self::assertFalse($result->passed);
			self::assertSame('Date missing: sluiting', $result->failureMessage);
			self::assertSame(['field' => 'sluiting'], $result->details);
		}
	}//end testAMissingOrUnreadableDayIsRefused()

	/**
	 * A guard without a field is refused as unconfigured.
	 *
	 * @return void
	 */
	public function testAGuardWithoutAFieldIsRefused(): void {
		$result = $this->evaluate(config: ['type' => 'dateReached'], case: ['sluiting' => '2026-01-01']);

		self::assertFalse($result->passed);
		self::assertSame('Date guard missing field', $result->failureMessage);
	}//end testAGuardWithoutAFieldIsRefused()
}//end class
