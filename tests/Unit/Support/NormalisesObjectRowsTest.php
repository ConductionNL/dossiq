<?php

/**
 * NormalisesObjectRows — every row shape becomes an associative array.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Support;

use OCA\Dossiq\Support\NormalisesObjectRows;
use PHPUnit\Framework\TestCase;

/**
 * The row-flattening trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Support\NormalisesObjectRows
 */
final class NormalisesObjectRowsTest extends TestCase {

	/**
	 * Flatten one row through the trait.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The flattened row.
	 */
	private function flatten(mixed $row): array {
		$host = new class {
			use NormalisesObjectRows;

			/**
			 * Expose the protected trait method.
			 *
			 * @param mixed $row The row.
			 *
			 * @return array<string, mixed> The flattened row.
			 */
			public function flatten(mixed $row): array {
				return $this->objectToArray($row);
			}//end flatten()
		};

		return $host->flatten($row);
	}//end flatten()

	/**
	 * An array is kept as it is, and a non-object scalar or null is empty.
	 *
	 * @return void
	 */
	public function testArraysPassAndScalarsAreEmpty(): void {
		self::assertSame(['id' => 'a'], $this->flatten(['id' => 'a']));
		self::assertSame([], $this->flatten(null));
		self::assertSame([], $this->flatten('row'));
	}//end testArraysPassAndScalarsAreEmpty()

	/**
	 * A serialisable object answers its serialised array.
	 *
	 * @return void
	 */
	public function testASerialisableObjectAnswersItsSerialisation(): void {
		$row = new class {
			/**
			 * The serialised row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'serialised'];
			}//end jsonSerialize()
		};

		self::assertSame(['id' => 'serialised'], $this->flatten($row));
	}//end testASerialisableObjectAnswersItsSerialisation()

	/**
	 * An object whose serialisation is not an array, or that has none, is
	 * read for its public properties instead.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreReadForTheirProperties(): void {
		$notAnArray = new class {
			/**
			 * The row id.
			 *
			 * @var string
			 */
			public string $id = 'plain';

			/**
			 * A serialisation that is not an array.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'plain';
			}//end jsonSerialize()
		};

		self::assertSame(['id' => 'plain'], $this->flatten($notAnArray));
		self::assertSame(['id' => 'std'], $this->flatten((object)['id' => 'std']));
	}//end testOtherObjectsAreReadForTheirProperties()
}//end class
