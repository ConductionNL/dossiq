<?php

/**
 * ReadableDay unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ReadableDay;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\ReadableDay
 */
class ReadableDayTest extends TestCase {

	/**
	 * A moment reads as its day with the Dutch month spelled out.
	 *
	 * @return void
	 */
	public function testDutchSpellsTheMonth(): void {
		$day = new ReadableDay();

		self::assertSame('14 oktober 2026', $day->dutch(value: '2026-10-14T12:34:29+00:00'));
		self::assertSame('1 januari 2027', $day->dutch(value: '2027-01-01'));
		self::assertSame('31 december 2026', $day->dutch(value: '  2026-12-31  '));
	}//end testDutchSpellsTheMonth()

	/**
	 * The English wording uses the English month names.
	 *
	 * @return void
	 */
	public function testEnglishSpellsTheMonth(): void {
		$day = new ReadableDay();

		self::assertSame('14 October 2026', $day->english(value: '2026-10-14T12:34:29+00:00'));
		self::assertSame('5 May 2026', $day->english(value: '2026-05-05'));
	}//end testEnglishSpellsTheMonth()

	/**
	 * Nothing to read, or something that is no date, answers an empty string.
	 *
	 * @return void
	 */
	public function testNoDateReadsAsEmpty(): void {
		$day = new ReadableDay();

		self::assertSame('', $day->dutch(value: ''));
		self::assertSame('', $day->dutch(value: '   '));
		self::assertSame('', $day->dutch(value: 'not a date at all'));
		self::assertSame('', $day->english(value: 'tomorrow-ish'));
	}//end testNoDateReadsAsEmpty()

	/**
	 * The public Dutch month list holds twelve months, January first.
	 *
	 * @return void
	 */
	public function testTheDutchMonthListStartsInJanuary(): void {
		self::assertCount(12, ReadableDay::MONTHS_NL);
		self::assertSame('januari', ReadableDay::MONTHS_NL[0]);
		self::assertSame('december', ReadableDay::MONTHS_NL[11]);
	}//end testTheDutchMonthListStartsInJanuary()
}//end class
