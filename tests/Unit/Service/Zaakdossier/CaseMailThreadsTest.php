<?php

/**
 * Placing a collected mail in its conversation, through the mails the case
 * already holds.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Email\CompoundFileStreams;
use OCA\Dossiq\Service\Email\MessageThreadReader;
use OCA\Dossiq\Service\Zaakdossier\CaseDocumentProvenance;
use OCA\Dossiq\Service\Zaakdossier\CaseMailThreads;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\CaseMailThreads
 * @uses   \OCA\Dossiq\Service\Email\MessageThreadHeaders
 * @uses   \OCA\Dossiq\Service\Email\MessageThreadReader
 * @uses   \OCA\Dossiq\Service\Email\CompoundFileStreams
 */
class CaseMailThreadsTest extends TestCase {

	private const CASE_ID = 'case-1';

	private CaseDocumentProvenance&MockObject $provenance;

	private DocumentRecordStore&MockObject $store;

	/**
	 * The collected mails the provenance reader answers, per document.
	 *
	 * @var array<string, array{threadId: string, messageId: string, parentIds: list<string>, sentAt: string}>
	 */
	private array $collected = [];

	protected function setUp(): void {
		$this->store = $this->createMock(DocumentRecordStore::class);
		$this->provenance = $this->createMock(CaseDocumentProvenance::class);
		$this->provenance->method('threadsForCase')->willReturnCallback(
			function (string $caseId): array {
				self::assertSame(self::CASE_ID, $caseId);
				return $this->collected;
			}
		);
	}//end setUp()

	/**
	 * The conversation fields of a mail with the given headers.
	 *
	 * @param string $headers The header lines.
	 *
	 * @return array<string, mixed>
	 */
	private function placed(string $headers): array {
		return (new CaseMailThreads(provenance: $this->provenance, store: $this->store, reader: new MessageThreadReader(compoundFile: new CompoundFileStreams())))
			->conversationOf(caseId: self::CASE_ID, fileName: 'mail.eml', bytes: $headers . "\n\nbody");
	}//end placed()

	/**
	 * A collected mail with a conversation.
	 *
	 * @param string       $documentId The document.
	 * @param string       $threadId   Its conversation.
	 * @param string       $messageId  Its own id.
	 * @param list<string> $parentIds  What it answers.
	 *
	 * @return void
	 */
	private function collect(string $documentId, string $threadId, string $messageId, array $parentIds = []): void {
		$this->collected[$documentId] = ['threadId' => $threadId, 'messageId' => $messageId, 'parentIds' => $parentIds, 'sentAt' => ''];
	}//end collect()

	/**
	 * A first mail starts a conversation keyed on its own id, dated and without parents.
	 *
	 * @return void
	 */
	public function testAFirstMailStartsItsOwnConversation(): void {
		$fields = $this->placed("Message-ID: <a@x>\nDate: Tue, 12 Mar 2024 09:15:00 +0100");

		self::assertSame(
			['threadId' => 'mail-' . substr(hash('sha256', 'id:a@x'), 0, 32), 'messageId' => 'a@x', 'parentIds' => [], 'sentAt' => '2024-03-12T08:15:00+00:00'],
			$fields
		);
	}//end testAFirstMailStartsItsOwnConversation()

	/**
	 * A reply joins the conversation of the collected mail it answers, whatever that one is keyed on.
	 *
	 * @return void
	 */
	public function testAReplyJoinsTheConversationOfWhatItAnswers(): void {
		$this->collect(documentId: 'doc-b', threadId: 'thread-of-b', messageId: 'b@x', parentIds: ['a@x']);

		$fields = $this->placed("Message-ID: <c@x>\nIn-Reply-To: <b@x>");

		self::assertSame('thread-of-b', $fields['threadId']);
		self::assertSame(['b@x'], $fields['parentIds']);
		self::assertArrayNotHasKey('sentAt', $fields);
	}//end testAReplyJoinsTheConversationOfWhatItAnswers()

	/**
	 * The oldest reference that is collected decides, before a later one.
	 *
	 * @return void
	 */
	public function testTheOldestCollectedReferenceDecides(): void {
		$this->collect(documentId: 'doc-b', threadId: 'thread-of-b', messageId: 'b@x');
		$this->collect(documentId: 'doc-a', threadId: 'thread-of-a', messageId: 'a@x');

		self::assertSame('thread-of-a', $this->placed("Message-ID: <c@x>\nReferences: <a@x> <b@x>")['threadId']);
	}//end testTheOldestCollectedReferenceDecides()

