<?php

/**
 * Unit tests for ColumnMove: a move knows whether its destination column
 * already exists on the table, by exact name.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\ColumnMove;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\Support\ColumnMove
 */
class ColumnMoveTest extends TestCase {

	public function testTheTargetExistsWhenTheTableCarriesIt(): void {
		$move = new ColumnMove(old: 'zaaknummer', new: 'case_number', columns: ['id', 'zaaknummer', 'case_number']);

		$this->assertTrue($move->targetExists());
		$this->assertSame(['zaaknummer', 'case_number'], [$move->old, $move->new]);
	}//end testTheTargetExistsWhenTheTableCarriesIt()

	public function testTheTargetIsFreeWhenOnlyTheOldColumnIsThere(): void {
		$this->assertFalse((new ColumnMove(old: 'zaaknummer', new: 'case_number', columns: ['id', 'zaaknummer']))->targetExists());
	}//end testTheTargetIsFreeWhenOnlyTheOldColumnIsThere()

	public function testTheMatchIsExactAndCaseSensitive(): void {
		$this->assertFalse((new ColumnMove(old: 'a', new: 'case_number', columns: ['CASE_NUMBER', 'case_number_old']))->targetExists());
	}//end testTheMatchIsExactAndCaseSensitive()
}//end class
