<?php

/**
 * Unit tests for DefinitionRows.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use JsonSerializable;
use OCA\Dossiq\Service\Workflow\DefinitionRows;
use PHPUnit\Framework\TestCase;

/**
 * Rows are sorted newest first, read for their ids and encoded for a write, without a store.
 *
 * @covers \OCA\Dossiq\Service\Workflow\DefinitionRows
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class DefinitionRowsTest extends TestCase {

	/**
	 * An entity row that serialises to the given array.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return JsonSerializable The entity.
	 */
	private function entity(array $row): JsonSerializable {
		return new class($row) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $row The row.
			 */
			public function __construct(private readonly array $row) {
			}//end __construct()

			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}//end jsonSerialize()
		};
	}//end entity()

	/**
	 * Versions sort newest first by their number, text or not; what is no row is dropped.
	 *
	 * @return void
	 */
	public function testVersionsSortNewestFirst(): void {
		$rows = (new DefinitionRows())->newestFirst(
			results: [
				['id' => 'v1', 'version' => 1],
				$this->entity(row: ['id' => 'v3', 'version' => '3']),
				'not a row',
				['id' => 'v0'],
				['id' => 'v2', 'version' => 2],
			]
		);

		self::assertSame(expected: ['v3', 'v2', 'v1', 'v0'], actual: array_column($rows, 'id'));
	}//end testVersionsSortNewestFirst()

	/**
	 * The ids of rows that carry one, entity or array, in order.
	 *
	 * @return void
	 */
	public function testTheIdsAreThoseTheRowsCarry(): void {
		self::assertSame(
			expected: ['st-1', 'st-2'],
			actual: (new DefinitionRows())->idsOf(rows: [['id' => 'st-1'], ['title' => 'no id'], 42, $this->entity(row: ['id' => 'st-2']), ['id' => '']])
		);
	}//end testTheIdsAreThoseTheRowsCarry()

	/**
	 * Only the named properties that hold an array become JSON text; everything else is kept.
	 *
	 * @return void
	 */
	public function testOnlyArrayJsonPropertiesAreEncoded(): void {
		$encoded = (new DefinitionRows())->withJsonEncoded(
			merged: ['graph' => ['nodes' => [1]], 'config' => '{"a":1}', 'tags' => ['x'], 'title' => 'T'],
			jsonProperties: ['graph', 'config', 'missing']
		);

		self::assertSame(
			expected: ['graph' => '{"nodes":[1]}', 'config' => '{"a":1}', 'tags' => ['x'], 'title' => 'T'],
			actual: $encoded
		);
	}//end testOnlyArrayJsonPropertiesAreEncoded()
}//end class
