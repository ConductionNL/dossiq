<?php

/**
 * What a handler reads of a queued message before opening it.
 *
 * The triage queue carries the stored original of each message. The Link to
 * case dialog (board DqKoppelAanZaak) shows the sender's name, the first lines
 * of the text and the attachments that go along; this reads those three out of
 * the original, so the dialog needs no second request per message.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

/**
 * Reads the sender's name, a text preview and the attachment names out of a stored original.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
class MessagePreview {

	/**
	 * The longest preview, in characters.
	 */
	private const PREVIEW_LENGTH = 220;

	/**
	 * The deepest multipart nesting read.
	 */
	private const MAX_DEPTH = 4;

	/**
	 * Add `senderName`, `preview` and `attachments` to every queue item that carries its original.
	 *
	 * @param array<int, array<string, mixed>> $items The queue items.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function annotate(array $items): array {
		foreach ($items as $index => $item) {
			$read = $this->read(original: (string)($item['original'] ?? ''));
			$items[$index]['senderName'] = $read['senderName'];
			$items[$index]['preview'] = $read['preview'];
			$items[$index]['attachments'] = $read['attachments'];
		}

		return $items;
	}//end annotate()

	/**
	 * The sender's name, the first text and the attachment names of one original.
	 *
	 * @param string $original The message as stored, headers and body.
	 *
	 * @return array{senderName: string, preview: string, attachments: array<int, string>}
	 *
	 * @spec openspec/specs/case-email-integration/spec.md
	 */
	public function read(string $original): array {
		$read = ['senderName' => '', 'preview' => '', 'attachments' => []];
		if (trim($original) === '') {
			return $read;
		}

		[$headers, $body] = $this->split(part: $original);
		$read['senderName'] = $this->displayName(from: ($headers['from'] ?? ''));

		$found = $this->walk(part: ['headers' => $headers, 'body' => $body], depth: 0);
		$read['attachments'] = $found['attachments'];
		$read['preview'] = $this->shorten(text: ($found['texts'][0] ?? ''));

		return $read;
	}//end read()

	/**
	 * The plain texts and attachment names of a part and its children.
	 *
	 * @param array{headers: array<string, string>, body: string} $part  The part.
	 * @param int                                                 $depth How deep the part sits.
	 *
	 * @return array{texts: array<int, string>, attachments: array<int, string>}
	 */
	private function walk(array $part, int $depth): array {
		$found = ['texts' => [], 'attachments' => []];
		$type = strtolower(($part['headers']['content-type'] ?? 'text/plain'));
		$filename = $this->filename(headers: $part['headers']);
		if ($filename !== '') {
			$found['attachments'][] = $filename;
			return $found;
		}

		if (str_starts_with($type, 'multipart/') === true) {
			if ($depth >= self::MAX_DEPTH) {
				return $found;
			}

			foreach ($this->children(body: $part['body'], type: $part['headers']['content-type']) as $child) {
				[$headers, $body] = $this->split(part: $child);
				$inner = $this->walk(part: ['headers' => $headers, 'body' => $body], depth: ($depth + 1));
				$found['texts'] = array_merge($found['texts'], $inner['texts']);
				$found['attachments'] = array_merge($found['attachments'], $inner['attachments']);
			}

			return $found;
		}

		if (str_starts_with($type, 'text/plain') === true) {
			$found['texts'][] = $this->decode(body: $part['body'], encoding: ($part['headers']['content-transfer-encoding'] ?? ''));
		}

		return $found;
	}//end walk()

	/**
	 * Split a part into its headers (lower-case names, folded lines joined) and its body.
	 *
	 * @param string $part The part.
	 *
	 * @return array{0: array<string, string>, 1: string}
	 */
	private function split(string $part): array {
		$normalised = str_replace("\r\n", "\n", $part);
		$cut = strpos($normalised, "\n\n");
		$head = $normalised;
		$body = '';
		if ($cut !== false) {
			$head = substr($normalised, 0, $cut);
			$body = substr($normalised, ($cut + 2));
		}

		$headers = [];
		foreach (explode("\n", (string)preg_replace('/\n[ \t]+/', ' ', $head)) as $line) {
			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}

			$headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, ($colon + 1)));
		}

		return [$headers, $body];
	}//end split()

	/**
	 * The parts of a multipart body.
	 *
	 * @param string $body The body.
	 * @param string $type The Content-Type header, which names the boundary.
	 *
	 * @return array<int, string>
	 */
	private function children(string $body, string $type): array {
		if (preg_match('/boundary="?([^";]+)"?/i', $type, $match) !== 1) {
			return [];
		}

		$parts = explode('--' . $match[1], $body);
		array_shift($parts);

		$children = [];
		foreach ($parts as $part) {
			if (str_starts_with($part, '--') === true) {
				break;
			}

			$children[] = ltrim($part, "\n");
		}

		return $children;
	}//end children()

	/**
	 * The file name of an attachment part, or '' for a part that is not one.
	 *
	 * @param array<string, string> $headers The part's headers.
	 *
	 * @return string
	 */
	private function filename(array $headers): string {
		$disposition = ($headers['content-disposition'] ?? '');
		$source = $disposition . ';' . ($headers['content-type'] ?? '');
		if (preg_match('/(?:file)?name\*?="?([^";]+)"?/i', $source, $match) !== 1) {
			return '';
		}

		if (stripos($disposition, 'inline') === 0 && stripos($headers['content-type'] ?? '', 'text/') === 0) {
			return '';
		}

		$name = trim($match[1]);
		if (preg_match("/^[\w-]+''(.*)$/", $name, $encoded) === 1) {
			$name = rawurldecode($encoded[1]);
		}

		$decoded = iconv_mime_decode($name, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
		if ($decoded === false) {
			return $name;
		}

		return $decoded;
	}//end filename()

	/**
	 * A text body in plain characters.
	 *
	 * @param string $body     The body as sent.
	 * @param string $encoding The Content-Transfer-Encoding.
	 *
	 * @return string
	 */
	private function decode(string $body, string $encoding): string {
		$encoding = strtolower(trim($encoding));
		if ($encoding === 'quoted-printable') {
			return quoted_printable_decode($body);
		}

		if ($encoding === 'base64') {
			return (string)base64_decode(str_replace(["\n", "\r"], '', $body), true);
		}

		return $body;
	}//end decode()

	/**
	 * The first lines of a text on one line, ending in an ellipsis when cut.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function shorten(string $text): string {
		$flat = trim((string)preg_replace('/\s+/u', ' ', $text));
		if (mb_strlen($flat) <= self::PREVIEW_LENGTH) {
			return $flat;
		}

		return rtrim(mb_substr($flat, 0, self::PREVIEW_LENGTH)) . '…';
	}//end shorten()

	/**
	 * The display name of a From header, or '' when it gives only an address.
	 *
	 * @param string $from The From header.
	 *
	 * @return string
	 */
	private function displayName(string $from): string {
		$decoded = iconv_mime_decode($from, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
		if ($decoded === false) {
			$decoded = $from;
		}

		if (preg_match('/^\s*"?([^"<]*?)"?\s*</', $decoded, $match) !== 1) {
			return '';
		}

		return trim($match[1]);
	}//end displayName()
}//end class
