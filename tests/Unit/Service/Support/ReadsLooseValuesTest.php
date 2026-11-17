<?php

/**
 * ReadsLooseValues — loose values read as arrays, trimmed strings or null.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\ReadsLooseValues;
use PHPUnit\Framework\TestCase;

/**
 * Each reading answers the value in its plain type, and its fallback otherwise.
 *
 * @covers \OCA\Dossiq\Service\Support\ReadsLooseValues
 */
final class ReadsLooseValuesTest extends TestCase {

	/**
	 * A class exposing the trait's steps.
	 *
	 * @return object
	 */
	private function subject(): object {
		return new class() {
			use ReadsLooseValues {
				looseList as public;
				looseText as public;
				looseScalarText as public;
				looseConfigText as public;
				looseTextOr as public;
				blankAsNull as public;
			}
		};
	}//end subject()

	/**
	 * An array stays itself; anything else is an empty list.
	 *
	 * @return void
	 */
	public function testListReadsArraysOnly(): void {
		$subject = $this->subject();

		self::assertSame(expected: ['a'], actual: $subject->looseList(['a']));
		self::assertSame(expected: [], actual: $subject->looseList('a'));
		self::assertSame(expected: [], actual: $subject->looseList(null));
	}//end testListReadsArraysOnly()

	/**
	 * Strings are trimmed; scalars only through the scalar reading.
	 *
	 * @return void
	 */
	public function testTextReadings(): void {
		$subject = $this->subject();

		self::assertSame(expected: 'a', actual: $subject->looseText(' a '));
		self::assertSame(expected: '', actual: $subject->looseText(5));
		self::assertSame(expected: '5', actual: $subject->looseScalarText(5));
		self::assertSame(expected: 'b', actual: $subject->looseScalarText(' b '));
		self::assertSame(expected: '', actual: $subject->looseScalarText(['b']));
	}//end testTextReadings()

	/**
	 * A configuration key reads as its trimmed string, or ''.
	 *
	 * @return void
	 */
	public function testConfigText(): void {
		$subject = $this->subject();

		self::assertSame(expected: 'x', actual: $subject->looseConfigText(['k' => ' x '], 'k'));
		self::assertSame(expected: '', actual: $subject->looseConfigText(['k' => 1], 'k'));
		self::assertSame(expected: '', actual: $subject->looseConfigText([], 'k'));
	}//end testConfigText()

	/**
	 * A blank string falls back to the default, or to null.
	 *
	 * @return void
	 */
	public function testBlankFallbacks(): void {
		$subject = $this->subject();

		self::assertSame(expected: 'y', actual: $subject->looseTextOr(' y ', 'd'));
		self::assertSame(expected: 'd', actual: $subject->looseTextOr('  ', 'd'));
		self::assertSame(expected: 'd', actual: $subject->looseTextOr(3, 'd'));
		self::assertSame(expected: ' z ', actual: $subject->blankAsNull(' z '));
		self::assertNull(actual: $subject->blankAsNull(' '));
	}//end testBlankFallbacks()
}//end class
