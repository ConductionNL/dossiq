<?php

/**
 * NormalisesObjectRows — an object or array read as a plain array.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use JsonSerializable;
use OCA\Dossiq\Service\Support\NormalisesObjectRows;
use PHPUnit\Framework\TestCase;

/**
 * Arrays, entities, serialisables and anything else.
 *
 * @covers \OCA\Dossiq\Service\Support\NormalisesObjectRows
 */
final class NormalisesObjectRowsTest extends TestCase {

	/**
	 * A class exposing the trait's step.
	 *
	 * @return object
	 */
	private function subject(): object {
		return new class() {
			use NormalisesObjectRows {
				toArray as public;
			}
		};
	}//end subject()

	/**
	 * Each input shape reads as its data.
	 *
	 * @return void
	 */
	public function testReadsEachShape(): void {
		$entity = new class() {
			/**
			 * The entity's data.
			 *
			 * @return array<string, string>
			 */
			public function getObject(): array {
				return ['from' => 'entity'];
			}
		};
		$serialisable = new class() implements JsonSerializable {
			/**
			 * The serialised data.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['from' => 'json'];
			}
		};

		$subject = $this->subject();
		self::assertSame(['a' => 1], $subject->toArray(['a' => 1]));
		self::assertSame(['from' => 'entity'], $subject->toArray($entity));
		self::assertSame(['from' => 'json'], $subject->toArray($serialisable));
		self::assertSame([], $subject->toArray(new \stdClass()));
		self::assertSame([], $subject->toArray('text'));
	}//end testReadsEachShape()
}//end class
