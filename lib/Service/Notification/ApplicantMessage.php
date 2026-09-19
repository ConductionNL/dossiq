<?php

/**
 * One declared moment's message to the applicant of a case.
 *
 * The acknowledgement of receipt is not the only thing a case type writes to
 * the applicant, and `notificationMoments` was already the list of the others.
 * What was missing was a way to SEND one: {@see AcknowledgementService} owns
 * the statutory moment and everything it does is specific to that duty.
 *
 * 🔴 A MESSAGE THAT COULD NOT BE SENT NEVER FAILS THE ACT THAT ASKED FOR IT.
 * An inadmissible verdict closes the case; a case with no address on it would
 * otherwise stay open because the letter could not go out, which is exactly the
 * dead phase the verdict exists to prevent. So this reports what happened and
 * the caller records it, rather than throwing.
 *
 * 🔑 IT SENDS NOTHING THE CASE TYPE DID NOT DECLARE. The moment has to be in
 * `notificationMoments` and switched on. A message that went out because a
 * service decided to send one is a message nobody can switch off.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notification
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
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the message one declared moment owes the applicant, and says what happened.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Five collaborators, because
 * sending one letter to a citizen is five things: is it declared, who is the
 * addressee, which clock does it quote, who routes it, and what does the case
 * file say afterwards. Folding them into a parameter object would hide the list
 * rather than shorten it.
 */
class ApplicantMessage {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeAcknowledgement    $moments       The moments a case type declares.
	 * @param CaseContactDirectory       $contacts      The addresses on a case.
	 * @param TermijnService             $terms         The case's own term, which the letter may quote.
	 * @param TermijnNotificationService $notifications Renders and routes the message.
	 * @param CaseTimeline               $timeline      Records what the applicant was sent.
	 * @param LoggerInterface            $logger        Says why a message did not go out.
	 */
	public function __construct(
		private readonly CaseTypeAcknowledgement $moments,
		private readonly CaseContactDirectory $contacts,
		private readonly TermijnService $terms,
		private readonly TermijnNotificationService $notifications,
		private readonly CaseTimeline $timeline,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this case type declares a moment, and has it switched on.
	 *
	 * A moment declared and switched off is a message somebody decided not to
	 * send, which is a legitimate choice. A moment nobody declared at all is
	 * the same answer here and a warning at publication, because the two are
	 * told apart by the case type and not by this class.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 * @param string               $moment   The moment.
	 *
	 * @return bool True when the message is declared and on.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
	 */
	public function declares(array $caseType, string $moment): bool {
		foreach ($this->moments->momentsFor(caseType: $caseType) as $declared) {
			if (($declared['moment'] ?? '') === $moment) {
				return (($declared['enabled'] ?? true) !== false);
			}
		}

		return false;
	}//end declares()

	/**
	 * Send the message this moment owes the applicant.
	 *
	 * @param array<string, mixed> $case     The stored case.
	 * @param array<string, mixed> $caseType The effective case type row.
	 * @param string               $moment   The declared moment.
	 * @param string               $template The template to render.
	 * @param array<string, mixed> $context  What the template needs beside the case.
	 *
	 * @return array{sent: bool, reason: string, recipient: string, template: string, sentAt: string}
	 *         What happened, for the caller to record.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-an-inadmissible-verdict-ends-the-case-at-intake-req-dec-02
	 */
	public function send(
		array $case,
		array $caseType,
		string $moment,
		string $template,
		array $context = [],
	): array {
		if ($this->declares(caseType: $caseType, moment: $moment) === false) {
			return $this->outcome(sent: false, reason: 'moment-not-declared');
		}

		$recipient = $this->recipientOn(case: $case);
		if ($recipient === '') {
			$this->logger->warning(
				'Dossiq applicant message: the case carries no address, so nothing was sent',
				['moment' => $moment, 'template' => $template],
			);

			return $this->outcome(sent: false, reason: 'no-address');
		}

		$caseId = trim((string)($case['id'] ?? ($case['uuid'] ?? '')));
		$term = $this->terms->getTermijnInstanceForZaak(caseId: $caseId);
		$sentAt = (new DateTimeImmutable())->format('c');

		try {
			$this->notifications->sendTermijnNotification(
				$template,
				(string)($term['id'] ?? ($term['uuid'] ?? '')),
				$recipient,
				array_merge(['case' => (string)($case['identification'] ?? $caseId)], $context),
			);
		} catch (Throwable $e) {
			// Reported, never thrown. The act that asked for this message has
			// already happened, and failing it now would leave a case half
			// ended with nothing saying which half.
			$this->logger->error(
				'Dossiq applicant message: the message could not be sent',
				['moment' => $moment, 'template' => $template, 'error' => $e->getMessage()],
			);

			return $this->outcome(sent: false, reason: 'send-failed', recipient: $recipient);
		}//end try

		// PUBLIC, because this is a message the applicant has received. A
		// timeline the portal reads that says nothing about a letter somebody
		// is holding is worse than no timeline.
		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::PORTAL_MESSAGE,
			message: 'Bericht aan de aanvrager verzonden',
			fields: ['moment' => $moment, 'template' => $template, 'sentAt' => $sentAt],
			visibility: CaseTimeline::PUBLIC_ENTRY,
		);

		return $this->outcome(
			sent: true,
			reason: '',
			recipient: $recipient,
			template: $template,
			sentAt: $sentAt,
		);
	}//end send()

	/**
	 * The first address on the case, or ''.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return string The address.
	 */
	private function recipientOn(array $case): string {
		$addresses = $this->contacts->collectAddresses(caseData: $case);
		if ($addresses === []) {
			return '';
		}

		return trim((string)reset($addresses));
	}//end recipientOn()

	/**
	 * One answer shape, so no caller has to interpret an absent key.
	 *
	 * @param bool   $sent      Whether the message went out.
	 * @param string $reason    Why it did not, when it did not.
	 * @param string $recipient Who it went to.
	 * @param string $template  Which template was rendered.
	 * @param string $sentAt    When, ISO 8601.
	 *
	 * @return array{sent: bool, reason: string, recipient: string, template: string, sentAt: string} The answer.
	 */
	private function outcome(
		bool $sent,
		string $reason,
		string $recipient = '',
		string $template = '',
		string $sentAt = '',
	): array {
		return [
			'sent' => $sent,
			'reason' => $reason,
			'recipient' => $recipient,
			'template' => $template,
			'sentAt' => $sentAt,
		];
	}//end outcome()
}//end class
