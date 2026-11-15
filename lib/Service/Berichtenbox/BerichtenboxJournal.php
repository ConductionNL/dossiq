<?php

/**
 * Dossiq Berichtenbox journal.
 *
 * Owns what a digital post send leaves behind for someone to read: the record
 * that is stored, the sentence the case timeline carries, and whether that
 * entry is public or internal.
 *
 * It was split out of {@see \OCA\Dossiq\Service\BerichtenboxService}, which
 * was doing two jobs at once: talking to the adapter and the register, and
 * deciding what a handler and a citizen each get to see. The second is the one
 * worth reading on its own, and it is the one a refusal changes. The timeline
 * seam came with it, so the sending service no longer holds it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Berichtenbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Berichtenbox;

use DateTime;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;

/**
 * What a digital post send leaves behind, and who may read it.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
class BerichtenboxJournal {
	/**
	 * Constructor.
	 *
	 * @param CaseTimeline $timeline The one seam that writes a timeline entry.
	 */
	public function __construct(
		private readonly CaseTimeline $timeline,
	) {
	}//end __construct()

	/**
	 * The message record a send writes, whether it went out or was refused.
	 *
	 * A refusal is stored too, because a handler who pressed Send has to be
	 * able to see what became of the letter they wrote. It carries status
	 * `refused`, no external id, no sent time and the reason.
	 *
	 * @param array<string, mixed> $letter The letter: `caseId`, `bsn` (who it was addressed to), `subject`, `body`,
	 *                                     `typeCode` (the bericht type) and `attachmentFileId` (or null).
	 * @param array<string, mixed> $result What the adapter answered.
	 *
	 * @return array<string, mixed> The record to store.
	 *
	 * @spec openspec/specs/berichtenbox-integration/spec.md
	 */
	public function messageRecord(array $letter, array $result): array {
		$record = [
			'caseId' => $letter['caseId'],
			'bsn' => $letter['bsn'],
			'subject' => $letter['subject'],
			'body' => $letter['body'],
			'berichtTypeCode' => $letter['typeCode'],
			'attachmentFileId' => $letter['attachmentFileId'],
		];
		if ((($result['refused'] ?? false) === true)) {
			return $record + [
				'externalMessageId' => null,
				'status' => 'refused',
				'sentAt' => null,
				'lastError' => (string)($result['error'] ?? ''),
			];
		}

		return $record + [
			'externalMessageId' => $result['messageId'] ?? null,
			'status' => $result['status'] ?? 'sent',
			'sentAt' => $result['sentAt'] ?? (new DateTime())->format('c'),
		];
	}//end messageRecord()

	/**
	 * Write the send on the case timeline.
	 *
	 * PUBLIC, and deliberately WITHOUT the BSN. The recipient already has the
	 * message; the identifier they were addressed by is not part of what
	 * happened on the case, and a public entry is the last place to put one. A
	 * refusal is INTERNAL instead: telling a citizen on the portal that we
	 * tried to write to them and failed is not what the portal is for.
	 *
	 * @param string               $caseId      The case.
	 * @param string               $subject     The subject.
	 * @param array<string, mixed> $result      What the adapter answered.
	 * @param array<string, mixed> $messageData The record that was stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/berichtenbox-integration/spec.md
	 */
	public function recordSend(string $caseId, string $subject, array $result, array $messageData): void {
		$this->recordSendOnCase(caseId: $caseId, sent: ['subject' => $subject, 'result' => $result, 'messageData' => $messageData]);
	}//end recordSend()

	/**
	 * Write the send on the case timeline.
	 *
	 * @param string               $caseId The case.
	 * @param array<string, mixed> $sent   The message `subject`, integriq's `result` and the stored `messageData`.
	 *
	 * @return void
	 */
	private function recordSendOnCase(string $caseId, array $sent): void {
		$subject = $sent['subject'];
		$result = $sent['result'];
		[$sentence, $visibility] = $this->timelineEntry(subject: $subject, result: $result);

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::PORTAL_MESSAGE,
			message: $sentence,
			fields: [
				'subject' => $subject,
				'messageId' => (string)($result['messageId'] ?? ''),
				'status' => (string)($sent['messageData']['status']),
			],
			visibility: $visibility,
		);
	}//end recordSendOnCase()

	/**
	 * Write the status change on the case timeline, when the message names a case.
	 *
	 * @param array<string, mixed> $data              The stored message.
	 * @param string               $externalMessageId The id integriq tracks it by.
	 * @param string               $status            The status it moved to.
	 * @param string               $lastError         The provider's reason, when it failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/berichtenbox-integration/spec.md
	 */
	public function recordStatus(
		array $data,
		string $externalMessageId,
		string $status,
		string $lastError,
	): void {
		if ((string)($data['caseId'] ?? '') === '') {
			return;
		}

		$this->recordStatusOnCase(
			data: $data,
			outcome: ['messageId' => $externalMessageId, 'status' => $status, 'lastError' => $lastError]
		);
	}//end recordStatus()

	/**
	 * Write the status change on the timeline of the case the message names.
	 *
	 * @param array<string, mixed>  $data    The stored message, with its `caseId`.
	 * @param array<string, string> $outcome The `messageId` integriq tracks it by, the `status` it moved to and
	 *                                       the provider's `lastError`, when it failed.
	 *
	 * @return void
	 */
	private function recordStatusOnCase(array $data, array $outcome): void {
		$status = $outcome['status'];
		$this->timeline->record(
			caseId: (string)$data['caseId'],
			kind: TimelineKinds::PORTAL_MESSAGE,
			message: $this->statusSentence(subject: (string)($data['subject'] ?? ''), outcome: ['status' => $status, 'lastError' => $outcome['lastError']]),
			fields: [
				'subject' => (string)($data['subject'] ?? ''),
				'messageId' => $outcome['messageId'],
				'status' => $status,
			],
			// INTERNAL: a delivery receipt is about our sending, not about what
			// the citizen was told, and the portal already shows them the
			// message itself.
			visibility: CaseTimeline::INTERNAL,
		);
	}//end recordStatusOnCase()

	/**
	 * The sentence and the visibility the timeline entry gets.
	 *
	 * A refusal is INTERNAL: telling a citizen on the portal that we tried to
	 * write to them and failed is not what the portal is for.
	 *
	 * @param string               $subject The subject.
	 * @param array<string, mixed> $result  What the adapter answered.
	 *
	 * @return array{0: string, 1: string} The sentence and the visibility.
	 */
	private function timelineEntry(string $subject, array $result): array {
		if ((($result['refused'] ?? false) === true)) {
			return [
				($subject . ' -- not sent: ' . (string)($result['error'] ?? '')),
				CaseTimeline::INTERNAL,
			];
		}

		return [$subject, CaseTimeline::PUBLIC_ENTRY];
	}//end timelineEntry()

	/**
	 * The sentence a status change writes on the timeline.
	 *
	 * A failure NAMES the reason. "Delivery failed" on its own leaves a
	 * handler with nothing to act on, which is how a citizen goes unnotified
	 * while the case says something happened.
	 *
	 * @param string                                    $subject The letter's subject.
	 * @param array{status: string, lastError: string} $outcome The status it moved to, and the provider's reason when it failed.
	 *
	 * @return string The sentence.
	 */
	private function statusSentence(string $subject, array $outcome): string {
		['status' => $status, 'lastError' => $lastError] = $outcome;
		if ($status === 'failed') {
			$reason = $lastError;
			if ($reason === '') {
				$reason = 'the provider gave no reason';
			}


			return $subject . ' -- not delivered: ' . $reason;
		}

		return $subject . ' -- ' . $status;
	}//end statusSentence()
}//end class
