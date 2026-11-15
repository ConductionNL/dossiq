<?php

/**
 * Unit tests for StoredValueRewriter: a repair rewrites one wrong stored
 * value to the right one in the dossiq register, only on rows that really
 * hold the wrong value, and counts the rows it saved.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\StoredValueRewriter;
use OCA\Dossiq\Repair\Support\ValueRewrite;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\Support\StoredValueRewriter
 *
 * @uses \OCA\Dossiq\Repair\Support\ValueRewrite
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class StoredValueRewriterTest extends TestCase {

	private function rewrite(): ValueRewrite {
		$rewrite = new ValueRewrite();
		$rewrite->schema = 'case';
		$rewrite->property = 'confidentiality';
		$rewrite->wrong = 'zeer geheim';
		$rewrite->right = 'zeer_geheim';
		return $rewrite;
	}//end rewrite()

	/**
	 * An object service answering the given rows and recording searches and saves.
	 *
	 * @param list<mixed> $rows      What the search answers.
	 * @param bool        $saveTakes Whether a save returns the row (true) or nothing (false).
	 */
	private function objectService(array $rows, bool $saveTakes = true): object {
		return new class($rows, $saveTakes) {
			/** @var list<array<int|string, mixed>> */
			public array $searches = [];

			/** @var list<array<string, mixed>> */
			public array $saves = [];

			public function __construct(private array $rows, private bool $saveTakes) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = [$register, $schema, $filters];
				return $this->rows;
			}

			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): ?array {
				$this->saves[] = $object;
				return $this->saveTakes === true ? $object : null;
			}
		};
	}//end objectService()

	public function testOnlyRowsHoldingTheWrongValueAreRewrittenAndCounted(): void {
		$service = $this->objectService(
			[
				['id' => 'a', 'confidentiality' => 'zeer geheim', 'title' => 'A'],
				['id' => 'b', 'confidentiality' => 'geheim'],
				['id' => 'c'],
				['id' => 'd', 'confidentiality' => 'zeer geheim'],
			]
		);

		$this->assertSame(2, (new StoredValueRewriter($service))->rewrite($this->rewrite()));
		$this->assertSame([['dossiq', 'case', ['confidentiality' => 'zeer geheim', '_limit' => 5000]]], $service->searches);
		$this->assertSame(
			[
				['id' => 'a', 'confidentiality' => 'zeer_geheim', 'title' => 'A'],
				['id' => 'd', 'confidentiality' => 'zeer_geheim'],
			],
			$service->saves
		);
	}//end testOnlyRowsHoldingTheWrongValueAreRewrittenAndCounted()

	public function testASaveThatReturnsNothingIsNotCounted(): void {
		$service = $this->objectService([['id' => 'a', 'confidentiality' => 'zeer geheim']], saveTakes: false);

		$this->assertSame(0, (new StoredValueRewriter($service))->rewrite($this->rewrite()));
		$this->assertCount(1, $service->saves);
	}//end testASaveThatReturnsNothingIsNotCounted()

	public function testNoMatchingRowsMeansNoSaves(): void {
		$service = $this->objectService([]);

		$this->assertSame(0, (new StoredValueRewriter($service))->rewrite($this->rewrite()));
		$this->assertSame([], $service->saves);
	}//end testNoMatchingRowsMeansNoSaves()
}//end class
