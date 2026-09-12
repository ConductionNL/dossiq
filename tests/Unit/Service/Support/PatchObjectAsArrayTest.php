<?php

/**
 * SearchesObjects::patchObjectAsArray(): both seams, and both refusals.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\SearchesObjects;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exposes the trait's protected seam to the test.
 */
class PatchingHost {

	use SearchesObjects;

	/**
	 * Call the seam.
	 *
	 * @param object               $objectService The object service.
	 * @param array<string, mixed> $changes       The fields to write.
	 *
	 * @return array<string, mixed>|null
	 */
	public function patch(object $objectService, array $changes): ?array {
		return $this->patchObjectAsArray(
			objectService: $objectService,
			register: 'dossiq',
			schema: 'consultation',
			id: 'obj-1',
			changes: $changes
		);
	}
}//end class

/**
 * The PATCH seam behind every partial write in lib/.
 *
 * @covers \OCA\Dossiq\Service\Support\SearchesObjects
 */
class PatchObjectAsArrayTest extends TestCase {

	/**
	 * With patchObject() available, only the changes travel.
	 *
	 * @return void
	 */
	public function testThePatchSeamCarriesOnlyTheChanges(): void {
		$objectService = new class {
			/** @var array<string, mixed> */
			public array $call = [];

			public function patchObject(string $objectId, array $data, string|int|null $register = null, string|int|null $schema = null): array {
				$this->call = ['id' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema];

				return ['id' => $objectId, 'status' => 'closed', 'title' => 'kept'];
			}

			public function saveObject(array $object, mixed ...$rest): array {
				throw new RuntimeException('saveObject must not be used while patchObject exists');
			}
		};

		$result = (new PatchingHost())->patch(objectService: $objectService, changes: ['status' => 'closed']);

		$this->assertSame(
			['id' => 'obj-1', 'data' => ['status' => 'closed'], 'register' => 'dossiq', 'schema' => 'consultation'],
			$objectService->call
		);
		$this->assertSame('kept', $result['title']);

	}//end testThePatchSeamCarriesOnlyTheChanges()

	/**
	 * Without patchObject(), the save carries the fresh read plus the changes.
	 *
	 * @return void
	 */
	public function testTheFallbackSavesTheWholeObjectWithoutItsMetadata(): void {
		$objectService = new class {
			/** @var array<string, mixed> */
			public array $saved = [];

			/** @var string|null */
			public ?string $savedUuid = null;

			public function find(string $id, string|int|null $register = null, string|int|null $schema = null): array {
				return [
					'id' => $id,
					'title' => 'Advies brandweer',
					'status' => 'open',
					'@self' => ['id' => $id, 'owner' => 'someone-else', 'folder' => 42],
				];
			}

			public function saveObject(array $object, string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): array {
				$this->saved = $object;
				$this->savedUuid = $uuid;

				return $object;
			}
		};

		(new PatchingHost())->patch(objectService: $objectService, changes: ['status' => 'closed']);

		$this->assertSame('obj-1', $objectService->savedUuid);
		$this->assertSame(['title' => 'Advies brandweer', 'status' => 'closed'], $objectService->saved);

	}//end testTheFallbackSavesTheWholeObjectWithoutItsMetadata()

	/**
	 * An object the fallback cannot read is refused, never saved blind.
	 *
	 * @return void
	 */
	public function testAnUnreadableObjectIsRefused(): void {
		$objectService = new class {
			public function find(string $id, string|int|null $register = null, string|int|null $schema = null): ?array {
				return null;
			}

			public function saveObject(array $object, mixed ...$rest): array {
				throw new RuntimeException('nothing may be saved when the read found nothing');
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('object_not_found_for_partial_write');

		(new PatchingHost())->patch(objectService: $objectService, changes: ['status' => 'closed']);

	}//end testAnUnreadableObjectIsRefused()

	/**
	 * A service with neither seam is refused rather than handed a partial object.
	 *
	 * @return void
	 */
	public function testAServiceWithNoSeamIsRefused(): void {
		$objectService = new class {
			public function saveObject(array $object, mixed ...$rest): array {
				return $object;
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('object_service_cannot_write_partially');

		(new PatchingHost())->patch(objectService: $objectService, changes: ['status' => 'closed']);

	}//end testAServiceWithNoSeamIsRefused()
}//end class
