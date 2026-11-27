<?php

/**
 * SavedObjectArray Unit Tests
 *
 * The object is saved in the named register and schema and the answer is
 * the stored entity's serialised form.
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
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use JsonSerializable;
use OCA\Dossiq\Service\Support\SavedObjectArray;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SavedObjectArray.
 *
 * @covers \OCA\Dossiq\Service\Support\SavedObjectArray
 */
final class SavedObjectArrayTest extends TestCase {

	/**
	 * The save names the register and schema, and the stored entity is answered serialised.
	 *
	 * @return void
	 */
	public function testTheObjectIsSavedAndAnsweredAsStored(): void {
		$store = new class {
			/**
			 * The saves, in order.
			 *
			 * @var array<int, array{0: array<string, mixed>, 1: ObjectSaveOptions}>
			 */
			public array $saves = [];

			/**
			 * Record the save and answer a stored entity.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param ObjectSaveOptions    $options Where to save it.
			 *
			 * @return JsonSerializable
			 */
			public function saveObject(array $object, ObjectSaveOptions $options): JsonSerializable {
				$this->saves[] = [$object, $options];
				return new class($object) implements JsonSerializable {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $object The stored object.
					 */
					public function __construct(private array $object) {
					}

					/**
					 * The stored object with its id.
					 *
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return ['id' => 'uuid-1'] + $this->object;
					}
				};
			}
		};

		$saved = (new SavedObjectArray())->save(
			objectService: $store,
			register: 'dossiq',
			schema: 'collaboration',
			object: ['status' => 'requested'],
		);

		$this->assertSame(expected: ['id' => 'uuid-1', 'status' => 'requested'], actual: $saved);
		$this->assertCount(expectedCount: 1, haystack: $store->saves);
		$this->assertSame(expected: ['status' => 'requested'], actual: $store->saves[0][0]);
		$this->assertSame(expected: 'dossiq', actual: $store->saves[0][1]->target->register);
		$this->assertSame(expected: 'collaboration', actual: $store->saves[0][1]->target->schema);
		$this->assertNull(actual: $store->saves[0][1]->uuid);
	}//end testTheObjectIsSavedAndAnsweredAsStored()
}//end class
