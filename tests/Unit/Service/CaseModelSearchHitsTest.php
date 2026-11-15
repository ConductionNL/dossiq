<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseModelSearchHits;
use PHPUnit\Framework\TestCase;

/**
 * The rows a caseType or caseModel lookup answers, read on their own.
 *
 * @covers \OCA\Dossiq\Service\CaseModelSearchHits
 */
class CaseModelSearchHitsTest extends TestCase {

	/**
	 * The first hit that carries a row wins; empty and unserialisable hits are skipped.
	 *
	 * @return void
	 */
	public function testFirstRowSkipsHitsWithoutOne(): void {
		$entity = new class implements \JsonSerializable {
			/** @return array<string, string> */
			public function jsonSerialize(): array {
				return ['id' => 'from-entity'];
			}
		};

		$hits = new CaseModelSearchHits();
		$this->assertSame(expected: ['id' => 'from-entity'], actual: $hits->firstAsArray(value: [[], new \stdClass(), $entity, ['id' => 'later']]));
		$this->assertNull(actual: $hits->firstAsArray(value: 'not a list'));
		$this->assertNull(actual: $hits->firstAsArray(value: [[]]));
	}//end testFirstRowSkipsHitsWithoutOne()

	/**
	 * planItems stored as JSON text are decoded; undecodable text reads as no plan items.
	 *
	 * @return void
	 */
	public function testPublishedModelDecodesPlanItemsText(): void {
		$hits = new CaseModelSearchHits();

		$this->assertSame(
			expected: ['id' => 'm', 'planItems' => [['id' => 'a']]],
			actual: $hits->publishedModelOf(found: [['id' => 'm', 'planItems' => '[{"id":"a"}]']])
		);
		$this->assertSame(
			expected: ['id' => 'm', 'planItems' => []],
			actual: $hits->publishedModelOf(found: [['id' => 'm', 'planItems' => '{broken']])
		);
		$this->assertSame(
			expected: ['id' => 'm', 'planItems' => [['id' => 'b']]],
			actual: $hits->publishedModelOf(found: [['id' => 'm', 'planItems' => [['id' => 'b']]]])
		);
		$this->assertNull(actual: $hits->publishedModelOf(found: []));
	}//end testPublishedModelDecodesPlanItemsText()
}//end class
