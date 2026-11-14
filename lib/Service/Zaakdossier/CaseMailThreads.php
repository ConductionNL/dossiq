<?php

/**
 * Dossiq Case Mail Threads
 *
 * Places a mail being collected onto a case in its conversation. The
 * conversation is found through the mails the case already holds: a mail
 * that answers or references a collected one joins that one's conversation,
 * as does a mail that a collected one answers, whichever custodian or system
 * each came from. A mail that links two conversations the case already holds
 * (a reply collected before the mail it answers, the middle one last) joins
 * them: the documents of the later one are moved into the first. A mail that
 * links to none starts the conversation of the oldest id it names. A mail
 * that carries no threading header at all is a conversation of its own, keyed
 * on its bytes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
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

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\Email\MessageThreadHeaders;
use OCA\Dossiq\Service\Email\MessageThreadReader;

/**
 * The conversation fields a collected mail's provenance records.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
 */
class CaseMailThreads {

	/**
	 * Constructor.
	 *
	 * @param CaseDocumentProvenance $provenance The conversations of the mails the case already holds.
	 * @param DocumentRecordStore    $store      Moves a collected mail into the conversation it was found to belong to.
	 * @param MessageThreadReader    $reader     Reads a mail's threading headers.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseDocumentProvenance $provenance,
		private readonly DocumentRecordStore $store,
		private readonly MessageThreadReader $reader,
	) {
	}//end __construct()

	/**
	 * The conversation fields of a mail being collected onto a case.
	 *
	 * @param string $caseId   The case uuid.
	 * @param string $fileName The mail's file name.
	 * @param string $bytes    The mail's bytes.
	 *
	 * @return array{threadId: string, messageId: string, parentIds: list<string>, sentAt?: string}
	 *         The fields `provenance` keeps; `sentAt` only when the mail is dated.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-collected-e-mails-are-grouped-into-the-conversation-they-belong-to-req-wrc-006
	 */
	public function conversationOf(string $caseId, string $fileName, string $bytes): array {
		$headers = $this->reader->read(fileName: $fileName, bytes: $bytes);
		$fields = [
			'threadId' => $this->threadId(caseId: $caseId, headers: $headers, bytes: $bytes),
			'messageId' => $headers->messageId,
			'parentIds' => $headers->parentIds(),
		];
		if ($headers->sentAt !== '') {
			$fields['sentAt'] = $headers->sentAt;
		}

		return $fields;
	}//end conversationOf()

	/**
	 * The conversation a mail joins: a linked collected mail's, else its root's, else its own.
	 *
	 * When it links more than one, the others are joined into the first.
	 *
	 * @param string               $caseId  The case uuid.
	 * @param MessageThreadHeaders $headers The mail's headers.
	 * @param string               $bytes   The mail's bytes.
	 *
	 * @return string
	 */
	private function threadId(string $caseId, MessageThreadHeaders $headers, string $bytes): string {
		if ($headers->isEmpty() === true) {
			return 'mail-' . substr(hash('sha256', 'bytes:' . $bytes), 0, 32);
		}

		$collected = $this->provenance->threadsForCase(caseId: $caseId);
		$linked = $this->linkedThreads(collected: $collected, headers: $headers);
		if ($linked === []) {
			return 'mail-' . substr(hash('sha256', 'id:' . $headers->rootId()), 0, 32);
		}

		$threadId = array_shift($linked);
		$this->join(collected: $collected, from: $linked, into: $threadId);

		return $threadId;
	}//end threadId()

	/**
	 * The conversations of the collected mails this one answers, then of those that answer it.
	 *
	 * What it answers comes first, its oldest reference first.
	 *
	 * @param array<string, array{threadId: string, messageId: string, parentIds: list<string>, sentAt: string}> $collected The case's collected mails.
	 * @param MessageThreadHeaders                                                                              $headers   The mail's headers.
	 *
	 * @return list<string> The conversation ids, each once.
	 */
	private function linkedThreads(array $collected, MessageThreadHeaders $headers): array {
		$byMessageId = [];
		foreach ($collected as $thread) {
			if ($thread['messageId'] !== '' && isset($byMessageId[$thread['messageId']]) === false) {
				$byMessageId[$thread['messageId']] = $thread['threadId'];
			}
		}

		$linked = [];
		foreach ($headers->parentIds() as $parentId) {
			if (isset($byMessageId[$parentId]) === true) {
				$linked[] = $byMessageId[$parentId];
			}
		}

		if ($headers->messageId !== '') {
			foreach ($collected as $thread) {
				if (in_array($headers->messageId, $thread['parentIds'], true) === true) {
					$linked[] = $thread['threadId'];
				}
			}
		}

		return array_values(array_unique($linked));
	}//end linkedThreads()

	/**
	 * Move the collected mails of other conversations into one.
	 *
	 * Only a mail whose provenance sits on its own record is moved; a document
	 * linked from another case keeps its join as it is.
	 *
	 * @param array<string, array{threadId: string, messageId: string, parentIds: list<string>, sentAt: string}> $collected The case's collected mails.
	 * @param list<string>                                                                                      $from      The conversations to join.
	 * @param string                                                                                            $into      The conversation they join.
	 *
	 * @return void
	 */
	private function join(array $collected, array $from, string $into): void {
		if ($from === []) {
			return;
		}

		foreach ($collected as $documentId => $thread) {
			if (in_array($thread['threadId'], $from, true) === false) {
				continue;
			}

			$record = $this->store->findRecord(recordId: (string)$documentId);
			if ($record === null || is_array($record['provenance'] ?? null) === false) {
				continue;
			}

			$record['provenance']['threadId'] = $into;
			$this->store->saveRecord(record: $record);
		}
	}//end join()
}//end class
