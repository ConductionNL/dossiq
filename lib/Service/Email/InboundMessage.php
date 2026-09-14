<?php

/**
 * Dossiq Inbound Message
 *
 * One message as intake sees it: where it sits in the mailbox, the handful of
 * headers every filter reads, and its raw source when something needs to read a
 * header nobody normalised.
 *
 * Held as a value object rather than the loose array the old poller passed
 * around, because a filter that reads `$message['subject']` on a row that spells
 * it `Subject` fails silently and answers `pass`. A filter that cannot see a
 * header it needs must say so, and a typed accessor is what makes that possible.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * A message on its way through the intake pipeline.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
final class InboundMessage {

	/**
	 * Constructor.
	 *
	 * @param integer               $accountId The mail account it arrived on.
	 * @param string                $mailbox   The folder it sits in.
	 * @param integer               $uid       Its uid in that folder.
	 * @param string                $messageId The RFC 5322 Message-ID.
	 * @param string                $subject   The subject.
	 * @param string                $from      The sender address.
	 * @param string                $to        The recipient address.
	 * @param string                $sentAt    When the sender says they sent it.
	 * @param array<string, string> $headers   Header name (lowercased) to value.
	 * @param string                $source    The raw source, or '' when unread.
	 */
	public function __construct(
		public readonly int $accountId,
		public readonly string $mailbox,
		public readonly int $uid,
		public readonly string $messageId = '',
		public readonly string $subject = '',
		public readonly string $from = '',
		public readonly string $to = '',
		public readonly string $sentAt = '',
		public readonly array $headers = [],
		public readonly string $source = '',
	) {
	}//end __construct()

	/**
	 * Build a message from the row a gateway hands over.
	 *
	 * @param array<string, mixed> $row       The normalised row.
	 * @param integer              $accountId The account, when the row omits it.
	 * @param string               $mailbox   The folder, when the row omits it.
	 *
	 * @return self The message.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function fromRow(array $row, int $accountId = 0, string $mailbox = ''): self {
		$headers = [];
		$raw = ($row['headers'] ?? []);
		if (is_array($raw) === true) {
			foreach ($raw as $name => $value) {
				$headers[strtolower((string)$name)] = (string)$value;
			}
		}

		return new self(
			accountId: (int)($row['accountId'] ?? $accountId),
			mailbox: (string)($row['mailbox'] ?? $mailbox),
			uid: (int)($row['uid'] ?? 0),
			messageId: (string)($row['messageId'] ?? ($row['mailMessageId'] ?? '')),
			subject: (string)($row['subject'] ?? ''),
			from: (string)($row['from'] ?? ''),
			to: (string)($row['to'] ?? ''),
			sentAt: (string)($row['sentAt'] ?? ''),
			headers: $headers,
			source: (string)($row['source'] ?? ''),
		);
	}//end fromRow()

	/**
	 * The same message with its raw source read in.
	 *
	 * Headers parsed from the source are merged UNDER the ones already present,
	 * so a gateway that normalised a header keeps the last word on it.
	 *
	 * @param string $source The raw message source.
	 *
	 * @return self A new message carrying the source.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function withSource(string $source): self {
		return new self(
			accountId: $this->accountId,
			mailbox: $this->mailbox,
			uid: $this->uid,
			messageId: $this->messageId,
			subject: $this->subject,
			from: $this->from,
			to: $this->to,
			sentAt: $this->sentAt,
			headers: array_merge(self::parseHeaders(source: $source), $this->headers),
			source: $source,
		);
	}//end withSource()

	/**
	 * One header, case-insensitively.
	 *
	 * @param string $name The header name.
	 *
	 * @return string The value, or '' when the message does not carry it.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function header(string $name): string {
		return ($this->headers[strtolower($name)] ?? '');
	}//end header()

	/**
	 * Whether the message carries a header at all.
	 *
	 * Distinct from an empty value on purpose: `Auto-Submitted:` present and
	 * empty is a malformed message, not an absent claim.
	 *
	 * @param string $name The header name.
	 *
	 * @return boolean True when the header is present.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function hasHeader(string $name): bool {
		return array_key_exists(strtolower($name), $this->headers);
	}//end hasHeader()

	/**
	 * The bare sender address, without a display name or angle brackets.
	 *
	 * @return string The address, lowercased, or '' when there is none.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function senderAddress(): string {
		return self::addressIn(value: $this->from);
	}//end senderAddress()

	/**
	 * The message ids this message claims to be a reply to.
	 *
	 * `In-Reply-To` first, then every id in `References`, de-duplicated and
	 * stripped of angle brackets.
	 *
	 * @return array<int, string> The referenced ids, possibly empty.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function referencedMessageIds(): array {
		$raw = trim($this->header(name: 'in-reply-to') . ' ' . $this->header(name: 'references'));
		if ($raw === '') {
			return [];
		}

		if (preg_match_all('/<([^<>\s]+)>/', $raw, $matches) === 0) {
			// A sender that omitted the angle brackets still made a claim.
			$bare = preg_split('/\s+/', $raw);
			if ($bare === false) {
				return [];
			}

			return array_values(array_unique(array_filter($bare)));
		}

		return array_values(array_unique($matches[1]));
	}//end referencedMessageIds()

	/**
	 * The row the intake log stores for this message.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function toArray(): array {
		return [
			'accountId' => $this->accountId,
			'mailbox' => $this->mailbox,
			'uid' => $this->uid,
			'messageId' => $this->messageId,
			'mailMessageId' => $this->messageId,
			'subject' => $this->subject,
			'from' => $this->from,
			'to' => $this->to,
			'sentAt' => $this->sentAt,
		];
	}//end toArray()

	/**
	 * Pull the bare address out of a `Name <a@b>` style value.
	 *
	 * @param string $value The header value.
	 *
	 * @return string The address, lowercased.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public static function addressIn(string $value): string {
		if (preg_match('/<([^<>]+)>/', $value, $matches) === 1) {
			return strtolower(trim($matches[1]));
		}

		return strtolower(trim($value));
	}//end addressIn()

	/**
	 * Parse the header block off a raw message source.
	 *
	 * Folded continuation lines are joined, and a header repeated more than
	 * once keeps every value joined by a newline, because
	 * `Authentication-Results` legitimately repeats per checking host and
	 * keeping only the last one would read one relay's verdict as the whole
	 * chain's.
	 *
	 * @param string $source The raw source.
	 *
	 * @return array<string, string> Lowercased header name to value.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private static function parseHeaders(string $source): array {
		$normalised = str_replace(["\r\n", "\r"], "\n", $source);
		$split = explode("\n\n", $normalised, 2);
		$block = $split[0];

		// Unfold: a line starting with whitespace continues the one above it.
		$unfolded = preg_replace('/\n[ \t]+/', ' ', $block);
		if (is_string($unfolded) === false) {
			return [];
		}

		$headers = [];
		foreach (explode("\n", $unfolded) as $line) {
			$position = strpos($line, ':');
			if ($position === false || $position === 0) {
				continue;
			}

			$name = strtolower(trim(substr($line, 0, $position)));
			$value = trim(substr($line, ($position + 1)));
			if (array_key_exists($name, $headers) === true) {
				$headers[$name] .= "\n" . $value;
				continue;
			}

			$headers[$name] = $value;
		}//end foreach

		return $headers;
	}//end parseHeaders()
}//end class
