<?php

/**
 * The journal between staging a derived move and recording it.
 *
 * A staged move is taken at most once, the last staging for a case wins, and
 * a staging that is not a move at all (no case, no target, or the status the
 * case already carries) is never held for the timeline listener to record.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
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
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use OCA\Dossiq\Service\Status\DerivedStatusJournal;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Status\DerivedStatusJournal
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */
class DerivedStatusJournalTest extends TestCase {

	/**
	 * A staged move comes back once, with its from and to.
	 *
	 * @return void
	 */
	public function testAStagedMoveIsTakenOnce(): void {
		$journal = new DerivedStatusJournal();
		$journal->stage(caseId: 'case-1', fromStatus: 'intake', toStatus: 'review');

		self::assertSame(
			expected: ['from' => 'intake', 'to' => 'review'],
			actual: $journal->take(caseId: 'case-1'),
		);
		self::assertNull(actual: $journal->take(caseId: 'case-1'));
	}//end testAStagedMoveIsTakenOnce()

	/**
	 * Nothing staged answers null.
	 *
	 * @return void
	 */
	public function testACaseNothingWasStagedForAnswersNull(): void {
		self::assertNull(actual: (new DerivedStatusJournal())->take(caseId: 'case-1'));
	}//end testACaseNothingWasStagedForAnswersNull()

	/**
	 * The last staging for a case replaces the earlier one.
	 *
	 * @return void
	 */
	public function testTheLastStagingForACaseWins(): void {
		$journal = new DerivedStatusJournal();
		$journal->stage(caseId: 'case-1', fromStatus: 'intake', toStatus: 'review');
		$journal->stage(caseId: 'case-1', fromStatus: 'intake', toStatus: 'decision');

		self::assertSame(
			expected: ['from' => 'intake', 'to' => 'decision'],
			actual: $journal->take(caseId: 'case-1'),
		);
		self::assertNull(actual: $journal->take(caseId: 'case-1'));
	}//end testTheLastStagingForACaseWins()

	/**
	 * Moves of different cases are kept apart, and taking one leaves the other.
	 *
	 * @return void
	 */
	public function testMovesOfDifferentCasesAreKeptApart(): void {
		$journal = new DerivedStatusJournal();
		$journal->stage(caseId: 'case-1', fromStatus: 'intake', toStatus: 'review');
		$journal->stage(caseId: 'case-2', fromStatus: '', toStatus: 'intake');

		self::assertSame(
			expected: ['from' => '', 'to' => 'intake'],
			actual: $journal->take(caseId: 'case-2'),
		);
		self::assertSame(
			expected: ['from' => 'intake', 'to' => 'review'],
			actual: $journal->take(caseId: 'case-1'),
		);
	}//end testMovesOfDifferentCasesAreKeptApart()

	/**
	 * A staging that is not a move is not held.
	 *
	 * @return void
	 */
	public function testAStagingThatIsNotAMoveIsDropped(): void {
		$journal = new DerivedStatusJournal();
		$journal->stage(caseId: '', fromStatus: 'intake', toStatus: 'review');
		$journal->stage(caseId: 'case-1', fromStatus: 'intake', toStatus: '');
		$journal->stage(caseId: 'case-2', fromStatus: 'review', toStatus: 'review');

		self::assertNull(actual: $journal->take(caseId: ''));
		self::assertNull(actual: $journal->take(caseId: 'case-1'));
		self::assertNull(actual: $journal->take(caseId: 'case-2'));
	}//end testAStagingThatIsNotAMoveIsDropped()

	/**
	 * A no-op staging does not erase a real move staged earlier.
	 *
	 * @return void
	 */
	public function testANoOpStagingKeepsTheEarlierMove(): void {
		$journal = new DerivedStatusJournal();
		$journal->stage(caseId: 'case-1', fromStatus: 'intake', toStatus: 'review');
		$journal->stage(caseId: 'case-1', fromStatus: 'review', toStatus: 'review');

		self::assertSame(
			expected: ['from' => 'intake', 'to' => 'review'],
			actual: $journal->take(caseId: 'case-1'),
		);
	}//end testANoOpStagingKeepsTheEarlierMove()
}//end class
