<?php

/**
 * Dossiq Message Thread Headers
 *
 * The headers that place a saved e-mail in its conversation: its own
 * `Message-ID`, the `In-Reply-To` it answers, the `References` chain and its
 * `Date`, as {@see MessageThreadReader} reads them from the file.
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

/**
 * A saved e-mail's threading headers.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */
final class MessageThreadHeaders {

	/**
	 * Constructor.
	 *
	 * @param string       $messageId  The message's own id, without angle brackets; '' when absent.
	 * @param list<string> $inReplyTo  The ids it answers.
	 * @param list<string> $references The ids of the conversation before it, oldest first.
	 * @param string       $sentAt     When it was sent, ISO 8601 in UTC; '' when absent or unreadable.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $messageId = '',
		public readonly array $inReplyTo = [],
		public readonly array $references = [],
		public readonly string $sentAt = '',
	) {
	}//end __construct()

	/**
	 * Whether the message carries none of the threading ids.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 */
	public function isEmpty(): bool {
		return $this->messageId === '' && $this->parentIds() === [];
	}//end isEmpty()

	/**
	 * The ids of the messages this one follows: the references, then what it answers, each once.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 */
	public function parentIds(): array {
		$ids = [];
		foreach (array_merge($this->references, $this->inReplyTo) as $id) {
			if ($id !== '' && $id !== $this->messageId) {
				$ids[$id] = true;
			}
		}

		return array_map('strval', array_keys($ids));
	}//end parentIds()

	/**
	 * The id the conversation started with: the oldest reference, else what it answers, else its own.
	 *
	 * @return string '' when the message carries no id at all.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 */
	public function rootId(): string {
		return ($this->references[0] ?? $this->inReplyTo[0] ?? $this->messageId);
	}//end rootId()
}//end class
