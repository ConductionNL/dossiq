<?php

/**
 * Dossiq Message Thread Reader
 *
 * Reads the headers that place a saved e-mail in its conversation from the
 * file's bytes: an `.eml` as RFC 5322 text, an `.msg` (Outlook's compound
 * file) through its message-level property streams and, failing those, its
 * saved transport headers.
 *
 * Nothing here parses a body or an attachment, and nothing is logged: a
 * header the file does not carry is simply absent.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Reads a saved e-mail's threading headers.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */
class MessageThreadReader {

	/**
	 * The `.msg` property tags read, by the header they hold.
	 */
	private const MSG_TAGS = [
		'message-id' => '1035',
		'in-reply-to' => '1042',
		'references' => '1039',
	];

	/**
	 * The `.msg` property tag of the saved transport headers.
	 */
	private const MSG_TRANSPORT_HEADERS = '007D';

	/**
	 * The shapes of an RFC 5322 `Date`, with and without the weekday and the seconds.
	 */
	private const DATE_FORMATS = ['!D, j M Y H:i:s O', '!j M Y H:i:s O', '!D, j M Y H:i O', '!j M Y H:i O'];

	/**
	 * Constructor.
	 *
	 * @param CompoundFileStreams $compoundFile Reads an `.msg`'s property streams.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CompoundFileStreams $compoundFile,
	) {
	}//end __construct()

	/**
	 * Read the threading headers of a saved e-mail.
	 *
	 * An `.msg` is read from its compound file; anything else as RFC 5322 text.
	 *
	 * @param string $fileName The file name.
	 * @param string $bytes    The file's bytes.
	 *
	 * @return MessageThreadHeaders The headers; every field empty when the file carries none.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 */
	public function read(string $fileName, string $bytes): MessageThreadHeaders {
		if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'msg') {
			return $this->fromMsg(bytes: $bytes);
		}

		return $this->fromHeaderBlock(fields: $this->headerFields(block: $bytes));
	}//end read()

	/**
	 * The headers of an `.msg`: its property streams first, its saved transport headers for the rest.
	 *
	 * @param string $bytes The file's bytes.
	 *
	 * @return MessageThreadHeaders
	 */
	private function fromMsg(string $bytes): MessageThreadHeaders {
		$streams = $this->compoundFile->rootStreams(bytes: $bytes);
		$fields = $this->headerFields(block: $this->msgString(streams: $streams, tag: self::MSG_TRANSPORT_HEADERS));
		foreach (self::MSG_TAGS as $header => $tag) {
			$value = $this->msgString(streams: $streams, tag: $tag);
			if ($value !== '') {
				$fields[$header] = $value;
			}
		}

		return $this->fromHeaderBlock(fields: $fields);
	}//end fromMsg()

	/**
	 * A string property of an `.msg`, Unicode or 8-bit, '' when absent.
	 *
	 * @param array<string, string> $streams The root streams.
	 * @param string                $tag     The four hex digits of the property tag.
	 *
	 * @return string
	 */
	private function msgString(array $streams, string $tag): string {
		$unicode = ($streams['__substg1.0_' . $tag . '001F'] ?? null);
		if ($unicode !== null) {
			return rtrim((string)mb_convert_encoding($unicode, 'UTF-8', 'UTF-16LE'), "\0");
		}

		return rtrim((string)($streams['__substg1.0_' . $tag . '001E'] ?? ''), "\0");
	}//end msgString()

	/**
	 * The headers from parsed header fields.
	 *
	 * @param array<string, string> $fields Lower-cased header name to its unfolded value.
	 *
	 * @return MessageThreadHeaders
	 */
	private function fromHeaderBlock(array $fields): MessageThreadHeaders {
		return new MessageThreadHeaders(
			messageId: ($this->ids(value: ($fields['message-id'] ?? ''))[0] ?? ''),
			inReplyTo: $this->ids(value: ($fields['in-reply-to'] ?? '')),
			references: $this->ids(value: ($fields['references'] ?? '')),
			sentAt: $this->date(value: ($fields['date'] ?? '')),
		);
	}//end fromHeaderBlock()

	/**
	 * The header fields of a header block, the first occurrence of each, folded lines joined.
	 *
	 * The block ends at the first empty line; a body is never read.
	 *
	 * @param string $block The text, from the first header line on.
	 *
	 * @return array<string, string> Lower-cased header name to its value.
	 */
	private function headerFields(string $block): array {
		$end = preg_match('/\r?\n\r?\n/', $block, $match, PREG_OFFSET_CAPTURE);
		if ($end === 1) {
			$block = substr($block, 0, $match[0][1]);
		}

		$unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $block);
		$fields = [];
		foreach (preg_split('/\r?\n/', (string)$unfolded) as $line) {
			if (preg_match('/^([!-9;-~]+):[ \t]*(.*)$/', $line, $parts) !== 1) {
				continue;
			}

			$name = strtolower($parts[1]);
			if (isset($fields[$name]) === false) {
				$fields[$name] = trim($parts[2]);
			}
		}

		return $fields;
	}//end headerFields()

	/**
	 * The message ids in a header value, without angle brackets, in order, each once.
	 *
	 * A value without brackets counts as one id when it holds no space.
	 *
	 * @param string $value The header value.
	 *
	 * @return list<string>
	 */
	private function ids(string $value): array {
		$value = trim($value);
		if ($value === '') {
			return [];
		}

		if (preg_match_all('/<([^<>\s]+)>/', $value, $matches) > 0) {
			return array_values(array_unique($matches[1]));
		}

		if (preg_match('/\s/', $value) === 1) {
			return [];
		}

		return [$value];
	}//end ids()

	/**
	 * A `Date` header as ISO 8601 in UTC, '' when it is no RFC 5322 date.
	 *
	 * The header names its own offset, so no zone is chosen here: the moment
	 * is read in the offset it names and written out from its timestamp.
	 *
	 * @param string $value The header value.
	 *
	 * @return string
	 */
	private function date(string $value): string {
		$value = trim((string)preg_replace('/\s*\([^)]*\)\s*$/', '', $value));
		foreach (self::DATE_FORMATS as $format) {
			$moment = DateTimeImmutable::createFromFormat($format, $value);
			if ($moment instanceof DateTimeImmutable) {
				return DateTimeImmutable::createFromFormat('U', (string)$moment->getTimestamp())->format(DateTimeInterface::ATOM);
			}
		}

		return '';
	}//end date()
}//end class