	/**
	 * A mail collected after its reply joins the reply's conversation.
	 *
	 * @return void
	 */
	public function testAnEarlierMailCollectedLateJoinsItsReply(): void {
		$this->collect(documentId: 'doc-b', threadId: 'thread-of-b', messageId: 'b@x', parentIds: ['a@x']);

		self::assertSame('thread-of-b', $this->placed('Message-ID: <a@x>')['threadId']);
	}//end testAnEarlierMailCollectedLateJoinsItsReply()

	/**
	 * A mail that answers one collected conversation and is answered by another joins them into the first.
	 *
	 * @return void
	 */
	public function testAMailLinkingTwoConversationsJoinsThem(): void {
		$this->collect(documentId: 'doc-c', threadId: 'thread-of-c', messageId: 'c@x', parentIds: ['b@x']);
		$this->collect(documentId: 'doc-a', threadId: 'thread-of-a', messageId: 'a@x');
		$this->collect(documentId: 'doc-linked', threadId: 'thread-of-c', messageId: 'd@x', parentIds: ['c@x']);
		$this->store->method('findRecord')->willReturnCallback(
			static fn (string $recordId): ?array => match ($recordId) {
				'doc-c' => ['id' => 'doc-c', 'title' => 'Re: Re: planning', 'provenance' => ['custodian' => 'Afdeling', 'threadId' => 'thread-of-c']],
				'doc-linked' => ['id' => 'doc-linked', 'title' => 'Elders'],
				default => null,
			}
		);
		$this->store->expects(self::once())->method('saveRecord')->with(
			['id' => 'doc-c', 'title' => 'Re: Re: planning', 'provenance' => ['custodian' => 'Afdeling', 'threadId' => 'thread-of-a']]
		)->willReturn('doc-c');

		self::assertSame('thread-of-a', $this->placed("Message-ID: <b@x>\nIn-Reply-To: <a@x>")['threadId']);
	}//end testAMailLinkingTwoConversationsJoinsThem()

	/**
	 * A mail linking only one conversation moves nothing.
	 *
	 * @return void
	 */
	public function testAMailLinkingOneConversationMovesNothing(): void {
		$this->collect(documentId: 'doc-a', threadId: 'thread-of-a', messageId: 'a@x');
		$this->collect(documentId: 'doc-c', threadId: 'thread-of-a', messageId: 'c@x', parentIds: ['b@x']);
		$this->store->expects(self::never())->method('saveRecord');

		self::assertSame('thread-of-a', $this->placed("Message-ID: <b@x>\nIn-Reply-To: <a@x>")['threadId']);
	}//end testAMailLinkingOneConversationMovesNothing()

	/**
	 * Unlinked replies to the same uncollected mail share the conversation of that mail.
	 *
	 * @return void
	 */
	public function testRepliesToAnUncollectedMailShareItsConversation(): void {
		$one = $this->placed("Message-ID: <b@x>\nIn-Reply-To: <a@x>");
		$other = $this->placed("Message-ID: <c@x>\nReferences: <a@x>");

		self::assertSame('mail-' . substr(hash('sha256', 'id:a@x'), 0, 32), $one['threadId']);
		self::assertSame($one['threadId'], $other['threadId']);
	}//end testRepliesToAnUncollectedMailShareItsConversation()

	/**
	 * A mail without threading headers stands alone, keyed on its bytes, even beside a collected mail.
	 *
	 * @return void
	 */
	public function testAHeaderlessMailStandsAlone(): void {
		$this->collect(documentId: 'doc-a', threadId: 'thread-of-a', messageId: 'a@x');
		$service = new CaseMailThreads(provenance: $this->provenance, store: $this->store, reader: new MessageThreadReader(compoundFile: new CompoundFileStreams()));

		$one = $service->conversationOf(caseId: self::CASE_ID, fileName: 'losse.msg', bytes: 'binary outlook one');
		$other = $service->conversationOf(caseId: self::CASE_ID, fileName: 'losse.msg', bytes: 'binary outlook two');

		self::assertSame('mail-' . substr(hash('sha256', 'bytes:binary outlook one'), 0, 32), $one['threadId']);
		self::assertNotSame($one['threadId'], $other['threadId']);
		self::assertSame('', $one['messageId']);
		self::assertSame([], $one['parentIds']);
	}//end testAHeaderlessMailStandsAlone()
}//end class
