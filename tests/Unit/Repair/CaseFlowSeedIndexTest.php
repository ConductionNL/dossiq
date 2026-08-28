<?php

/**
 * Unit tests for CaseFlowSeedIndex — "does this already exist?".
 *
 * This class is the whole reason the seed is idempotent PER OBJECT rather than
 * all-or-nothing, so what is worth asserting is the SCOPING and the shapes it
 * survives, not the happy path. A reader that quietly finds nothing makes the
 * seed create duplicates on every repair pass; a reader that quietly finds
 * everything makes a half-finished seed permanent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CaseFlowSeedIndex;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CaseFlowSeedIndexTest extends TestCase {

	/**
	 * @var array<int, array<string,mixed>> The queries the double was asked.
	 */
	private array $seen = [];

	private const SCHEMAS = [
		'register' => 'dossiq',
		'caseType' => 'case_type',
		'statusType' => 'status_type',
		'case' => 'case',
	];

	/**
	 * An index over a store that answers $answer to every search.
	 *
	 * @param mixed        $answer  What searchObjects returns. Deliberately
	 *                              `mixed`: the store answers with a bare list
	 *                              on one instance and a paged envelope on
	 *                              another, and reading only one of those is how
	 *                              a reader silently finds nothing.
	 * @param boolean      $throws  Whether the store is unreadable.
	 * @param boolean      $noStore Whether there is no ObjectService at all.
	 *
	 * @return CaseFlowSeedIndex The index.
	 */
	private function index(mixed $answer, bool $throws = false, bool $noStore = false): CaseFlowSeedIndex {
		$this->seen = [];

		$objectService = null;
		if ($noStore === false) {
			$objectService = new class($answer, $throws, $this->seen) {
				public function __construct(
					private mixed $answer,
					private bool $throws,
					public array &$seen,
				) {
				}

				public function searchObjects(array $query): mixed {
					$this->seen[] = $query;

					if ($this->throws === true) {
						throw new RuntimeException('unreadable');
					}

					return $this->answer;
				}
			};
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new CaseFlowSeedIndex($settings);
	}//end index()

	public function testACaseTypeIsFoundByTitle(): void {
		$index = $this->index([['id' => 'ct-1', 'title' => 'Bouwvergunning']]);

		$found = $index->caseTypeByTitle(schemas: self::SCHEMAS, title: 'Bouwvergunning');

		$this->assertSame('ct-1', $found['id']);
	}//end testACaseTypeIsFoundByTitle()

	/**
	 * 🔴 THE QUERY MUST BE SCOPED SERVER-SIDE.
	 *
	 * A reader that fetched every object and filtered in PHP would miss rows the
	 * first page did not contain, and the seed would then re-create objects that
	 * already exist on every repair pass.
	 */
	public function testTheLookupIsScopedToTheRegisterSchemaAndTitle(): void {
		$index = $this->index([]);
		$index->caseTypeByTitle(schemas: self::SCHEMAS, title: 'Bouwvergunning');

		$query = $this->seen[0];
		$this->assertSame('dossiq', $query['@self']['register']);
		$this->assertSame('case_type', $query['@self']['schema']);
		$this->assertSame('Bouwvergunning', $query['title']);
	}//end testTheLookupIsScopedToTheRegisterSchemaAndTitle()

	public function testAnAbsentCaseTypeIsNull(): void {
		$this->assertNull($this->index([])->caseTypeByTitle(schemas: self::SCHEMAS, title: 'Nope'));
	}//end testAnAbsentCaseTypeIsNull()

	/**
	 * A paged envelope answers the same as a bare list.
	 */
	public function testAPagedEnvelopeIsReadTheSameAsABareList(): void {
		$index = $this->index(['results' => [['id' => 'ct-9', 'title' => 'X']], 'total' => 1]);

		$this->assertSame('ct-9', $index->caseTypeByTitle(schemas: self::SCHEMAS, title: 'X')['id']);
	}//end testAPagedEnvelopeIsReadTheSameAsABareList()

	/**
	 * Entities are flattened through jsonSerialize().
	 */
	public function testAnEntityRowIsFlattened(): void {
		$entity = new class {
			public function jsonSerialize(): array {
				return ['id' => 'ct-e', 'title' => 'Entity'];
			}
		};

		$this->assertSame('ct-e', $this->index([$entity])->caseTypeByTitle(schemas: self::SCHEMAS, title: 'Entity')['id']);
	}//end testAnEntityRowIsFlattened()

	/**
	 * 🔴 AN UNREADABLE STORE MUST NOT READ AS "NOTHING EXISTS YET".
	 *
	 * It does return an empty answer — there is nothing better to return — which
	 * is exactly why the seed catches per object and reports rather than
	 * treating a failed read as licence to write.
	 */
	public function testAnUnreadableStoreIsNotFatal(): void {
		$this->assertNull($this->index([], throws: true)->caseTypeByTitle(schemas: self::SCHEMAS, title: 'X'));
		$this->assertSame([], $this->index([], throws: true)->caseTitlesFor(schemas: self::SCHEMAS, caseTypeId: 'ct-1'));
	}//end testAnUnreadableStoreIsNotFatal()

	public function testWithNoObjectServiceNothingIsFound(): void {
		$this->assertNull($this->index([], noStore: true)->caseTypeByTitle(schemas: self::SCHEMAS, title: 'X'));
		$this->assertSame([], $this->index([], noStore: true)->caseTitlesFor(schemas: self::SCHEMAS, caseTypeId: 'ct-1'));
	}//end testWithNoObjectServiceNothingIsFound()

	public function testCaseTitlesAreReturnedForThatCaseType(): void {
		$index = $this->index([
			['id' => 'c-1', 'title' => 'Dakkapel Kerkstraat 1'],
			['id' => 'c-2', 'title' => 'Aanbouw Molenweg 4'],
		]);

		$this->assertSame(
			['Dakkapel Kerkstraat 1', 'Aanbouw Molenweg 4'],
			$index->caseTitlesFor(schemas: self::SCHEMAS, caseTypeId: 'ct-1')
		);
	}//end testCaseTitlesAreReturnedForThatCaseType()

	public function testCaseTitlesAreScopedToTheCaseType(): void {
		$index = $this->index([]);
		$index->caseTitlesFor(schemas: self::SCHEMAS, caseTypeId: 'ct-1');

		$this->assertSame('ct-1', $this->seen[0]['caseType']);
		$this->assertSame('case', $this->seen[0]['@self']['schema']);
	}//end testCaseTitlesAreScopedToTheCaseType()

	/**
	 * A row with no title contributes nothing rather than an empty string, which
	 * would make `in_array('', $present)` match every untitled case.
	 */
	public function testUntitledRowsAreSkipped(): void {
		$index = $this->index([['id' => 'c-1'], ['id' => 'c-2', 'title' => 'Real']]);

		$this->assertSame(['Real'], $index->caseTitlesFor(schemas: self::SCHEMAS, caseTypeId: 'ct-1'));
	}//end testUntitledRowsAreSkipped()
}//end class
