<?php

/**
 * What counts as a filled answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\FilledValue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\FilledValue
 */
class FilledValueTest extends TestCase {

	/**
	 * Blank strings and empty lists are not filled; everything else is, including false and 0.
	 *
	 * @return void
	 */
	public function testWhatCountsAsFilled(): void {
		$this->assertFalse(FilledValue::isFilled(value: ''));
		$this->assertFalse(FilledValue::isFilled(value: "  \t\n"));
		$this->assertFalse(FilledValue::isFilled(value: []));

		$this->assertTrue(FilledValue::isFilled(value: ' x '));
		$this->assertTrue(FilledValue::isFilled(value: '0'));
		$this->assertTrue(FilledValue::isFilled(value: ['']));
		$this->assertTrue(FilledValue::isFilled(value: 0));
		$this->assertTrue(FilledValue::isFilled(value: false));
		$this->assertTrue(FilledValue::isFilled(value: null));
	}//end testWhatCountsAsFilled()
}//end class
