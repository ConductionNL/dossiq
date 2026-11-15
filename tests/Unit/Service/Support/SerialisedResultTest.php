<?php

/**
 * SerialisedResult unit tests
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

use JsonSerializable;
use OCA\Dossiq\Service\Support\SerialisedResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\SerialisedResult
 * @covers \OCA\Dossiq\Service\Support\SearchesObjects
 */
class SerialisedResultTest extends TestCase {

	/**
	 * An array is its own row; a serialisable object is its array.
	 *
	 * @return void
	 */
	public function testRowOfReadsArraysAndSerialisableObjects(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'abc'];
			}//end jsonSerialize()
		};

		self::assertSame(['a' => 1], SerialisedResult::rowOf(value: ['a' => 1]));
		self::assertSame(['id' => 'abc'], SerialisedResult::rowOf(value: $entity));
	}//end testRowOfReadsArraysAndSerialisableObjects()

	/**
	 * Anything else is no row: null from rowOf, an empty array from arrayOf.
	 *
	 * @return void
	 */
	public function testWhatIsNoRowIsNullOrEmpty(): void {
		$scalarSerialiser = new class implements JsonSerializable {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'scalar';
			}//end jsonSerialize()
		};

		self::assertNull(SerialisedResult::rowOf(value: 'text'));
		self::assertNull(SerialisedResult::rowOf(value: new \stdClass()));
		self::assertNull(SerialisedResult::rowOf(value: $scalarSerialiser));
		self::assertSame([], SerialisedResult::arrayOf(value: null));
		self::assertSame([], SerialisedResult::arrayOf(value: $scalarSerialiser));
		self::assertSame(['b' => 2], SerialisedResult::arrayOf(value: ['b' => 2]));
	}//end testWhatIsNoRowIsNullOrEmpty()
}//end class
