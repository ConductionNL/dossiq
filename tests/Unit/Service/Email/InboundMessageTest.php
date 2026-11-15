<?php

/**
 * An inbound message: built from a row, enriched from its source, its sender and the messages it answers.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\InboundMessage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\InboundMessage
 */
class InboundMessageTest extends TestCase {

	public function testFromRowReadsTheRowAndLowerCasesHeaderNames(): void {
		$message = InboundMessage::fromRow(
			row: [
				'uid' => '17',
				'mailMessageId' => 'abc@mail',
				'subject' => 'Bezwaar',
				'from' => 'Jan <JAN@Example.com>',
				'headers' => ['X-Spam-Flag' => 'NO', 'Auto-Submitted' => 3],
			],
			accountId: 4,
			mailbox: 'INBOX',
		);

		self::assertSame(4, $message->accountId);
		self::assertSame('INBOX', $message->mailbox);
		self::assertSame(17, $message->uid);
		self::assertSame('abc@mail', $message->messageId);
		self::assertSame(['x-spam-flag' => 'NO', 'auto-submitted' => '3'], $message->headers);
		self::assertSame('NO', $message->header(name: 'X-SPAM-FLAG'));
		self::assertSame('', $message->header(name: 'list-id'));
	}//end testFromRowReadsTheRowAndLowerCasesHeaderNames()

	public function testTheRowsOwnAccountAndMailboxWinAndMessageIdIsPreferred(): void {
		$message = InboundMessage::fromRow(
			row: ['accountId' => 9, 'mailbox' => 'Archief', 'messageId' => 'own@id', 'mailMessageId' => 'other@id', 'headers' => 'not a list'],
			accountId: 4,
			mailbox: 'INBOX',
		);

		self::assertSame(9, $message->accountId);
		self::assertSame('Archief', $message->mailbox);
		self::assertSame('own@id', $message->messageId);
		self::assertSame([], $message->headers);
	}//end testTheRowsOwnAccountAndMailboxWinAndMessageIdIsPreferred()

	public function testHasHeaderSeparatesPresentAndEmptyFromAbsent(): void {
		$message = new InboundMessage(accountId: 1, mailbox: 'INBOX', uid: 1, headers: ['auto-submitted' => '']);

		self::assertTrue($message->hasHeader(name: 'Auto-Submitted'));
		self::assertFalse($message->hasHeader(name: 'List-Id'));
	}//end testHasHeaderSeparatesPresentAndEmptyFromAbsent()

	public function testTheSenderAddressIsTheBracketedPartLowerCased(): void {
		self::assertSame('jan@example.com', (new InboundMessage(accountId: 1, mailbox: 'I', uid: 1, from: '"Jan, de Vries" <Jan@Example.COM>'))->senderAddress());
		self::assertSame('jan@example.com', InboundMessage::addressIn(value: '  JAN@example.com '));
	}//end testTheSenderAddressIsTheBracketedPartLowerCased()

	public function testReferencedIdsComeFromInReplyToAndReferencesDeduplicated(): void {
		$message = new InboundMessage(accountId: 1, mailbox: 'I', uid: 1, headers: [
			'in-reply-to' => '<b@x>',
			'references' => "<a@x>\n <b@x> <c@x>",
		]);

		self::assertSame(['b@x', 'a@x', 'c@x'], $message->referencedMessageIds());
	}//end testReferencedIdsComeFromInReplyToAndReferencesDeduplicated()

	public function testBareReferencedIdsStillCountAndNoneMeansEmpty(): void {
		$bare = new InboundMessage(accountId: 1, mailbox: 'I', uid: 1, headers: ['references' => 'a@x  b@x a@x']);
		self::assertSame(['a@x', 'b@x'], $bare->referencedMessageIds());

		self::assertSame([], (new InboundMessage(accountId: 1, mailbox: 'I', uid: 1))->referencedMessageIds());
	}//end testBareReferencedIdsStillCountAndNoneMeansEmpty()

	public function testWithSourceParsesFoldedRepeatedHeadersButKeepsKnownOnes(): void {
		$source = "Subject: Hallo\r\nX-Mailer: Mass\r\n  Mail 3\r\nReceived: one\r\nReceived: two\r\nX-Spam-Flag: YES\r\n: no name\r\nbroken line\r\n\r\nX-Body: not a header\r\n";
		$message = new InboundMessage(accountId: 1, mailbox: 'I', uid: 5, subject: 'Hallo', headers: ['x-spam-flag' => 'NO']);

		$enriched = $message->withSource(source: $source);

		self::assertSame($source, $enriched->source);
		self::assertSame(5, $enriched->uid);
		self::assertSame('Mass Mail 3', $enriched->header(name: 'x-mailer'));
		self::assertSame("one\ntwo", $enriched->header(name: 'received'));
		// The header already known on the message wins over the parsed source.
		self::assertSame('NO', $enriched->header(name: 'x-spam-flag'));
		self::assertFalse($enriched->hasHeader(name: 'x-body'));
		self::assertFalse($enriched->hasHeader(name: ''));
		// The original message is not changed by enriching it.
		self::assertSame(['x-spam-flag' => 'NO'], $message->headers);
	}//end testWithSourceParsesFoldedRepeatedHeadersButKeepsKnownOnes()

	public function testToArrayCarriesTheMessageIdUnderBothNames(): void {
		$message = new InboundMessage(accountId: 2, mailbox: 'INBOX', uid: 3, messageId: 'm@id', subject: 's', from: 'f', to: 't', sentAt: '2026-01-01');

		self::assertSame(
			['accountId' => 2, 'mailbox' => 'INBOX', 'uid' => 3, 'messageId' => 'm@id', 'mailMessageId' => 'm@id', 'subject' => 's', 'from' => 'f', 'to' => 't', 'sentAt' => '2026-01-01'],
			$message->toArray()
		);
	}//end testToArrayCarriesTheMessageIdUnderBothNames()
}//end class
