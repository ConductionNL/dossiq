<?php

/**
 * Unit tests for OccurrenceRows.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
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

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use JsonSerializable;
use OCA\Dossiq\Service\Flow\OccurrenceRows;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A store answer of any shape reads as the id and title of every row with an id.
 *
 * @covers \OCA\Dossiq\Service\Flow\OccurrenceRows
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class OccurrenceRowsTest extends TestCase {

	/**
	 * A page and a bare list read the same; array, entity and plain object rows all count.
	 *
	 * @return void
	 */
	public function testEveryAnswerShapeReadsAsIdAndTitle(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The serialised row.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'case-2', 'title' => 'Tweede'];
			}//end jsonSerialize()
		};
		$plain = new stdClass();
		$plain->id = ' case-3 ';
		$rows = [['id' => 'case-1', 'title' => 'Eerste', 'other' => 'x'], $entity, $plain, ['title' => 'Zonder id'], ['id' => '  ']];
		$expected = [
			['id' => 'case-1', 'title' => 'Eerste'],
			['id' => 'case-2', 'title' => 'Tweede'],
			['id' => 'case-3', 'title' => ''],
		];

		$occurrences = new OccurrenceRows();
		self::assertSame(expected: $expected, actual: $occurrences->occurrences(answer: ['results' => $rows, 'total' => 5]));
		self::assertSame(expected: $expected, actual: $occurrences->occurrences(answer: $rows));
	}//end testEveryAnswerShapeReadsAsIdAndTitle()

	/**
	 * An empty answer, and one that is no list at all, hold no occurrences.
	 *
	 * @return void
	 */
	public function testNothingReadsAsNoOccurrences(): void {
		$occurrences = new OccurrenceRows();

		self::assertSame(expected: [], actual: $occurrences->occurrences(answer: []));
		self::assertSame(expected: [], actual: $occurrences->occurrences(answer: ['results' => []]));
		self::assertSame(expected: [], actual: $occurrences->occurrences(answer: null));
	}//end testNothingReadsAsNoOccurrences()
}//end class
