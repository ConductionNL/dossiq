<?php

/**
 * Reading the headers that place a saved e-mail in its conversation, from an
 * `.eml` and from an `.msg`.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\CompoundFileStreams;
use OCA\Dossiq\Service\Email\MessageThreadReader;
use OCA\Dossiq\Tests\Support\CompoundFileBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Email\MessageThreadReader
 * @covers \OCA\Dossiq\Service\Email\MessageThreadHeaders
 * @covers \OCA\Dossiq\Service\Email\CompoundFileStreams
 */
class MessageThreadReaderTest extends TestCase {

	/**
	 * The reader over the real compound file reader.
	 *
	 * @return MessageThreadReader
	 */
	private function reader(): MessageThreadReader {
		return new MessageThreadReader(compoundFile: new CompoundFileStreams());
	}//end reader()

	/**
	 * An `.eml` reply: its own id, what it answers, the folded references chain and its date in UTC.
	 *
	 * @return void
	 */
	public function testAnEmlReplyKeepsItsChain(): void {
		$eml = "Message-ID: <c3@mail.gemeente.nl>\r\n"
			. "In-Reply-To: <b2@aannemer.nl>\r\n"
			. "References: <a1@mail.gemeente.nl>\r\n"
			. "\t<b2@aannemer.nl>\r\n"
			. "Date: Wed, 13 Mar 2024 10:30:00 +0100 (CET)\r\n"
			. "Subject: Re: Planning\r\n"
			. "\r\n"
			. "Message-ID: <in-the-body@example.org>\r\n";

		$headers = $this->reader()->read(fileName: 'antwoord.eml', bytes: $eml);

		self::assertSame('c3@mail.gemeente.nl', $headers->messageId);
		self::assertSame(['b2@aannemer.nl'], $headers->inReplyTo);
		self::assertSame(['a1@mail.gemeente.nl', 'b2@aannemer.nl'], $headers->references);
		self::assertSame('2024-03-13T09:30:00+00:00', $headers->sentAt);
		self::assertSame(['a1@mail.gemeente.nl', 'b2@aannemer.nl'], $headers->parentIds());
		self::assertSame('a1@mail.gemeente.nl', $headers->rootId());
		self::assertFalse($headers->isEmpty());
	}//end testAnEmlReplyKeepsItsChain()

	/**
	 * Header names are matched in any case, the first occurrence wins, and LF line ends work.
	 *
	 * @return void
	 */
	public function testHeaderNamesAreCaseInsensitiveAndTheFirstWins(): void {
		$eml = "message-id: <first@x>\nMESSAGE-ID: <second@x>\nin-reply-to: <parent@x> <other@x>\n\nbody";

		$headers = $this->reader()->read(fileName: 'mail.eml', bytes: $eml);

		self::assertSame('first@x', $headers->messageId);
		self::assertSame(['parent@x', 'other@x'], $headers->inReplyTo);
		self::assertSame([], $headers->references);
		self::assertSame('', $headers->sentAt);
		self::assertSame('parent@x', $headers->rootId());
	}//end testHeaderNamesAreCaseInsensitiveAndTheFirstWins()

	/**
	 * A bare id counts, a phrase without an id does not, and the parents never repeat or hold the own id.
	 *
	 * @return void
	 */
	public function testIdsAreReadWithAndWithoutBrackets(): void {
		$eml = "Message-ID: own@x\nIn-Reply-To: not an id\nReferences: <a@x> <own@x> <a@x>\n\n";

		$headers = $this->reader()->read(fileName: 'mail.eml', bytes: $eml);

		self::assertSame('own@x', $headers->messageId);
		self::assertSame([], $headers->inReplyTo);
		self::assertSame(['a@x', 'own@x'], $headers->references);
		self::assertSame(['a@x'], $headers->parentIds());
	}//end testIdsAreReadWithAndWithoutBrackets()

	/**
	 * A mail without threading headers, or with an unreadable date, is empty of them.
	 *
	 * @return void
	 */
	public function testAMailWithoutThreadingHeadersIsEmpty(): void {
		$headers = $this->reader()->read(fileName: 'losse.eml', bytes: "From: a@x\nDate: not a date at all\n\nHallo");

		self::assertTrue($headers->isEmpty());
		self::assertSame('', $headers->messageId);
		self::assertSame('', $headers->rootId());
		self::assertSame('', $headers->sentAt);
	}//end testAMailWithoutThreadingHeadersIsEmpty()

	/**
	 * An `.msg` is read from its message-level property streams, never from a nested attachment's.
	 *
	 * @return void
	 */
	public function testAnMsgIsReadFromItsPropertyStreams(): void {
		$msg = CompoundFileBuilder::msg(
			properties: [
				'1035' => '<reply@mail.gemeente.nl>',
				'1042' => '<start@mail.gemeente.nl>',
				'1039' => '<start@mail.gemeente.nl>',
				'007D' => "Received: from x\r\nDate: Thu, 14 Mar 2024 08:00:00 +0000\r\nMessage-ID: <transport@x>\r\n\r\n",
			],
			nested: ['__substg1.0_1035001F' => mb_convert_encoding('<attached@x>', 'UTF-16LE', 'UTF-8')],
		);

		$headers = $this->reader()->read(fileName: 'Re planning.MSG', bytes: $msg);

		self::assertSame('reply@mail.gemeente.nl', $headers->messageId);
		self::assertSame(['start@mail.gemeente.nl'], $headers->inReplyTo);
		self::assertSame(['start@mail.gemeente.nl'], $headers->references);
		self::assertSame('2024-03-14T08:00:00+00:00', $headers->sentAt);
	}//end testAnMsgIsReadFromItsPropertyStreams()

	/**
	 * An `.msg` without the id properties falls back to its saved transport headers.
	 *
	 * @return void
	 */
	public function testAnMsgFallsBackToItsTransportHeaders(): void {
		$msg = CompoundFileBuilder::msg(
			properties: ['007D' => "Message-ID: <transport@x>\r\nIn-Reply-To: <earlier@x>\r\n\r\n"],
		);

		$headers = $this->reader()->read(fileName: 'export.msg', bytes: $msg);

		self::assertSame('transport@x', $headers->messageId);
		self::assertSame(['earlier@x'], $headers->inReplyTo);
	}//end testAnMsgFallsBackToItsTransportHeaders()

	/**
	 * An `.msg` without any header, or bytes that are no compound file, carry none.
	 *
	 * @return void
	 */
	public function testAHeaderlessOrDamagedMsgIsEmpty(): void {
		self::assertTrue($this->reader()->read(fileName: 'x.msg', bytes: CompoundFileBuilder::msg(properties: ['0037' => 'Onderwerp']))->isEmpty());
		self::assertTrue($this->reader()->read(fileName: 'x.msg', bytes: 'binary outlook')->isEmpty());
		self::assertTrue($this->reader()->read(fileName: 'x.msg', bytes: "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\xFF", 600))->isEmpty());
	}//end testAHeaderlessOrDamagedMsgIsEmpty()
}//end class
