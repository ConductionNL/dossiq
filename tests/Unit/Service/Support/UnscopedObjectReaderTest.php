<?php

/**
 * One object by id is read as the system and unscoped, and a missing one reads as null.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use JsonSerializable;
use OCA\Dossiq\Service\Support\UnscopedObjectReader;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Support\UnscopedObjectReader
 */
class UnscopedObjectReaderTest extends TestCase {

	/**
	 * The read is unscoped, on the pinned register and schema, and runs as the system.
	 *
	 * @return void
	 */
	public function testTheReadIsUnscopedAndRunsAsTheSystem(): void {
		$service = new class {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/** @var int */
			public int $asSystem = 0;

			public function runAsSystem(callable $operation): mixed {
				$this->asSystem++;
				return $operation();
			}

			public function find(int|string $id, int|string $register, int|string $schema, bool $_rbac, bool $_multitenancy): array {
				$this->calls[] = ['id' => $id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return ['id' => $id, 'title' => 'Found'];
			}
		};

		$row = (new UnscopedObjectReader())->find(objectService: $service, register: 'r', schema: 's', id: 'uuid-1');

		self::assertSame(['id' => 'uuid-1', 'title' => 'Found'], $row);
		self::assertSame(1, $service->asSystem);
		self::assertSame([['id' => 'uuid-1', 'register' => 'r', 'schema' => 's', 'rbac' => false, 'multitenancy' => false]], $service->calls);
	}//end testTheReadIsUnscopedAndRunsAsTheSystem()

	/**
	 * An entity is answered as its serialised array.
	 *
	 * @return void
	 */
	public function testAnEntityIsAnsweredAsItsArray(): void {
		$service = new class {
			public function find(int|string $id, int|string $register, int|string $schema, bool $_rbac, bool $_multitenancy): JsonSerializable {
				return new class implements JsonSerializable {
					public function jsonSerialize(): array {
						return ['id' => 'uuid-2'];
					}
				};
			}
		};

		self::assertSame(['id' => 'uuid-2'], (new UnscopedObjectReader())->find(objectService: $service, register: 'r', schema: 's', id: 'uuid-2'));
	}//end testAnEntityIsAnsweredAsItsArray()

	/**
	 * A row that does not exist, or a result that is no object, reads as null.
	 *
	 * @return void
	 */
	public function testAMissingRowReadsAsNull(): void {
		$missing = new class {
			public function find(int|string $id, int|string $register, int|string $schema, bool $_rbac, bool $_multitenancy): array {
				throw new DoesNotExistException('gone');
			}
		};
		$nothing = new class {
			public function find(int|string $id, int|string $register, int|string $schema, bool $_rbac, bool $_multitenancy): ?string {
				return null;
			}
		};

		self::assertNull((new UnscopedObjectReader())->find(objectService: $missing, register: 'r', schema: 's', id: 'x'));
		self::assertNull((new UnscopedObjectReader())->find(objectService: $nothing, register: 'r', schema: 's', id: 'x'));
	}//end testAMissingRowReadsAsNull()
}//end class
