<?php

/**
 * The canonical form of a value: maps sorted by key, lists kept in order, at every depth.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\FrozenRecord
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use OCA\Dossiq\Service\FrozenRecord\CanonicalValue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\FrozenRecord\CanonicalValue
 */
class CanonicalValueTest extends TestCase {

	public function testScalarsPassThroughUntouched(): void {
		self::assertSame('x', CanonicalValue::formOf(value: 'x'));
		self::assertSame(3, CanonicalValue::formOf(value: 3));
		self::assertNull(CanonicalValue::formOf(value: null));
		self::assertFalse(CanonicalValue::formOf(value: false));
	}//end testScalarsPassThroughUntouched()

	public function testMapsAreSortedByKeyAtEveryDepthAndListsKeepTheirOrder(): void {
		$value = [
			'zeta' => ['b' => 2, 'a' => 1],
			'alpha' => [
				['y' => 'second', 'x' => 'first'],
				'c',
				'a',
			],
			'10' => 'ten',
			'9' => 'nine',
		];

		$canonical = CanonicalValue::formOf(value: $value);

		// SORT_STRING: '10' sorts before '9', and both before letters.
		self::assertSame(['10', '9', 'alpha', 'zeta'], array_map('strval', array_keys($canonical)));
		self::assertSame(['a' => 1, 'b' => 2], $canonical['zeta']);
		self::assertSame([['x' => 'first', 'y' => 'second'], 'c', 'a'], $canonical['alpha']);
	}//end testMapsAreSortedByKeyAtEveryDepthAndListsKeepTheirOrder()

	public function testTwoOrderingsOfOneMapHaveOneCanonicalForm(): void {
		$one = ['b' => ['d' => 1, 'c' => [2, 1]], 'a' => true];
		$two = ['a' => true, 'b' => ['c' => [2, 1], 'd' => 1]];

		self::assertSame(
			json_encode(CanonicalValue::formOf(value: $one)),
			json_encode(CanonicalValue::formOf(value: $two))
		);
		self::assertNotSame(
			json_encode(CanonicalValue::formOf(value: ['c' => [1, 2]])),
			json_encode(CanonicalValue::formOf(value: ['c' => [2, 1]]))
		);
	}//end testTwoOrderingsOfOneMapHaveOneCanonicalForm()
}//end class
