<?php

/**
 * Dossiq sent mail recorder.
 *
 * Records a mail that went out about a case: the stored message and its
 * line on the case timeline. Split out of
 * {@see \OCA\Dossiq\Service\CaseEmailService}, which keeps the checks
 * before a send, the hand-off to the Mail account, the templates and the
 * inbound linking.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-history-surface/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;

/**
 * Stores a sent mail on its case and puts its line on the timeline.
 *
 * @spec openspec/specs/case-history-surface/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class SentMailRecorder {

	/**
	 * Constructor.
	 *
	 * @param CaseEmailRepository $repository OpenRegister reads/writes for case email.
	 * @param CaseTimeline        $timeline   The one seam that writes a timeline entry.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly CaseEmailRepository $repository,
		private readonly CaseTimeline $timeline,
	) {
	}//end __construct()

	/**
	 * Record a sent mail as a case document and put its line on the timeline.
	 *
	 * The sent mail carries the envelope sender (empty when it is not known
	 * here), the recipient, the subject and body as sent, and what became of
	 * it (an OutboundState value: sent, sent-not-filed or queued).
	 *
	 * @param string                                                                                  $caseId The case UUID.
	 * @param array{fromAddress: string, to: string, subject: string, body: string, delivery: string} $sent   The sent mail.
	 *
	 * @return string The message id of the stored email record.
	 *
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function record(string $caseId, array $sent): string {
		$mail = new SentCaseMail(
			caseId: $caseId,
			fromAddress: $sent['fromAddress'],
			to: $sent['to'],
			subject: $sent['subject'],
			body: $sent['body'],
			delivery: $sent['delivery']
		);

		// Record the sent email as a case document.
		$messageId = $this->repository->recordSentEmail(
			caseId: $mail->caseId,
			fromAddress: $mail->fromAddress,
			to: $mail->to,
			subject: $mail->subject,
			body: $mail->body,
		);

		$this->recordMailOut(mail: $mail, messageId: (string)$messageId);

		return (string)$messageId;
	}//end record()

	/**
	 * Put a sent mail's line on the case timeline.
	 *
	 * PUBLIC: the recipient already has this message in their own inbox,
	 * so hiding its line from the timeline they are shown would only hide
	 * it from the person who has it.
	 *
	 * @param SentCaseMail $mail      The mail.
	 * @param string       $messageId The document it was recorded as.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-history-surface/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function recordMailOut(SentCaseMail $mail, string $messageId): void {
		$this->timeline->record(
			caseId: $mail->caseId,
			kind: TimelineKinds::MAIL_OUT,
			message: $mail->subject,
			fields: [
				'recipient' => $mail->to,
				'subject' => $mail->subject,
				'documentId' => $messageId,
				'delivery' => $mail->delivery,
				'sender' => $mail->fromAddress,
			],
			visibility: CaseTimeline::PUBLIC_ENTRY,
		);
	}//end recordMailOut()
}//end class
