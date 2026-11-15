<?php

/**
 * Recording a published Woo decision's withheld documents.
 *
 * The ledger hands OpenCatalogi the positions and refusal grounds of the
 * documents assessed `niet_openbaar` (and only those), and returns the
 * recorder's answer with its sentence. Without a recorder, the answer is
 * "not recorded, OpenCatalogi too old" and no sentence: a decision is never
 * blocked on its withheld list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-decision-records-what-was-withheld/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-published-woo-decision-records-its-withheld-documents-in-opencatalogi-req-wrw-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\Publication\WithheldListRecorder;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Woo\WooCaseLedger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * WooCaseLedger::recordWithheld().
 *
 * @covers \OCA\Dossiq\Woo\WooCaseLedger
 * @uses \OCA\Dossiq\Service\Publication\WithheldEntries
 */
class WooCaseLedgerWithheldTest extends TestCase {

	/**
	 * The ledger over the given recorder.
	 *
	 * @param WithheldListRecorder|null $recorder The recorder.
	 *
	 * @return WooCaseLedger
	 */
	private function ledger(?WithheldListRecorder $recorder): WooCaseLedger {
		return new WooCaseLedger(
			settingsService: $this->createMock(SettingsService::class),
			logger: $this->createMock(LoggerInterface::class),
			withheldRecorder: $recorder
		);
	}//end ledger()

	/**
	 * Only the withheld documents are recorded, by position in the sorted inventory, with their grounds.
	 *
	 * @return void
	 */
	public function testTheWithheldDocumentsAreRecordedWithTheirGrounds(): void {
		$answer = ['status' => 'recorded', 'count' => 2];
		$recorder = $this->createMock(WithheldListRecorder::class);
		$recorder->expects($this->once())->method('record')->with(
			'pub-1',
			[
				['position' => 1, 'grounds' => ['5.1.2e']],
				['position' => 3, 'grounds' => ['5.1.1c', '5.2']],
			]
		)->willReturn($answer);
		$recorder->expects($this->once())->method('describe')->with($answer)->willReturn('2 withheld documents recorded.');

		$result = $this->ledger($recorder)->recordWithheld(
			publicationId: 'pub-1',
			assessments: [
				['documentRef' => 'doc-c', 'classification' => 'niet_openbaar', 'weigeringsgronden' => [' 5.1.1c ', '', 5, '5.2']],
				['documentRef' => 'doc-a', 'classification' => 'niet_openbaar', 'weigeringsgronden' => ['5.1.2e']],
				['documentRef' => 'doc-b', 'classification' => 'openbaar'],
				['classification' => 'niet_openbaar'],
			]
		);

		$this->assertSame(['record' => $answer, 'note' => '2 withheld documents recorded.'], $result);
	}//end testTheWithheldDocumentsAreRecordedWithTheirGrounds()

	/**
	 * Without a recorder nothing is recorded and the reason is that OpenCatalogi is too old.
	 *
	 * @return void
	 */
	public function testWithoutARecorderNothingIsRecorded(): void {
		$this->assertSame(
			['record' => ['status' => 'not-recorded', 'reason' => WithheldListRecorder::REASON_TOO_OLD], 'note' => ''],
			$this->ledger(null)->recordWithheld(publicationId: 'pub-1', assessments: [['documentRef' => 'd', 'classification' => 'niet_openbaar']])
		);
	}//end testWithoutARecorderNothingIsRecorded()
}//end class
