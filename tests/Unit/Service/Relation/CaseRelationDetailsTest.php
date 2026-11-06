<?php

/**
 * CaseRelationDetails Unit Tests
 *
 * A related case's row carries its number, case type, status and handler, as
 * board DqZaakGerelateerd draws it; a case the reader may not open keeps only
 * the title it already had.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Relation\CaseRelationDetails;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CaseRelationDetails.
 */
class CaseRelationDetailsTest extends TestCase {
	/**
	 * The details over a store holding two cases of one case type.
	 *
	 * @param CaseRelationStore|null $store A store to use instead.
	 *
	 * @return CaseRelationDetails
	 */
	private function details(?CaseRelationStore $store=null): CaseRelationDetails {
		if ($store === null) {
			$store = $this->createMock(originalClassName: CaseRelationStore::class);
			$store->method('fetchCase')->willReturnMap(
				[
					[
						'b',
						[
							'identifier'        => '2025-0311',
							'caseType'          => 'ct',
							'statusPublicLabel' => 'Afgehandeld',
							'statusRole'        => 'closed',
							'endDate'           => '2025-12-12',
							'assignee'          => 'lars',
						],
					],
					[
						'c',
						[
							'identifier'        => '2026-0088',
							'caseType'          => 'ct',
							'statusPublicLabel' => 'In behandeling',
							'statusRole'        => 'in-progress',
							'assignee'          => '',
						],
					],
				]
			);
			$store->method('fetchCaseType')->willReturn(['title' => 'Omgevingsvergunning']);
		}

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['lars', 'Lars de Koning']]);

		return new CaseRelationDetails(store: $store, userManager: $users);
	}//end details()

	/**
	 * A readable related case gets its number, case type, status and handler.
	 *
	 * @return void
	 */
	public function testDescribesAReadableCase(): void {
		$rows = $this->details()->describe(
			rows: [['caseId' => 'b', 'aardRelatie' => 'subject', 'title' => 'Aanleg fietspad Lindelaan']],
			canRead: fn (string $id): bool => true,
		);

		$this->assertSame(expected: 'Aanleg fietspad Lindelaan', actual: $rows[0]['title']);
		$this->assertSame(expected: 'subject', actual: $rows[0]['aardRelatie']);
		$this->assertSame(expected: '2025-0311', actual: $rows[0]['identifier']);
		$this->assertSame(expected: 'Omgevingsvergunning', actual: $rows[0]['caseTypeTitle']);
		$this->assertSame(expected: 'Afgehandeld', actual: $rows[0]['statusLabel']);
		$this->assertSame(expected: 'closed', actual: $rows[0]['statusRole']);
		$this->assertSame(expected: '2025-12-12', actual: $rows[0]['endDate']);
		$this->assertSame(expected: 'Lars de Koning', actual: $rows[0]['assigneeName']);
	}//end testDescribesAReadableCase()

	/**
	 * A case the reader may not open is not read at all, and keeps its row, with its group.
	 *
	 * @return void
	 */
	public function testLeavesAnUnreadableCaseAlone(): void {
		$store = $this->createMock(originalClassName: CaseRelationStore::class);
		$store->expects($this->never())->method('fetchCase');
		$row = ['caseId' => 'b', 'aardRelatie' => 'subject', 'title' => 'Aanleg fietspad Lindelaan'];

		$rows = $this->details(store: $store)->describe(rows: [$row], canRead: fn (string $id): bool => false);

		$this->assertSame(expected: [$row + ['group' => 'subject']], actual: $rows);
	}//end testLeavesAnUnreadableCaseAlone()

	/**
	 * A case type is read once for all its related cases, and an empty handler stays empty.
	 *
	 * @return void
	 */
	public function testReadsEachCaseTypeOnce(): void {
		$store = $this->createMock(originalClassName: CaseRelationStore::class);
		$store->method('fetchCase')->willReturn(['identifier' => 'x', 'caseType' => 'ct', 'assignee' => '']);
		$store->expects($this->once())->method('fetchCaseType')->willReturn(['title' => 'Woo-verzoek']);

		$rows = $this->details(store: $store)->describe(
			rows: [['caseId' => 'b', 'title' => 'B'], ['caseId' => 'c', 'title' => 'C']],
			canRead: fn (string $id): bool => true,
		);

		$this->assertSame(expected: ['Woo-verzoek', 'Woo-verzoek'], actual: array_column($rows, 'caseTypeTitle'));
		$this->assertSame(expected: ['', ''], actual: array_column($rows, 'assigneeName'));
	}//end testReadsEachCaseTypeOnce()
	/**
	 * A related case without a readable title is left out of the list.
	 *
	 * @return void
	 */
	public function testLeavesOutARowWithoutATitle(): void {
		$rows = $this->details()->describe(
			rows: [['caseId' => 'b', 'title' => ''], ['caseId' => 'c', 'title' => 'Advies verlichting fietspad']],
			canRead: fn (string $id): bool => true,
		);

		$this->assertSame(expected: ['c'], actual: array_column($rows, 'caseId'));
	}//end testLeavesOutARowWithoutATitle()
	/**
	 * A relation lists under the label the reader's side carries, else its type.
	 *
	 * @return void
	 */
	public function testGroupsByTheLabelElseTheType(): void {
		$rows = $this->details()->describe(
			rows: [
				['caseId' => 'b', 'aardRelatie' => 'waitsOn', 'displayLabel' => 'Wacht op', 'title' => 'B'],
				['caseId' => 'c', 'aardRelatie' => 'subject', 'title' => 'C'],
			],
			canRead: fn (string $id): bool => false,
		);

		$this->assertSame(expected: ['Wacht op', 'subject'], actual: array_column($rows, 'group'));
	}//end testGroupsByTheLabelElseTheType()

	/**
	 * A planned flow and a series become rows of the planned group.
	 *
	 * @return void
	 */
	public function testListsPlannedFollowUps(): void {
		$rows = $this->details()->plannedRows(
			planned: [
				['id' => 'f1', 'title' => 'Bericht aan verzoeker na publicatie', 'date' => '2026-11-04', 'recurrence' => 'none', 'occurrences' => []],
				['id' => 'f2', 'title' => '', 'date' => '2026-11-05', 'recurrence' => 'none'],
			]
		);

		$this->assertCount(expectedCount: 1, haystack: $rows);
		$this->assertSame(expected: 'planned', actual: $rows[0]['group']);
		$this->assertSame(expected: 'flow', actual: $rows[0]['plannedKind']);
		$this->assertSame(expected: '2026-11-04', actual: $rows[0]['date']);
	}//end testListsPlannedFollowUps()
}//end class
