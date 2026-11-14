<?php

/**
 * The act seam: every act on a case reaches the class that owns it with
 * its arguments unchanged, and its answer comes back unchanged.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\CaseActs;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseHoldActs;
use OCA\Dossiq\Service\Lifecycle\CaseIncompleteness;
use OCA\Dossiq\Service\Lifecycle\DraftCaseActs;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Lifecycle\CaseActs
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class CaseActsTest extends TestCase {

	/**
	 * Ending acts.
	 *
	 * @var CaseEndingActs&MockObject
	 */
	private CaseEndingActs&MockObject $endings;

	/**
	 * Hold acts.
	 *
	 * @var CaseHoldActs&MockObject
	 */
	private CaseHoldActs&MockObject $holds;

	/**
	 * Draft acts.
	 *
	 * @var DraftCaseActs&MockObject
	 */
	private DraftCaseActs&MockObject $drafts;

	/**
	 * Incompleteness recorder.
	 *
	 * @var CaseIncompleteness&MockObject
	 */
	private CaseIncompleteness&MockObject $incompleteness;

	/**
	 * The seam under test.
	 *
	 * @var CaseActs
	 */
	private CaseActs $acts;

	/**
	 * Set up the owners and the seam.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->endings        = $this->createMock(CaseEndingActs::class);
		$this->holds          = $this->createMock(CaseHoldActs::class);
		$this->drafts         = $this->createMock(DraftCaseActs::class);
		$this->incompleteness = $this->createMock(CaseIncompleteness::class);
		$this->acts           = new CaseActs(
			endings: $this->endings,
			holds: $this->holds,
			drafts: $this->drafts,
			incompleteness: $this->incompleteness
		);
	}//end setUp()

	/**
	 * Finish and abort pass the reason, result type and target status through.
	 *
	 * @return void
	 */
	public function testFinishAndAbortReachTheEndingActs(): void {
		$this->endings->expects($this->once())->method('finish')
			->with('c-1', 'Besluit genomen', 'rt-toegekend', 's-afgerond')
			->willReturn(['act' => 'finish']);
		$this->endings->expects($this->once())->method('abort')
			->with('c-2', 'Ingetrokken door aanvrager', 'rt-ingetrokken', '')
			->willReturn(['act' => 'abort']);

		$this->assertSame(['act' => 'finish'], $this->acts->finish(caseId: 'c-1', reason: 'Besluit genomen', resultTypeId: 'rt-toegekend', toStatus: 's-afgerond'));
		$this->assertSame(['act' => 'abort'], $this->acts->abort(caseId: 'c-2', reason: 'Ingetrokken door aanvrager', resultTypeId: 'rt-ingetrokken', toStatus: ''));
	}//end testFinishAndAbortReachTheEndingActs()

	/**
	 * Archive and unarchive reach the ending acts with the reason.
	 *
	 * @return void
	 */
	public function testArchiveAndUnarchiveReachTheEndingActs(): void {
		$this->endings->expects($this->once())->method('archive')->with('c-1', 'Termijn verstreken')->willReturn(['nomination' => 'vernietigen']);
		$this->endings->expects($this->once())->method('unarchive')->with('c-1', 'Bezwaar')->willReturn(['archiveStatus' => 'nog_te_archiveren']);

		$this->assertSame(['nomination' => 'vernietigen'], $this->acts->archive(caseId: 'c-1', reason: 'Termijn verstreken'));
		$this->assertSame(['archiveStatus' => 'nog_te_archiveren'], $this->acts->unarchive(caseId: 'c-1', reason: 'Bezwaar'));
	}//end testArchiveAndUnarchiveReachTheEndingActs()

	/**
	 * Hold and release reach the hold acts.
	 *
	 * @return void
	 */
	public function testHoldAndReleaseReachTheHoldActs(): void {
		$this->holds->expects($this->once())->method('hold')->with('c-1', 'Wacht op stukken', '2026-12-01')->willReturn(['until' => '2026-12-01']);
		$this->holds->expects($this->once())->method('release')->with('c-1', 'Stukken binnen')->willReturn(['held' => false]);

		$this->assertSame(['until' => '2026-12-01'], $this->acts->hold(caseId: 'c-1', reason: 'Wacht op stukken', until: '2026-12-01'));
		$this->assertSame(['held' => false], $this->acts->releaseHold(caseId: 'c-1', reason: 'Stukken binnen'));
	}//end testHoldAndReleaseReachTheHoldActs()

	/**
	 * Draft begins a draft and promote promotes it.
	 *
	 * @return void
	 */
	public function testDraftAndPromoteReachTheDraftActs(): void {
		$this->drafts->expects($this->once())->method('begin')->with('c-1')->willReturn(['draft' => true]);
		$this->drafts->expects($this->once())->method('promote')->with('c-1')->willReturn(['deadline' => '2027-01-01']);

		$this->assertSame(['draft' => true], $this->acts->draft(caseId: 'c-1'));
		$this->assertSame(['deadline' => '2027-01-01'], $this->acts->promote(caseId: 'c-1'));
	}//end testDraftAndPromoteReachTheDraftActs()

	/**
	 * The fields left empty are recorded as the missing list.
	 *
	 * @return void
	 */
	public function testIncompletenessIsRecordedWithTheFields(): void {
		$this->incompleteness->expects($this->once())->method('record')
			->with('c-1', ['bsn', 'telefoonnummer'])
			->willReturn(['incomplete' => ['bsn', 'telefoonnummer']]);

		$this->assertSame(
			['incomplete' => ['bsn', 'telefoonnummer']],
			$this->acts->recordIncompleteness(caseId: 'c-1', fields: ['bsn', 'telefoonnummer'])
		);
	}//end testIncompletenessIsRecordedWithTheFields()

	/**
	 * A refusal from the owner reaches the caller unchanged.
	 *
	 * @return void
	 */
	public function testARefusalPassesThrough(): void {
		$refusal = new RefusedException(rule: 'archive-role-required', sentence: 'No.', status: RefusedException::STATUS_FORBIDDEN);
		$this->endings->method('archive')->willThrowException($refusal);

		try {
			$this->acts->archive(caseId: 'c-1', reason: 'x');
			$this->fail('Expected the refusal.');
		} catch (RefusedException $e) {
			$this->assertSame($refusal, $e);
		}
	}//end testARefusalPassesThrough()
}//end class
