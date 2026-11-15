<?php

/**
 * Mail Header Reader Test
 *
 * The header fields of a collected mail, read through OpenRegister's parser
 * contract (here a fake that parses the real fixture bytes), turned into
 * bare lower-cased addresses, and marked unreadable when they cannot be read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-review-reports/specs/woo-review-reports/spec.md#requirement-collected-mail-keeps-its-header-fields-req-wrr-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\MailHeaderReader;
use OCA\Dossiq\Tests\Support\HeaderParsingTextExtraction;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zaakdossier\MailHeaderReader
 */
class MailHeaderReaderTest extends TestCase {

	/**
	 * A file double over the given bytes.
	 *
	 * @param string $name    The file name.
	 * @param string $content The bytes.
	 *
	 * @return File&MockObject The file.
	 */
	private function file(string $name, string $content): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(7);
		$file->method('fopen')->willReturnCallback(
			static function () use ($content) {
				$stream = fopen('php://memory', 'r+');
				fwrite($stream, $content);
				rewind($stream);
				return $stream;
			}
		);

		return $file;
	}//end file()

	/**
	 * The reader over a container answering the given extraction service, or none.
	 *
	 * @param object|null     $extraction The extraction service, null when OpenRegister has none.
	 * @param LoggerInterface $logger     The logger.
	 *
	 * @return MailHeaderReader The reader.
	 */
	private function reader(?object $extraction, ?LoggerInterface $logger = null): MailHeaderReader {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($extraction !== null);
		$container->method('get')->willReturn($extraction);

		return new MailHeaderReader(container: $container, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end reader()

	/**
	 * A mail is told by its extension or its MIME type; anything else is no mail.
	 *
	 * @return void
	 */
	public function testAMailIsToldByExtensionOrType(): void {
		$reader = $this->reader(extraction: null);

		self::assertTrue($reader->isMail(fileName: 'Planning.EML'));
		self::assertTrue($reader->isMail(fileName: 'export.msg'));
		self::assertTrue($reader->isMail(fileName: 'bericht', mimeType: 'message/rfc822'));
		self::assertFalse($reader->isMail(fileName: 'notulen.pdf', mimeType: 'application/pdf'));
		self::assertFalse($reader->isMail(fileName: 'eml.txt'));
	}//end testAMailIsToldByExtensionOrType()

	/**
	 * The real fixture's headers come back as bare, lower-cased addresses with date and id.
	 *
	 * @return void
	 */
	public function testTheFixtureHeadersBecomeBareAddresses(): void {
		$bytes = (string)file_get_contents(dirname(__DIR__, 3) . '/Fixtures/mail/planning-stationsweg.eml');

		$mail = $this->reader(extraction: new HeaderParsingTextExtraction())->read(file: $this->file(name: 'planning.eml', content: $bytes));

		self::assertSame('a.jansen@gemeente.nl', $mail['from']);
		self::assertSame(['b@aannemer.nl'], $mail['to']);
		self::assertSame(['c@gemeente.nl'], $mail['cc']);
		self::assertSame('20240312091500.4711@mail.gemeente.nl', $mail['messageId']);
		self::assertSame('2024-03-12T09:15:00+01:00', $mail['date']);
	}//end testTheFixtureHeadersBecomeBareAddresses()

	/**
	 * Quoted commas, repeated addresses and tokens without an address are handled; no date, no date key.
	 *
	 * @return void
	 */
	public function testAddressListsAreSplitAndCleaned(): void {
		$bytes = "From: \"Bakker, J.\" <J.Bakker@Example.org>\r\n"
			. "To: \"De Vries, P.\" <p@example.org>, P@example.org, undisclosed-recipients:;\r\n"
			. "Message-ID: <x@y>\r\n\r\nbody";

		$mail = $this->reader(extraction: new HeaderParsingTextExtraction())->read(file: $this->file(name: 'a.eml', content: $bytes));

		self::assertSame('j.bakker@example.org', $mail['from']);
		self::assertSame(['p@example.org'], $mail['to']);
		self::assertSame([], $mail['cc']);
		self::assertSame('x@y', $mail['messageId']);
		self::assertArrayNotHasKey('date', $mail);
	}//end testAddressListsAreSplitAndCleaned()

	/**
	 * An Outlook .msg, an absent parser, a parser that throws and a mail without sender are unreadable.
	 *
	 * @return void
	 */
	public function testWhatCannotBeReadIsMarkedUnreadable(): void {
		$parser = new HeaderParsingTextExtraction();
		self::assertSame(['unreadable' => true], $this->reader(extraction: $parser)->read(file: $this->file(name: 'export.msg', content: 'x')));
		self::assertSame([], $parser->parsed);

		self::assertSame(['unreadable' => true], $this->reader(extraction: null)->read(file: $this->file(name: 'a.eml', content: "From: a@b.nl\r\n\r\n")));

		$headerless = (string)file_get_contents(dirname(__DIR__, 3) . '/Fixtures/mail/headerless.eml');
		self::assertSame(['unreadable' => true], $this->reader(extraction: $parser)->read(file: $this->file(name: 'h.eml', content: $headerless)));

		$throwing = new class {
			public function parseEmlStructured(File $file): object {
				throw new RuntimeException('From: secret@person.nl could not be parsed');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with(
			self::anything(),
			self::callback(static fn (array $context): bool => ($context['error'] === RuntimeException::class && str_contains(json_encode($context), 'secret') === false))
		);
		self::assertSame(['unreadable' => true], $this->reader(extraction: $throwing, logger: $logger)->read(file: $this->file(name: 'a.eml', content: 'x')));
	}//end testWhatCannotBeReadIsMarkedUnreadable()
}//end class
