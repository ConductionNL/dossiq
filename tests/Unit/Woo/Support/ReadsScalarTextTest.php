<?php

/**
 * ReadsScalarText — a posted value read as trimmed text.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo\Support;

use OCA\Dossiq\Woo\Support\ReadsScalarText;
use PHPUnit\Framework\TestCase;

/**
 * Scalars read trimmed; arrays, objects and null read as ''.
 *
 * @covers \OCA\Dossiq\Woo\Support\ReadsScalarText
 */
final class ReadsScalarTextTest extends TestCase {

	/**
	 * Each input shape reads as its text.
	 *
	 * @return void
	 */
	public function testReadsScalarsTrimmed(): void {
		$subject = new class() {
			use ReadsScalarText {
				text as public;
			}
		};

		self::assertSame(expected: 'woo', actual: $subject->text(value: ' woo '));
		self::assertSame(expected: '42', actual: $subject->text(value: 42));
		self::assertSame(expected: '', actual: $subject->text(value: ['woo']));
		self::assertSame(expected: '', actual: $subject->text(value: null));
	}//end testReadsScalarsTrimmed()
}//end class
