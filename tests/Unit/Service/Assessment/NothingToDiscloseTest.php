<?php

/**
 * Unit tests for NothingToDisclose and DocumentSearchOutcome: a Woo case has
 * recorded that nothing can be disclosed when a search found nothing and says
 * why, or when every assessed document is withheld and none is outstanding.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Assessment;

use OCA\Dossiq\Service\Assessment\DocumentSearchOutcome;
use OCA\Dossiq\Service\Assessment\NothingToDisclose;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Assessment\NothingToDisclose
 * @covers \OCA\Dossiq\Service\Assessment\DocumentSearchOutcome
 */
class NothingToDiscloseTest extends TestCase {

	private const WITHHELD = [['classification' => 'niet_openbaar'], ['classification' => 'niet_openbaar']];

	private WOODocumentAssessmentService&MockObject $assessments;

	protected function setUp(): void {
		parent::setUp();
		$this->assessments = $this->createMock(WOODocumentAssessmentService::class);
	}//end setUp()

	private function check(): NothingToDisclose {
		return new NothingToDisclose($this->assessments);
	}//end check()

	public function testASearchThatFoundNothingWithANoteIsEnough(): void {
		$this->assessments->expects($this->never())->method('getDocumentsUnderReview');

		$this->assertTrue($this->check()->recorded(['documentSearchOutcome' => 'nothing_found', 'documentSearchNote' => 'Archief doorzocht'], 'case-1', []));
	}//end testASearchThatFoundNothingWithANoteIsEnough()

	public function testNothingFoundNeedsANote(): void {
		$outcome = new DocumentSearchOutcome();

		$this->assertFalse($outcome->recordsNothingFound(['documentSearchOutcome' => 'nothing_found', 'documentSearchNote' => '  ']));
		$this->assertFalse($outcome->recordsNothingFound(['documentSearchOutcome' => 'found', 'documentSearchNote' => 'x']));
		$this->assertFalse($outcome->recordsNothingFound([]));
		$this->assertFalse($this->check()->recorded(['documentSearchOutcome' => 'nothing_found'], 'case-1', []));
	}//end testNothingFoundNeedsANote()

	public function testEverythingWithheldAndNothingOutstandingIsRecorded(): void {
		$this->assessments->expects($this->once())->method('getDocumentsUnderReview')->with('case-1')->willReturn([['id' => 'd-1']]);
		$this->assessments->expects($this->once())->method('getOutstanding')->with('case-1')->willReturn(['count' => '0']);

		$this->assertTrue($this->check()->recorded([], 'case-1', self::WITHHELD));
	}//end testEverythingWithheldAndNothingOutstandingIsRecorded()

	public function testOneDisclosedDocumentMeansSomethingToDisclose(): void {
		$this->assessments->expects($this->never())->method('getDocumentsUnderReview');

		$this->assertFalse($this->check()->recorded([], 'case-1', [['classification' => 'niet_openbaar'], ['classification' => 'openbaar'], []]));
	}//end testOneDisclosedDocumentMeansSomethingToDisclose()

	public function testOutstandingOrNoDocumentsUnderReviewIsNotRecorded(): void {
		$this->assessments->method('getDocumentsUnderReview')->willReturnOnConsecutiveCalls([['id' => 'd-1']], []);
		$this->assessments->method('getOutstanding')->willReturnOnConsecutiveCalls(['count' => 2], ['count' => 0]);

		$this->assertFalse($this->check()->recorded([], 'case-1', self::WITHHELD));
		$this->assertFalse($this->check()->recorded([], 'case-1', self::WITHHELD));
	}//end testOutstandingOrNoDocumentsUnderReviewIsNotRecorded()

	public function testNoAssessmentsOrAFailingReadIsNotRecorded(): void {
		$this->assessments->method('getDocumentsUnderReview')->willThrowException(new RuntimeException('down'));

		$this->assertFalse($this->check()->recorded([], 'case-1', []));
		$this->assertFalse($this->check()->recorded([], 'case-1', self::WITHHELD));
	}//end testNoAssessmentsOrAFailingReadIsNotRecorded()
}//end class
