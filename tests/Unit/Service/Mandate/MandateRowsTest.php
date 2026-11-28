<?php

/**
 * Unit tests for the questions asked of mandate rows.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Mandate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Mandate;

use OCA\Dossiq\Service\Mandate\MandateRows;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Mandate\MandateRows
 */
class MandateRowsTest extends TestCase {

	/**
	 * A row is in force from its first day through its last; an open end never lapses.
	 *
	 * @return void
	 */
	public function testARowIsInForceBetweenItsDates(): void {
		$rows = new MandateRows();
		$row = ['validFrom' => '2026-01-01', 'validUntil' => '2026-12-31'];

		self::assertTrue(condition: $rows->isValidOn(row: $row, dateStr: '2026-12-31'));
		self::assertFalse(condition: $rows->isValidOn(row: $row, dateStr: '2027-01-01'));
		self::assertFalse(condition: $rows->isValidOn(row: $row, dateStr: '2025-12-31'));
		self::assertTrue(condition: $rows->isValidOn(row: ['validFrom' => '2026-01-01'], dateStr: '2099-01-01'));
	}//end testARowIsInForceBetweenItsDates()

	/**
	 * A row names its decision and case types, or covers every one when it names none.
	 *
	 * @return void
	 */
	public function testTypeTermsNarrowOnlyWhenNamed(): void {
		$rows = new MandateRows();
		$row = ['terms' => ['decisionTypes' => ['grant'], 'caseTypes' => ['ct-1']]];

		self::assertTrue(condition: $rows->matchesTypes(row: $row, request: ['decisionType' => 'grant', 'caseType' => 'ct-1']));
		self::assertFalse(condition: $rows->matchesTypes(row: $row, request: ['decisionType' => 'refuse', 'caseType' => 'ct-1']));
		self::assertFalse(condition: $rows->matchesTypes(row: $row, request: ['decisionType' => 'grant', 'caseType' => 'ct-2']));
		self::assertTrue(condition: $rows->matchesTypes(row: [], request: ['decisionType' => 'any', 'caseType' => 'ct-9']));
	}//end testTypeTermsNarrowOnlyWhenNamed()

	/**
	 * A role sees its own and unassigned rows, and a primary assignment is chosen first.
	 *
	 * @return void
	 */
	public function testRoleRowsAndThePrimaryAssignment(): void {
		$rows = new MandateRows();

		$forRole = $rows->forRole(rows: [['mandateeRole' => 'r1'], ['mandateeRole' => 'r2'], ['mandateeRole' => '']], roleId: 'r1');
		$first = $rows->firstByAllocation(active: [['allocationType' => 'tijdelijk'], ['allocationType' => 'primair']]);

		self::assertSame(expected: [['mandateeRole' => 'r1', 'unilateral' => true], ['mandateeRole' => '', 'unilateral' => false]], actual: $forRole);
		self::assertSame(expected: ['allocationType' => 'primair'], actual: $first);
	}//end testRoleRowsAndThePrimaryAssignment()
}//end class
