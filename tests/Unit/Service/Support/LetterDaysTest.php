<?php

/**
 * A letter decides its language once, and every date in it follows.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\LetterDays;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\LetterDays
 * @uses \OCA\Dossiq\Service\Support\ReadableDay
 */
class LetterDaysTest extends TestCase {

	/**
	 * The declared locale decides the month name, and it decides it once.
	 *
	 * @return void
	 */
	public function testTheDeclaredLocaleNamesTheMonth(): void {
		self::assertSame('15 juni 2026', (new LetterDays(language: 'nl'))->day(value: '2026-06-15T09:12:00+02:00'));
		self::assertSame('15 June 2026', (new LetterDays(language: 'en'))->day(value: '2026-06-15T09:12:00+02:00'));
		self::assertSame('1 september 2026', (new LetterDays(language: 'nl'))->day(value: '2026-09-01'));
	}//end testTheDeclaredLocaleNamesTheMonth()

	/**
	 * A letter that carries no locale, or one nobody wrote a wording for, is
	 * Dutch: the register's language, never an English date in a Dutch body.
	 *
	 * @return void
	 */
	public function testAnUndeclaredLanguageIsDutch(): void {
		self::assertSame('10 augustus 2026', (new LetterDays(language: ''))->day(value: '2026-08-10'));
		self::assertSame('10 augustus 2026', (new LetterDays(language: 'de'))->day(value: '2026-08-10'));
	}//end testAnUndeclaredLanguageIsDutch()

	/**
	 * What a letter prints: a day where there is one, and the value itself
	 * where there is not, so a placeholder stays a placeholder.
	 *
	 * @return void
	 */
	public function testWhatIsNoDateIsPrintedAsItStands(): void {
		$days = new LetterDays(language: 'nl');

		self::assertSame('20 oktober 2026', $days->shown(value: '2026-10-20'));
		self::assertSame('–', $days->shown(value: '–'));
		self::assertSame('-', $days->shown(value: '-'));
		self::assertSame('', $days->day(value: ''));
	}//end testWhatIsNoDateIsPrintedAsItStands()
}//end class
