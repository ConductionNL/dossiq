<?php

/**
 * Unit tests for ColumnMoveTally and ColumnMove: a column move knows whether
 * its destination already exists, and the tally counts renames and copies
 * apart while ignoring a move that did nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\ColumnMove;
use OCA\Dossiq\Repair\Support\ColumnMoveTally;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\Support\ColumnMoveTally
 * @covers \OCA\Dossiq\Repair\Support\ColumnMove
 */
class ColumnMoveTallyTest extends TestCase {

	public function testANewTallyHasCountedNothing(): void {
		$tally = new ColumnMoveTally();

		$this->assertSame([0, 0, 0], [$tally->renamed, $tally->copied, $tally->refused]);
	}//end testANewTallyHasCountedNothing()

	public function testRenamesAndCopiesAreCountedApart(): void {
		$tally = new ColumnMoveTally();
		$tally->count(outcome: ColumnMoveTally::RENAMED);
		$tally->count(outcome: ColumnMoveTally::COPIED);
		$tally->count(outcome: ColumnMoveTally::COPIED);

		$this->assertSame(1, $tally->renamed);
		$this->assertSame(2, $tally->copied);
		$this->assertSame(0, $tally->refused);
	}//end testRenamesAndCopiesAreCountedApart()

	public function testAMoveThatDidNothingIsNotCounted(): void {
		$tally = new ColumnMoveTally();
		$tally->count(outcome: '');
		$tally->count(outcome: 'something else');

		$this->assertSame([0, 0, 0], [$tally->renamed, $tally->copied, $tally->refused]);
	}//end testAMoveThatDidNothingIsNotCounted()

	public function testTheTargetExistsOnlyWhenTheTableHasTheNewColumn(): void {
		$present = new ColumnMove(old: 'zaaknummer', new: 'case_number', columns: ['id', 'zaaknummer', 'case_number']);
		$absent  = new ColumnMove(old: 'zaaknummer', new: 'case_number', columns: ['id', 'zaaknummer', 'Case_Number']);

		$this->assertTrue($present->targetExists());
		$this->assertFalse($absent->targetExists());
		$this->assertSame('zaaknummer', $present->old);
		$this->assertSame('case_number', $present->new);
	}//end testTheTargetExistsOnlyWhenTheTableHasTheNewColumn()
}//end class
