<?php

/**
 * Unit tests for RepairSchema: a repair step pages through one schema's rows
 * until a short page, patches a row by merging changes over the stored row,
 * and reads a row's id from the row or its @self.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\RepairSchema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\Support\RepairSchema
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class RepairSchemaTest extends TestCase {

	/**
	 * An object service that serves the given pages and records every call.
	 *
	 * @param list<list<mixed>>    $pages  The pages searchObjects answers, in order.
	 * @param array<string, mixed> $stored What find answers.
	 */
	private function objectService(array $pages = [], array $stored = []): object {
		return new class($pages, $stored) {
			/** @var list<array<int|string, mixed>> */
			public array $calls = [];

			public function __construct(private array $pages, private array $stored) {
			}

			public function searchObjects(array $query): array {
				$this->calls[] = ['searchObjects', $query];
				return array_shift($this->pages) ?? [];
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->calls[] = ['searchObjectsBySlug', $register, $schema, $filters];
				return array_shift($this->pages) ?? [];
			}

			public function find(string $id, int|string $register, int|string $schema): array {
				$this->calls[] = ['find', $id, $register, $schema];
				return $this->stored;
			}

			public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): array {
				$this->calls[] = ['saveObject', $object, $register, $schema, $uuid];
				return $object;
			}
		};
	}//end objectService()

	public function testRowsArePagedUntilAShortPage(): void {
		$service = $this->objectService([[['id' => 'a'], ['id' => 'b']], [['id' => 'c'], ['id' => 'd']], [['id' => 'e']]]);
		$target = new RepairSchema(objectService: $service, register: '4');
		$target->schema = '12';

		$ids = array_map(static fn (array $row): string => $row['id'], iterator_to_array($target->rows(2), false));

		$this->assertSame(['a', 'b', 'c', 'd', 'e'], $ids);
		$this->assertSame(
			[
				['searchObjects', ['_limit' => 2, '_page' => 1, '@self' => ['register' => 4, 'schema' => 12]]],
				['searchObjects', ['_limit' => 2, '_page' => 2, '@self' => ['register' => 4, 'schema' => 12]]],
				['searchObjects', ['_limit' => 2, '_page' => 3, '@self' => ['register' => 4, 'schema' => 12]]],
			],
			$service->calls
		);
	}//end testRowsArePagedUntilAShortPage()

	public function testAFullLastPageCostsOneEmptyRead(): void {
		$service = $this->objectService([[['id' => 'a'], ['id' => 'b']]]);
		$target = new RepairSchema(objectService: $service, register: 'dossiq');
		$target->schema = 'case';

		$this->assertCount(2, iterator_to_array($target->rows(2), false));
		$this->assertSame(
			[
				['searchObjectsBySlug', 'dossiq', 'case', ['_limit' => 2, '_page' => 1]],
				['searchObjectsBySlug', 'dossiq', 'case', ['_limit' => 2, '_page' => 2]],
			],
			$service->calls
		);
	}//end testAFullLastPageCostsOneEmptyRead()

	public function testPatchMergesTheChangesOverTheStoredRowAndSavesUnderTheId(): void {
		$service = $this->objectService([], ['id' => 'row-1', '@self' => ['id' => 'row-1'], 'title' => 'Old', 'status' => 'open']);
		$target = new RepairSchema(objectService: $service, register: 'dossiq');
		$target->schema = 'case';

		$target->patch('row-1', ['title' => 'New']);

		$this->assertSame(
			[
				['find', 'row-1', 'dossiq', 'case'],
				['saveObject', ['title' => 'New', 'status' => 'open'], 'dossiq', 'case', 'row-1'],
			],
			$service->calls
		);
	}//end testPatchMergesTheChangesOverTheStoredRowAndSavesUnderTheId()

	public function testIdOfReadsTheRowThenItsSelfThenNothing(): void {
		$target = new RepairSchema(objectService: $this->objectService(), register: 'dossiq');

		$this->assertSame('row-1', $target->idOf(['id' => 'row-1', '@self' => ['id' => 'self-1']]));
		$this->assertSame('self-1', $target->idOf(['@self' => ['id' => 'self-1']]));
		$this->assertSame('7', $target->idOf(['id' => 7]));
		$this->assertSame('', $target->idOf(['title' => 'x']));
		$this->assertSame('dossiq', $target->register);
		$this->assertSame('', $target->schema);
	}//end testIdOfReadsTheRowThenItsSelfThenNothing()
}//end class
