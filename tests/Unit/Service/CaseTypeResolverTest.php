<?php

/**
 * Unit tests for CaseTypeResolver — the effective blueprint of a child type.
 *
 * 🔴 THE FAILURE THIS GUARDS IS SILENT. A child type that declares no statuses
 * of its own has, as far as every existing reader is concerned, NO statuses:
 * `statusType where caseType = child` is an empty list, not an error. A case of
 * that type then cannot be moved, and nothing anywhere says why. So the tests
 * that matter here are the ones where the child declares NOTHING, and the ones
 * where a reader would otherwise have stopped one level up.
 *
 * The double models what the real ObjectService does: `find` by id, and
 * `searchObjects` filtered SERVER-side on the `caseType` back-reference. The
 * filter is asserted rather than assumed — a resolver that fetched every status
 * type and filtered in PHP would pass a test that fed it only the right rows,
 * and would drop rows the first page did not contain in production.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/specs/property-definition-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CaseTypeResolverTest extends TestCase {

	/**
	 * A resolver over a fixed store.
	 *
	 * @param array<string, array<string, mixed>> $caseTypes Case types, keyed by id.
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema key.
	 * @param boolean $throws Whether every read throws.
	 *
	 * @return CaseTypeResolver The resolver.
	 */
	private function resolver(array $caseTypes, array $rows = [], bool $throws = false): CaseTypeResolver {
		$objectService = new class($caseTypes, $rows, $throws) {
			/**
			 * @param array<string, array<string, mixed>> $caseTypes Case types, keyed by id.
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 * @param boolean $throws Whether reads throw.
			 */
			public function __construct(
				private array $caseTypes,
				private array $rows,
				private bool $throws,
			) {
			}

			/**
			 * Read one object by id.
			 *
			 * @param string $id The id.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, string $register, string $schema): array {
				if ($this->throws === true) {
					throw new RuntimeException('unreadable');
				}

				return ($this->caseTypes[$id] ?? []);
			}

			/**
			 * Search one schema, filtered on the caseType back-reference.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query): array {
				if ($this->throws === true) {
					throw new RuntimeException('unreadable');
				}

				$schema = (string)($query['@self']['schema'] ?? '');
				$wanted = (string)($query['caseType'] ?? '');

				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static function (array $row) use ($wanted): bool {
							$own = (string)($row['caseType'] ?? '');
							if ($wanted === 'IS NULL') {
								return ($own === '');
							}

							return ($own === $wanted);
						}
					)
				);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : $key)
		);

		return new CaseTypeResolver(new CaseTypeStore($settings));
	}//end resolver()

	/**
	 * A parent, a child that declares nothing, and four statuses on the parent.
	 *
	 * @return CaseTypeResolver The resolver.
	 */
	private function bezwaarResolver(): CaseTypeResolver {
		return $this->resolver(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar', 'processingDeadline' => 'P12W'],
				'child' => [
					'id' => 'child',
					'title' => 'Bezwaar (verkort)',
					'parentCaseType' => 'parent',
				],
			],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'order' => 1, 'caseType' => 'parent'],
					['id' => 's2', 'name' => 'In behandeling', 'order' => 2, 'caseType' => 'parent'],
					['id' => 's3', 'name' => 'Besluitvorming', 'order' => 3, 'caseType' => 'parent'],
					['id' => 's4', 'name' => 'Afgehandeld', 'order' => 4, 'caseType' => 'parent'],
				],
			]
		);
	}//end bezwaarResolver()

	/**
	 * A child that declares no statuses shows its parent's four.
	 */
	public function testAChildInheritsItsParentsStatuses(): void {
		$statuses = $this->bezwaarResolver()->statusTypesFor(caseTypeId: 'child');

		self::assertCount(4, $statuses);
		self::assertSame(
			['Ontvangen', 'In behandeling', 'Besluitvorming', 'Afgehandeld'],
			array_column($statuses, 'name')
		);
	}//end testAChildInheritsItsParentsStatuses()

	/**
	 * Every inherited row says so, so the page can mark it.
	 */
	public function testAnInheritedRowIsMarkedInherited(): void {
		$statuses = $this->bezwaarResolver()->statusTypesFor(caseTypeId: 'child');

		foreach ($statuses as $status) {
			self::assertSame(CaseTypeResolver::ORIGIN_INHERITED, $status['origin']);
			self::assertSame('parent', $status['originCaseType']);
			self::assertSame('Bezwaar', $status['originCaseTypeTitle']);
		}
	}//end testAnInheritedRowIsMarkedInherited()

	/**
	 * The parent's own rows are its own, not inherited from itself.
	 */
	public function testAParentsRowsAreMarkedOwn(): void {
		$statuses = $this->bezwaarResolver()->statusTypesFor(caseTypeId: 'parent');

		self::assertCount(4, $statuses);
		self::assertSame(CaseTypeResolver::ORIGIN_OWN, $statuses[0]['origin']);
	}//end testAParentsRowsAreMarkedOwn()

	/**
	 * A row the child declares with the SAME NAME replaces the parent's.
	 *
	 * Name, not id: ids are minted per install, so a child could not name the
	 * parent row it is overriding by anything else.
	 */
	public function testAChildsRowWithTheSameNameReplacesTheParents(): void {
		$resolver = $this->resolver(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar'],
				'child' => ['id' => 'child', 'title' => 'Kort', 'parentCaseType' => 'parent'],
			],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'order' => 1, 'caseType' => 'parent'],
					['id' => 's2', 'name' => 'Afgehandeld', 'order' => 9, 'caseType' => 'parent'],
					['id' => 'c1', 'name' => 'ontvangen', 'order' => 1, 'colour' => 'red', 'caseType' => 'child'],
				],
			]
		);

		$statuses = $resolver->statusTypesFor(caseTypeId: 'child');

		self::assertCount(2, $statuses);
		self::assertSame('c1', $statuses[0]['id']);
		self::assertSame('red', $statuses[0]['colour']);
		self::assertSame(CaseTypeResolver::ORIGIN_OWN, $statuses[0]['origin']);
	}//end testAChildsRowWithTheSameNameReplacesTheParents()

	/**
	 * The merged list is ordered the way a lifecycle is read.
	 */
	public function testTheMergedListIsOrderedByOrder(): void {
		$resolver = $this->resolver(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar'],
				'child' => ['id' => 'child', 'title' => 'Kort', 'parentCaseType' => 'parent'],
			],
			[
				'status_type_schema' => [
					['id' => 's9', 'name' => 'Afgehandeld', 'order' => 9, 'caseType' => 'parent'],
					['id' => 'c2', 'name' => 'Spoed', 'order' => 2, 'caseType' => 'child'],
					['id' => 's1', 'name' => 'Ontvangen', 'order' => 1, 'caseType' => 'parent'],
				],
			]
		);

		self::assertSame(
			['Ontvangen', 'Spoed', 'Afgehandeld'],
			array_column($resolver->statusTypesFor(caseTypeId: 'child'), 'name')
		);
	}//end testTheMergedListIsOrderedByOrder()

	/**
	 * A child that leaves a deadline empty takes its parent's.
	 */
	public function testADeadlineFallsBackToTheParent(): void {
		$effective = $this->bezwaarResolver()->effectiveCaseType(caseTypeId: 'child');

		self::assertSame('P12W', $effective['processingDeadline']);
	}//end testADeadlineFallsBackToTheParent()

	/**
	 * A child that sets its own deadline keeps it.
	 */
	public function testAChildsOwnDeadlineWins(): void {
		$resolver = $this->resolver(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar', 'processingDeadline' => 'P12W'],
				'child' => [
					'id' => 'child',
					'title' => 'Kort',
					'parentCaseType' => 'parent',
					'processingDeadline' => 'P6W',
				],
			]
		);

		self::assertSame('P6W', $resolver->effectiveCaseType(caseTypeId: 'child')['processingDeadline']);
	}//end testAChildsOwnDeadlineWins()

	/**
	 * 🔴 A FALSE IS AN ANSWER, NOT A SILENCE.
	 *
	 * Only null, '' and [] count as "the child said nothing". A boolean false
	 * that fell back to the parent would turn every child of a type allowing
	 * suspension into one allowing it too, whatever its author set.
	 */
	public function testAnExplicitFalseIsNotOverwrittenByTheParent(): void {
		$resolver = $this->resolver(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar', 'suspensionAllowed' => true],
				'child' => [
					'id' => 'child',
					'title' => 'Kort',
					'parentCaseType' => 'parent',
					'suspensionAllowed' => false,
				],
			]
		);

		self::assertFalse($resolver->effectiveCaseType(caseTypeId: 'child')['suspensionAllowed']);
	}//end testAnExplicitFalseIsNotOverwrittenByTheParent()

	/**
	 * The AVG block falls back too, so a child does not claim to process nothing.
	 */
	public function testTheAvgBlockFallsBackToTheParent(): void {
		$resolver = $this->resolver(
			[
				'parent' => [
					'id' => 'parent',
					'title' => 'Bezwaar',
					'processesPersonalData' => true,
					'personalDataCategories' => ['naw', 'bsn'],
					'legalBasis' => 'public_task',
				],
				'child' => ['id' => 'child', 'title' => 'Kort', 'parentCaseType' => 'parent'],
			]
		);

		$effective = $resolver->effectiveCaseType(caseTypeId: 'child');

		self::assertSame(['naw', 'bsn'], $effective['personalDataCategories']);
		self::assertSame('public_task', $effective['legalBasis']);
	}//end testTheAvgBlockFallsBackToTheParent()

	/**
	 * A chain runs at most three deep, and says which three it used.
	 */
	public function testTheChainStopsAtThreeLevels(): void {
		$resolver = $this->resolver(
			[
				'a' => ['id' => 'a', 'title' => 'A', 'parentCaseType' => 'b'],
				'b' => ['id' => 'b', 'title' => 'B', 'parentCaseType' => 'c'],
				'c' => ['id' => 'c', 'title' => 'C', 'parentCaseType' => 'd'],
				'd' => ['id' => 'd', 'title' => 'D'],
			]
		);

		self::assertSame(
			['A', 'B', 'C'],
			array_column($resolver->chainFor(caseTypeId: 'a'), 'title')
		);
	}//end testTheChainStopsAtThreeLevels()

	/**
	 * A reference that arrives EXPANDED still resolves.
	 *
	 * A `$ref` reaches PHP as a uuid on a plain read and as an object when the
	 * caller asked OpenRegister to expand it. Reading only the string shape is
	 * how a chain silently stops one level up.
	 */
	public function testAnExpandedParentReferenceStillResolves(): void {
		$resolver = $this->resolver(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar'],
				'child' => [
					'id' => 'child',
					'title' => 'Kort',
					'parentCaseType' => ['id' => 'parent', 'title' => 'Bezwaar'],
				],
			]
		);

		self::assertCount(2, $resolver->chainFor(caseTypeId: 'child'));
	}//end testAnExpandedParentReferenceStillResolves()

	/**
	 * Naming yourself as your own parent is a cycle.
	 */
	public function testATypeCannotBeItsOwnParent(): void {
		$resolver = $this->resolver(['a' => ['id' => 'a', 'title' => 'A']]);

		self::assertTrue($resolver->wouldCycle(caseTypeId: 'a', parentCaseTypeId: 'a'));
	}//end testATypeCannotBeItsOwnParent()

	/**
	 * Naming your own descendant as your parent is a cycle.
	 */
	public function testNamingADescendantAsTheParentIsACycle(): void {
		$resolver = $this->resolver(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar'],
				'child' => ['id' => 'child', 'title' => 'Kort', 'parentCaseType' => 'parent'],
			]
		);

		self::assertTrue($resolver->wouldCycle(caseTypeId: 'parent', parentCaseTypeId: 'child'));
	}//end testNamingADescendantAsTheParentIsACycle()

	/**
	 * An unrelated type is not a cycle.
	 */
	public function testAnUnrelatedParentIsNotACycle(): void {
		$resolver = $this->resolver(
			[
				'a' => ['id' => 'a', 'title' => 'A'],
				'b' => ['id' => 'b', 'title' => 'B'],
			]
		);

		self::assertFalse($resolver->wouldCycle(caseTypeId: 'a', parentCaseTypeId: 'b'));
	}//end testAnUnrelatedParentIsNotACycle()

	/**
	 * The refusal NAMES the cycle: an author of a deep chain cannot see which
	 * link closes it.
	 */
	public function testTheRefusalNamesTheCycle(): void {
		$resolver = $this->resolver(
			[
				'parent' => ['id' => 'parent', 'title' => 'Bezwaar'],
				'child' => ['id' => 'child', 'title' => 'Bezwaar (verkort)', 'parentCaseType' => 'parent'],
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/Bezwaar \(verkort\)/');

		$resolver->assertNoCycle(caseTypeId: 'parent', parentCaseTypeId: 'child');
	}//end testTheRefusalNamesTheCycle()

	/**
	 * A valid parent passes the guard without throwing.
	 */
	public function testAValidParentPassesTheGuard(): void {
		$resolver = $this->resolver(
			[
				'a' => ['id' => 'a', 'title' => 'A'],
				'b' => ['id' => 'b', 'title' => 'B'],
			]
		);

		$resolver->assertNoCycle(caseTypeId: 'a', parentCaseTypeId: 'b');

		self::assertFalse($resolver->wouldCycle(caseTypeId: 'a', parentCaseTypeId: 'b'));
	}//end testAValidParentPassesTheGuard()

	/**
	 * An already-stored cycle degrades to a finite chain rather than hanging.
	 */
	public function testAStoredCycleDoesNotLoopForever(): void {
		$resolver = $this->resolver(
			[
				'a' => ['id' => 'a', 'title' => 'A', 'parentCaseType' => 'b'],
				'b' => ['id' => 'b', 'title' => 'B', 'parentCaseType' => 'a'],
			]
		);

		self::assertSame(['A', 'B'], array_column($resolver->chainFor(caseTypeId: 'a'), 'title'));
	}//end testAStoredCycleDoesNotLoopForever()

	/**
	 * A shared attribute belongs to every type, and says it is shared.
	 */
	public function testASharedAttributeIsListedAndMarkedShared(): void {
		$resolver = $this->resolver(
			['a' => ['id' => 'a', 'title' => 'A']],
			[
				'property_definition_schema' => [
					['id' => 'p1', 'name' => 'Zaaknummer', 'caseType' => 'a'],
					['id' => 'p2', 'name' => 'Kenteken'],
				],
			]
		);

		$rows = $resolver->propertyDefinitionsFor(caseTypeId: 'a');
		$byName = array_column($rows, 'origin', 'name');

		self::assertSame(CaseTypeResolver::ORIGIN_OWN, $byName['Zaaknummer']);
		self::assertSame(CaseTypeResolver::ORIGIN_SHARED, $byName['Kenteken']);
	}//end testASharedAttributeIsListedAndMarkedShared()

	/**
	 * A type's OWN attribute wins over a shared one of the same name.
	 */
	public function testAnOwnAttributeWinsOverASharedOne(): void {
		$resolver = $this->resolver(
			['a' => ['id' => 'a', 'title' => 'A']],
			[
				'property_definition_schema' => [
					['id' => 'p1', 'name' => 'Kenteken', 'caseType' => 'a'],
					['id' => 'p2', 'name' => 'Kenteken'],
				],
			]
		);

		$rows = $resolver->propertyDefinitionsFor(caseTypeId: 'a');

		self::assertCount(1, $rows);
		self::assertSame('p1', $rows[0]['id']);
	}//end testAnOwnAttributeWinsOverASharedOne()

	/**
	 * Statuses do NOT pick up shared rows: a status belongs to a lifecycle.
	 */
	public function testStatusesDoNotPickUpSharedRows(): void {
		$resolver = $this->resolver(
			['a' => ['id' => 'a', 'title' => 'A']],
			[
				'status_type_schema' => [
					['id' => 's1', 'name' => 'Ontvangen', 'caseType' => 'a'],
					['id' => 'orphan', 'name' => 'Zwevend'],
				],
			]
		);

		self::assertSame(['Ontvangen'], array_column($resolver->statusTypesFor(caseTypeId: 'a'), 'name'));
	}//end testStatusesDoNotPickUpSharedRows()

	/**
	 * An unreadable store answers an empty blueprint rather than throwing.
	 */
	public function testAnUnreadableStoreAnswersNothing(): void {
		$resolver = $this->resolver(['a' => ['id' => 'a', 'title' => 'A']], [], true);

		self::assertSame([], $resolver->chainFor(caseTypeId: 'a'));
		self::assertSame([], $resolver->statusTypesFor(caseTypeId: 'a'));
		self::assertSame([], $resolver->effectiveCaseType(caseTypeId: 'a'));
	}//end testAnUnreadableStoreAnswersNothing()

	/**
	 * An empty id resolves to nothing rather than to whatever sorts first.
	 */
	public function testAnEmptyIdResolvesToNothing(): void {
		$resolver = $this->resolver(['a' => ['id' => 'a', 'title' => 'A']]);

		self::assertSame([], $resolver->chainFor(caseTypeId: ''));
		self::assertSame([], $resolver->statusTypesFor(caseTypeId: ''));
		self::assertFalse($resolver->wouldCycle(caseTypeId: '', parentCaseTypeId: 'a'));
	}//end testAnEmptyIdResolvesToNothing()

	/**
	 * The blueprint carries the parents it merged, so a page can name them.
	 */
	public function testTheBlueprintNamesTheParentsItMerged(): void {
		$blueprint = $this->bezwaarResolver()->blueprintFor(caseTypeId: 'child');

		self::assertSame(['Bezwaar'], array_column($blueprint['parents'], 'title'));
		self::assertCount(4, $blueprint['statusTypes']);
		self::assertSame('P12W', $blueprint['caseType']['processingDeadline']);
	}//end testTheBlueprintNamesTheParentsItMerged()
}//end class
