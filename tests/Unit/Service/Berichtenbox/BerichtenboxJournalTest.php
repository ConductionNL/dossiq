<?php

/**
 * Unit tests for BerichtenboxJournal: the record a send stores, sent or
 * refused, and the timeline entry each send and status change writes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Berichtenbox;

use OCA\Dossiq\Service\Berichtenbox\BerichtenboxJournal;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Berichtenbox\BerichtenboxJournal
 */
class BerichtenboxJournalTest extends TestCase {

	private CaseTimeline&MockObject $timeline;

	private BerichtenboxJournal $journal;

	private const LETTER = [
		'caseId' => 'case-1',
		'bsn' => '999993653',
		'subject' => 'Besluit',
		'body' => 'Uw aanvraag is toegekend.',
		'typeCode' => 'BESLUIT',
		'attachmentFileId' => 42,
	];

	protected function setUp(): void {
		$this->timeline = $this->createMock(CaseTimeline::class);
		$this->journal = new BerichtenboxJournal($this->timeline);
	}//end setUp()

	public function testASentLetterIsStoredWithTheAdapterAnswer(): void {
		$record = $this->journal->messageRecord(
			self::LETTER,
			['messageId' => 'ext-7', 'status' => 'delivered', 'sentAt' => '2026-11-01T10:00:00+00:00']
		);

		$this->assertSame(
			[
				'caseId' => 'case-1',
				'bsn' => '999993653',
				'subject' => 'Besluit',
				'body' => 'Uw aanvraag is toegekend.',
				'berichtTypeCode' => 'BESLUIT',
				'attachmentFileId' => 42,
				'externalMessageId' => 'ext-7',
				'status' => 'delivered',
				'sentAt' => '2026-11-01T10:00:00+00:00',
			],
			$record
		);
	}//end testASentLetterIsStoredWithTheAdapterAnswer()

	public function testAMissingStatusDefaultsToSentWithANowTimestamp(): void {
		$record = $this->journal->messageRecord(self::LETTER, []);

		$this->assertNull($record['externalMessageId']);
		$this->assertSame('sent', $record['status']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $record['sentAt']);
		$this->assertArrayNotHasKey('lastError', $record);
	}//end testAMissingStatusDefaultsToSentWithANowTimestamp()

	public function testARefusalIsStoredWithItsReasonAndNoExternalId(): void {
		$record = $this->journal->messageRecord(
			self::LETTER,
			['refused' => true, 'error' => 'No consent', 'messageId' => 'ignored']
		);

		$this->assertNull($record['externalMessageId']);
		$this->assertSame('refused', $record['status']);
		$this->assertNull($record['sentAt']);
		$this->assertSame('No consent', $record['lastError']);
		$this->assertSame('999993653', $record['bsn']);
	}//end testARefusalIsStoredWithItsReasonAndNoExternalId()

	public function testATruthyButNonTrueRefusedIsASend(): void {
		$record = $this->journal->messageRecord(self::LETTER, ['refused' => 1, 'status' => 'sent']);

		$this->assertSame('sent', $record['status']);
		$this->assertArrayNotHasKey('lastError', $record);
	}//end testATruthyButNonTrueRefusedIsASend()

	public function testASendIsAPublicEntryWithoutTheBsn(): void {
		$this->timeline->expects($this->once())
			->method('record')
			->with(
				caseId: 'case-1',
				kind: TimelineKinds::PORTAL_MESSAGE,
				message: 'Besluit',
				fields: ['subject' => 'Besluit', 'messageId' => 'ext-7', 'status' => 'sent'],
				visibility: CaseTimeline::PUBLIC_ENTRY,
			);

		$this->journal->recordSend('case-1', 'Besluit', ['messageId' => 'ext-7'], ['status' => 'sent', 'bsn' => '999993653']);
	}//end testASendIsAPublicEntryWithoutTheBsn()

	public function testARefusedSendIsAnInternalEntryNamingTheReason(): void {
		$this->timeline->expects($this->once())
			->method('record')
			->with(
				caseId: 'case-1',
				kind: TimelineKinds::PORTAL_MESSAGE,
				message: 'Besluit -- not sent: No consent',
				fields: ['subject' => 'Besluit', 'messageId' => '', 'status' => 'refused'],
				visibility: CaseTimeline::INTERNAL,
			);

		$this->journal->recordSend('case-1', 'Besluit', ['refused' => true, 'error' => 'No consent'], ['status' => 'refused']);
	}//end testARefusedSendIsAnInternalEntryNamingTheReason()

	public function testAStatusChangeIsAnInternalEntry(): void {
		$this->timeline->expects($this->once())
			->method('record')
			->with(
				caseId: 'case-1',
				kind: TimelineKinds::PORTAL_MESSAGE,
				message: 'Besluit -- delivered',
				fields: ['subject' => 'Besluit', 'messageId' => 'ext-7', 'status' => 'delivered'],
				visibility: CaseTimeline::INTERNAL,
			);

		$this->journal->recordStatus(['caseId' => 'case-1', 'subject' => 'Besluit'], 'ext-7', 'delivered', '');
	}//end testAStatusChangeIsAnInternalEntry()

	public function testAFailedStatusNamesTheProviderReason(): void {
		$this->timeline->expects($this->once())
			->method('record')
			->with($this->anything(), $this->anything(), 'Besluit -- not delivered: Mailbox closed');

		$this->journal->recordStatus(['caseId' => 'case-1', 'subject' => 'Besluit'], 'ext-7', 'failed', 'Mailbox closed');
	}//end testAFailedStatusNamesTheProviderReason()

	public function testAFailedStatusWithoutReasonSaysSo(): void {
		$this->timeline->expects($this->once())
			->method('record')
			->with($this->anything(), $this->anything(), 'Besluit -- not delivered: the provider gave no reason');

		$this->journal->recordStatus(['caseId' => 'case-1', 'subject' => 'Besluit'], 'ext-7', 'failed', '');
	}//end testAFailedStatusWithoutReasonSaysSo()

	public function testAStatusWithoutACaseWritesNothing(): void {
		$this->timeline->expects($this->never())->method('record');

		$this->journal->recordStatus(['subject' => 'Besluit'], 'ext-7', 'delivered', '');
		$this->journal->recordStatus(['caseId' => '', 'subject' => 'Besluit'], 'ext-7', 'delivered', '');
	}//end testAStatusWithoutACaseWritesNothing()
}//end class
