<?php

/**
 * The applicant's term note spells a day with the Dutch month names.
 *
 * Every month, and every day the note refuses to spell, pinned before the
 * month names are read from ReadableDay instead of a copy of them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\Term\ApplicantTermNote;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class ApplicantTermNoteDayTest extends TestCase {

	/**
	 * The day a moment is spelled as.
	 *
	 * @param string $moment The moment.
	 *
	 * @return string The day in words.
	 */
	private function day(string $moment): string {
		$note = (new ReflectionClass(ApplicantTermNote::class))->newInstanceWithoutConstructor();
		return (string)(new ReflectionMethod($note, 'dayInWords'))->invoke($note, $moment);
	}//end day()

	/**
	 * Every month is spelled in Dutch, January first.
	 *
	 * @return void
	 */
	public function testEveryMonthIsSpelledInDutch(): void {
		$months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
		foreach ($months as $index => $month) {
			self::assertSame('6 ' . $month . ' 2026', $this->day(moment: sprintf('2026-%02d-06T10:00:00+02:00', ($index + 1))));
		}
	}//end testEveryMonthIsSpelledInDutch()

	/**
	 * A day that cannot be read is spelled as nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableDayIsNothing(): void {
		foreach (['', 'gisteren', '2026-13-01', '2026-00-10', '2026-05-00', '2026-05-32'] as $moment) {
			self::assertSame('', $this->day(moment: $moment), 'moment ' . $moment);
		}

		self::assertSame('31 december 2026', $this->day(moment: ' 2026-12-31 '));
	}//end testAnUnreadableDayIsNothing()
}//end class
