<?php

/**
 * CaseRelationDetails edge tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use OCA\Dossiq\Service\Relation\CaseRelationDetails;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Relation\CaseRelationDetails
 * @uses \OCA\Dossiq\Service\Relation\RelationDetailsRun
 */
class CaseRelationDetailsEdgesTest extends TestCase {

	/**
	 * A recurring entry is a series counting its occurrences; a missing recurrence is a one-off flow.
	 *
	 * @return void
	 */
	public function testASeriesCountsItsOccurrences(): void {
		$details = new CaseRelationDetails(store: $this->createMock(CaseRelationStore::class), userManager: $this->createMock(IUserManager::class));

		$rows = $details->plannedRows(
			planned: [
				['id' => 's1', 'title' => 'Maandrapport', 'recurrence' => 'monthly', 'occurrences' => ['2026-12-01', '2027-01-01', '2027-02-01']],
				['title' => 'Herinnering', 'occurrences' => 'not a list'],
			]
		);

		self::assertSame(
			[
				[
					'id' => 's1', 'title' => 'Maandrapport', 'group' => 'planned', 'statusRole' => 'planned',
					'plannedKind' => 'series', 'recurrence' => 'monthly', 'date' => '', 'occurrences' => 3,
				],
				[
					'id' => '', 'title' => 'Herinnering', 'group' => 'planned', 'statusRole' => 'planned',
					'plannedKind' => 'flow', 'recurrence' => 'none', 'date' => '', 'occurrences' => 0,
				],
			],
			$rows
		);
	}//end testASeriesCountsItsOccurrences()

	/**
	 * An assignee without a display name is shown by uid; a case without a case type reads no type.
	 *
	 * @return void
	 */
	public function testAnUnknownAssigneeIsShownByUid(): void {
		$store = $this->createMock(CaseRelationStore::class);
		$store->method('fetchCase')->with('b')->willReturn(['identifier' => '2026-1', 'assignee' => 'ghost']);
		$store->expects(self::never())->method('fetchCaseType');
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::once())->method('getDisplayName')->with('ghost')->willReturn(null);

		$rows = (new CaseRelationDetails(store: $store, userManager: $users))->describe(
			rows: [['caseId' => 'b', 'title' => 'Bezwaar', 'aardRelatie' => 'vervolg']],
			canRead: static fn (string $id): bool => true
		);

		self::assertSame('ghost', $rows[0]['assigneeName']);
		self::assertSame('', $rows[0]['caseTypeTitle']);
		self::assertSame('2026-1', $rows[0]['identifier']);
		self::assertSame('vervolg', $rows[0]['group']);
		self::assertSame('', $rows[0]['statusLabel']);
		self::assertSame('', $rows[0]['endDate']);
	}//end testAnUnknownAssigneeIsShownByUid()

	/**
	 * A related case the store no longer has keeps its row as stored, grouped, and without details.
	 *
	 * @return void
	 */
	public function testAVanishedCaseKeepsItsStoredRow(): void {
		$store = $this->createMock(CaseRelationStore::class);
		$store->method('fetchCase')->willReturn(null);
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::never())->method('getDisplayName');

		$rows = (new CaseRelationDetails(store: $store, userManager: $users))->describe(
			rows: [['caseId' => 'gone', 'title' => 'Oud dossier', 'displayLabel' => ' Voorganger ']],
			canRead: static fn (string $id): bool => true
		);

		self::assertSame([['caseId' => 'gone', 'title' => 'Oud dossier', 'displayLabel' => ' Voorganger ', 'group' => 'Voorganger']], $rows);
	}//end testAVanishedCaseKeepsItsStoredRow()

	/**
	 * A row without a case id is never looked up, and only `true` from the reader counts as readable.
	 *
	 * @return void
	 */
	public function testOnlyAReadableCaseIdIsLookedUp(): void {
		$store = $this->createMock(CaseRelationStore::class);
		$store->expects(self::never())->method('fetchCase');
		$asked = [];

		$rows = (new CaseRelationDetails(store: $store, userManager: $this->createMock(IUserManager::class)))->describe(
			rows: [['title' => 'Zonder id'], ['caseId' => 'x', 'title' => 'Twijfel']],
			canRead: static function (string $id) use (&$asked): int {
				$asked[] = $id;
				return 1;
			}
		);

		self::assertSame(['x'], $asked);
		self::assertSame([['title' => 'Zonder id', 'group' => ''], ['caseId' => 'x', 'title' => 'Twijfel', 'group' => '']], $rows);
	}//end testOnlyAReadableCaseIdIsLookedUp()
}//end class
