<?php

/**
 * DescribesValues — a value described for a message.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\DescribesValues;
use PHPUnit\Framework\TestCase;

/**
 * Strings as themselves, anything else by type.
 *
 * @covers \OCA\Dossiq\Service\Support\DescribesValues
 */
final class DescribesValuesTest extends TestCase {

	/**
	 * A string reads as itself, other values as their type.
	 *
	 * @return void
	 */
	public function testDescribes(): void {
		$subject = new class() {
			use DescribesValues {
				describe as public;
			}
		};

		self::assertSame('text', $subject->describe('text'));
		self::assertSame('integer', $subject->describe(3));
		self::assertSame('array', $subject->describe([]));
		self::assertSame('NULL', $subject->describe(null));
	}//end testDescribes()
}//end class
