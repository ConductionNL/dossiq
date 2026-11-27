<?php

/**
 * A mail that went out about a case, recorded on that case
 *
 * The half of sending a case mail that is dossiq's own, whichever way the
 * mail left (dossiq's own send, or an OpenRegister flow step): the message
 * stored as a case document and a public line on the case timeline naming
 * the recipient, the subject, the document, what became of the delivery and
 * the sender.
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
 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;

/**
 * Stores a sent case mail and puts its line on the timeline.
 *
 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
 */
final class SentCaseMailRecorder {

	/**
	 * Constructor.
	 *
	 * @param CaseEmailRepository $repository Stores the mail as a case document.
	 * @param CaseTimeline        $timeline   The one seam that writes a timeline entry.
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly CaseEmailRepository $repository,
		private readonly CaseTimeline $timeline,
	) {
	}//end __construct()

	/**
	 * Record a mail that went out about a case: the stored message and the timeline line.
	 *
	 * @param string $caseId      The case UUID.
	 * @param string $fromAddress The envelope sender, or empty when it is not known here.
	 * @param string $to          The recipient.
	 * @param string $subject     The subject, as sent.
	 * @param string $body        The body, as sent.
	 * @param string $delivery    What became of it: an OutboundState value (sent, sent-not-filed or queued).
	 *
	 * @return string The stored message id.
	 *
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function record(
		string $caseId,
		string $fromAddress,
		string $to,
		string $subject,
		string $body,
		string $delivery = 'sent',
	): string {
		return $this->recordSent(
			mail: new SentCaseMail(
				caseId: $caseId, fromAddress: $fromAddress, to: $to, subject: $subject, body: $body, delivery: $delivery
			)
		);
	}//end record()

	/**
	 * Record a sent mail as a case document and put its line on the timeline.
	 *
	 * @param SentCaseMail $mail The mail.
	 *
	 * @return string The message id of the stored email record.
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function recordSent(SentCaseMail $mail): string {
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
	}//end recordSent()

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
