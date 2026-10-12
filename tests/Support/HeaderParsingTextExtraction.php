<?php

/**
 * A text extraction service the tests own, which really parses mail headers.
 *
 * OpenRegister's `TextExtractionService::parseEmlStructured()` parses an
 * `.eml` with a MIME parser that lives in OpenRegister's vendor directory, not
 * dossiq's, so the unit suite cannot call it. A mock returning configured
 * headers would prove only what was configured. This fake reads the real
 * bytes of the file it is handed with PHP's own RFC 822 header parser and
 * answers in OpenRegister's shape (`EmlParser::extractHeaders()`): `from` the
 * raw header value, `to` and `cc` lists of address tokens, `date` a
 * DateTimeImmutable or null, `messageId` without angle brackets. A file with
 * no header block answers headers whose fields are all empty, as the real
 * parser does for such a file.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use DateTimeImmutable;
use OCP\Files\File;
use RuntimeException;
use Throwable;

/**
 * Parses the header block of a real mail file, in OpenRegister's answer shape.
 */
final class HeaderParsingTextExtraction {

	/**
	 * Every file handed to the parser, by name.
	 *
	 * @var list<string>
	 */
	public array $parsed = [];

	/**
	 * Parse the file's headers.
	 *
	 * @param File $file The mail file.
	 *
	 * @return object `{headers: array<string, mixed>}`.
	 *
	 * @throws RuntimeException When the file cannot be opened, as the real parser throws.
	 */
	public function parseEmlStructured(File $file): object {
		$this->parsed[] = $file->getName();
		$stream = $file->fopen('r');
		if ($stream === false) {
			throw new RuntimeException('Failed to open EML file stream');
		}

		$bytes = (string)stream_get_contents($stream);
		$block = preg_split('/\r?\n\r?\n/', $bytes, 2)[0];
		$raw = iconv_mime_decode_headers($block, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
		if ($raw === false) {
			$raw = [];
		}

		$date = null;
		if (is_string($raw['Date'] ?? null) === true) {
			try {
				$date = new DateTimeImmutable($raw['Date']);
			} catch (Throwable) {
				$date = null;
			}
		}

		return (object)[
			'headers' => [
				'from' => ($raw['From'] ?? null),
				'to' => $this->tokens(raw: ($raw['To'] ?? null)),
				'cc' => $this->tokens(raw: ($raw['Cc'] ?? null)),
				'subject' => ($raw['Subject'] ?? null),
				'date' => $date,
				'messageId' => (is_string($raw['Message-ID'] ?? null) === true) ? trim($raw['Message-ID'], '<> ') : null,
			],
		];
	}//end parseEmlStructured()

	/**
	 * Split an address list on commas outside quotes, as the real parser does.
	 *
	 * @param mixed $raw The raw header value.
	 *
	 * @return list<string>
	 */
	private function tokens(mixed $raw): array {
		if (is_string($raw) === false || trim($raw) === '') {
			return [];
		}

		$parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $raw);

		return array_values(array_filter(array_map('trim', ($parts ?: [])), static fn (string $part): bool => $part !== ''));
	}//end tokens()
}//end class
