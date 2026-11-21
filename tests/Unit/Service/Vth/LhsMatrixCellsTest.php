<?php

/**
 * LHS matrix cells tests
 *
 * The decision rules the cells of an LHS matrix become.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Vth;

use OCA\Dossiq\Service\Vth\LhsMatrixCells;
use PHPUnit\Framework\TestCase;

/**
 * One rule per cell, none when a cell is off its axes.
 *
 * @covers \OCA\Dossiq\Service\Vth\LhsMatrixCells
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class LhsMatrixCellsTest extends TestCase {

	/**
	 * The axes every test uses.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private const AXES = [
		'severity' => ['A', 'B'],
		'behaviour' => [1, 2],
		'actorType' => ['burger'],
	];

	/**
	 * Each cell becomes a rule in order, a missing note and intervention read empty.
	 *
	 * @return void
	 */
	public function testEachCellBecomesARule(): void {
		$rules = (new LhsMatrixCells())->rules(
			cells: [
				['severity' => 'B', 'behaviour' => 2, 'actorType' => 'burger', 'intervention' => 'boete', 'note' => 'n'],
				['severity' => 'A', 'behaviour' => 1, 'actorType' => 'burger'],
			],
			axes: self::AXES
		);

		self::assertSame(
			[
				['id' => 'B:2:burger', 'annotation' => 'n', 'inputEntries' => ['B', '2', 'burger'], 'outputEntries' => ['boete']],
				['id' => 'A:1:burger', 'annotation' => '', 'inputEntries' => ['A', '1', 'burger'], 'outputEntries' => ['']],
			],
			$rules
		);
	}//end testEachCellBecomesARule()

	/**
	 * One cell off an axis, by value or by type, refuses the whole set.
	 *
	 * @return void
	 */
	public function testACellOffItsAxesRefusesAll(): void {
		$onAxes = ['severity' => 'A', 'behaviour' => 1, 'actorType' => 'burger'];
		$cells = new LhsMatrixCells();

		self::assertNull($cells->rules(cells: [$onAxes, ['severity' => 'C', 'behaviour' => 1, 'actorType' => 'burger']], axes: self::AXES));
		self::assertNull($cells->rules(cells: [$onAxes, ['severity' => 'A', 'behaviour' => '1', 'actorType' => 'burger']], axes: self::AXES));
		self::assertNull($cells->rules(cells: [['severity' => 'A', 'behaviour' => 1]], axes: self::AXES));
		self::assertSame([], $cells->rules(cells: [], axes: self::AXES));
	}//end testACellOffItsAxesRefusesAll()
}//end class
