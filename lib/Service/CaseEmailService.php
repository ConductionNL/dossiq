<?php

/**
 * Dossiq Case Email Service
 *
 * Service for sending and receiving email within case context.
 * Supports template variable resolution, email-to-PDF conversion,
 * and automatic linking of inbound email to cases.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Email\OutboundMailRules;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RecipientOptedOutException;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\Email\OutboundCaseMail;
use OCA\Dossiq\Service\Email\ReceivedEmail;
use OCA\Dossiq\Service\Email\RecipientAllowlist;
use OCA\Dossiq\Service\Email\SentCaseMail;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for case-integrated email functionality.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
 * @spec openspec/specs/case-history-surface/spec.md
 */
class CaseEmailService {

	/**
	 * Regex pattern for extracting case number from email subject.
	 */
	private const CASE_NUMBER_PATTERN = '/\[ZAAK-(\d{4}-\d{4,})\]/';

	/**
	 * Substitution mode that HTML-escapes every resolved value.
	 */
	private const ESCAPE_HTML = 'html';

	/**
	 * Substitution mode that writes resolved values through verbatim.
	 */
	private const ESCAPE_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * 🔴 NO TRANSPORT HERE (inbound-mail-filters 8.1, decisions 165 and 182).
	 * OutboundCaseMail sends through the transport configured for case mail:
	 * by default the case's Nextcloud Mail account, on that account's own
	 * authentication, filed in its sent folder.
	 *
	 * @param LoggerInterface $logger Logger
	 * @param CaseEmailRepository $repository OpenRegister reads/writes for case email
	 * @param CaseContactDirectory $contactDirectory Contact addresses registered on a case
	 * @param OutboundMailRules $sending The outbound mail, the recipient allowlist and the opt-out.
	 * @param CaseTimeline $timeline The one seam that writes a timeline entry
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly CaseEmailRepository $repository,
		private readonly CaseContactDirectory $contactDirectory,
		private readonly OutboundMailRules $sending,
		private readonly CaseTimeline $timeline,
	) {
	}//end __construct()

	/**
	 * Send an email from case context.
	 *
	 * @param string $caseId The case UUID
	 * @param string $to Recipient email address
	 * @param string $subject Email subject
	 * @param string $body Email body (HTML or plain text)
	 * @param array<string> $attachments File paths to attach
	 * @param string $category What the mail is: `case-update` (default), `besluit` or `statutory`
	 *
	 * A category a case mail cannot carry is refused first, by
	 * CaseMailOptOut::assertCaseMailCategory() (`invalid-category`).
	 *
	 * OutboundCaseMail's refusals (sender-not-held, mail-account-unavailable,
	 * mail-transport-unavailable) pass through to the controller unchanged.
	 *
	 * @return array<string, mixed> Send result with message ID and `state`
	 *         (sent, sent-not-filed, or queued when the account took it but could not send it yet)
	 *
	 * @throws \RuntimeException If the case cannot be read or the recipient is not allowed
	 * @throws RecipientOptedOutException If integriq says this person may not be sent it
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function sendEmail(
		string $caseId,
		string $to,
		string $subject,
		string $body,
		array $attachments = [],
		string $category = CaseMailOptOut::CATEGORY_CASE_UPDATE,
	): array {
		$this->sending->optOut->assertCaseMailCategory(category: $category);

		$send = new CaseMailSend(caseId: $caseId, recipient: $to);
		$send->subject = $subject;
		$send->body = $body;
		$send->attachments = $attachments;
		$send->category = $category;

		$this->readCaseAndSender(send: $send);
		$this->assertRecipientAllowed(send: $send);
		$this->assertNotOptedOut(send: $send);
		$this->handToMailAccount(send: $send);

		return $this->sentResult(send: $send, messageId: $this->recordOnCase(send: $send));
	}//end sendEmail()

	/**
	 * Read the case the user may see, and the sender the configured transport chose for it.
	 *
	 * @param CaseMailSend $send The send, given its case record and sender here.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the case cannot be read
	 */
	private function readCaseAndSender(CaseMailSend $send): void {
		// C4 IDOR: Load the case via OR with RBAC enabled to verify the current user
		// has read access. If the case is not found (or the user has no access), OR
		// returns null — we treat that as 403.
		// The RAW record, not loadCaseVariables()'s six-key projection: the
		// recipient policy reads the case's contact fields, and the projection
		// drops every field it does not name.
		$send->caseData = $this->repository->loadCaseRecord(caseId: $send->caseId);
		if (empty($send->caseData) === true) {
			throw new RuntimeException('Zaak niet gevonden of geen toegang.');
		}

		// The configured transport decides the sender (decisions 165, 182).
		// Through the Mail account: the case type's declared account, else the
		// one an administrator picked; an address no account holds is refused
		// here, before anything is built (REQ-IMF-12).
		$send->sender      = $this->sending->outbound->senderFor(caseData: $send->caseData);
		$send->fromAddress = $send->sender['from'];
	}//end readCaseAndSender()

	/**
	 * Ask integriq after the allow-list and before anything is built or sent.
	 *
	 * Opt-out-before-send: a refusal leaves no mail and no record.
	 *
	 * @param CaseMailSend $send The send, given integriq's decision here.
	 *
	 * @return void
	 *
	 * @throws RecipientOptedOutException If integriq says this person may not be sent it
	 */
	private function assertNotOptedOut(CaseMailSend $send): void {
		$send->decision = $this->sending->optOut->decide(
			recipient: $send->recipient,
			category: $send->category,
			caseId: $send->caseId
		);
		if ($send->decision['send'] !== false) {
			return;
		}

		$this->logger->info(
			'Case mail not sent: integriq said this person may not be sent it',
			['app' => Application::APP_ID, 'caseId' => $send->caseId, 'category' => $send->category, 'code' => $send->decision['code']]
		);
		throw new RecipientOptedOutException(reasonCode: $send->decision['code'], reason: $send->decision['reason']);
	}//end assertNotOptedOut()

	/**
	 * Hand the mail to the Mail account.
	 *
	 * @param CaseMailSend $send The send, past every check; given its delivery state here.
	 *
	 * @return void
	 */
	private function handToMailAccount(CaseMailSend $send): void {
		// H5: attachments are paths in the sending user's own files; Nextcloud
		// Mail reads them from that folder and checks the download permission.
		// Through the Mail account the unsubscribe link travels in the body
		// only, because Mail builds its own headers.
		$delivery = $this->sending->outbound->send(
			sender: $send->sender,
			to: $send->recipient,
			subject: $send->subject,
			body: $send->body,
			unsubscribe: $send->decision['unsubscribe'],
			attachments: $send->attachments,
		);

		$send->state = $delivery['state'];
	}//end handToMailAccount()

	/**
	 * Record the handed-over mail on its case.
	 *
	 * @param CaseMailSend $send The send, handed over.
	 *
	 * @return string The stored message id.
	 */
	private function recordOnCase(CaseMailSend $send): string {
		return $this->recordSentEmail(
			caseId: $send->caseId,
			fromAddress: $send->fromAddress,
			to: $send->recipient,
			subject: $send->subject,
			body: $send->body,
			delivery: $send->state,
		);
	}//end recordOnCase()

	/**
	 * Log the handover and answer the send result.
	 *
	 * @param CaseMailSend $send      The send, handed over and recorded.
	 * @param string       $messageId The stored message id.
	 *
	 * @return array<string, mixed> Send result with message ID and `state`
	 */
	private function sentResult(CaseMailSend $send, string $messageId): array {
		$this->logger->info(
			'Email for case {caseId} handed to the Mail account: {state}',
			['app' => Application::APP_ID, 'caseId' => $send->caseId, 'state' => $send->state],
		);

		return [
			'messageId' => $messageId,
			'to' => $send->recipient,
			'from' => $send->fromAddress,
			'subject' => $send->subject,
			'state' => $send->state,
			'sentAt' => date('Y-m-d\TH:i:s'),
		];
	}//end sentResult()

	/**
	 * Record a mail that went out about a case: the stored message and the timeline line.
	 *
	 * The half of sending that is dossiq's own. A mail this service sends
	 * comes through here, and so does one an OpenRegister flow step sent
	 * about a dossiq case ({@see \OCA\Dossiq\Listener\FlowEmailSentListener}),
	 * so the case reads the same whichever way the mail left.
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function recordSentEmail(
		string $caseId,
		string $fromAddress,
		string $to,
		string $subject,
		string $body,
		string $delivery = 'sent',
	): string {
		$mail = new SentCaseMail(
			caseId: $caseId, fromAddress: $fromAddress, to: $to, subject: $subject, body: $body, delivery: $delivery
		);

		return $this->recordSent(mail: $mail);
	}//end recordSentEmail()

	/**
	 * Record a sent mail as a case document and put its line on the timeline.
	 *
	 * @param SentCaseMail $mail The mail.
	 *
	 * @return string The message id of the stored email record.
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

	/**
	 * Assert that a recipient address is well-formed and allowed.
	 *
	 * H4: prevents open-relay abuse where any address could be supplied. The
	 * recipient must match the allow-list (`RecipientAllowlist`) or be a contact
	 * registered on the case. Anything else is rejected — including the case
	 * where neither source yields a single address, which is why the allow-list
	 * carries a default rather than being allowed to arrive empty.
	 *
	 * This guard rejected nothing at all until 2026-09-10: it was handed
	 * `loadCaseVariables()`'s six-key projection, which carries none of the
	 * contact fields `CaseContactDirectory` reads, so the address list was
	 * always empty and an empty list meant "no restriction".
	 *
	 * @param CaseMailSend $send The send: recipient, raw case record, case UUID and envelope from-address
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the address is invalid or not allowed
	 */
	private function assertRecipientAllowed(CaseMailSend $send): void {
		$recipient = $send->recipient;
		if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
			throw new RuntimeException('Ongeldig e-mailadres opgegeven.');
		}

		$send->allowlistEntries = $this->sending->allowlist->entriesFor(fromAddress: $send->fromAddress);
		$send->caseContacts = $this->contactDirectory->collectAddresses(caseData: $send->caseData);

		$permitted = $this->sending->allowlist->permits(
			recipient: $recipient,
			entries: $send->allowlistEntries,
			caseContacts: $send->caseContacts,
		);
		if ($permitted === true) {
			return;
		}

		$this->refuseOffListRecipient(send: $send);
	}//end assertRecipientAllowed()

	/**
	 * Log and refuse a recipient that is neither on the allow-list nor a case contact.
	 *
	 * @param CaseMailSend $send The send, with the allow-list entries and case contacts it was checked against.
	 *
	 * @return never
	 *
	 * @throws \RuntimeException Always
	 */
	private function refuseOffListRecipient(CaseMailSend $send): never {
		$this->logger->warning(
			'Blocked email to a recipient outside the allow-list',
			[
				'app' => Application::APP_ID,
				'to' => $send->recipient,
				'caseId' => $send->caseId,
				'allowlistEntries' => count($send->allowlistEntries),
				'caseContacts' => count($send->caseContacts),
			]
		);

		throw new RuntimeException(
			'Ontvanger staat niet op de lijst met toegestane e-mailadressen. '
			. 'Voeg het adres of het domein toe bij de e-mailinstellingen.'
		);
	}//end refuseOffListRecipient()

	/**
	 * Send an email using a template.
	 *
	 * @param string $caseId The case UUID
	 * @param string $templateId The email template UUID
	 * @param string $to Recipient email address
	 *
	 * @return array<string, mixed> Send result
	 *
	 * @throws \RuntimeException If template not found or sending fails
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function sendFromTemplate(
		string $caseId,
		string $templateId,
		string $to,
	): array {
		$template = $this->repository->findTemplate(templateId: $templateId);
		if ($template === null) {
			throw new RuntimeException('Email template not found');
		}

		// Load case data for variable resolution.
		$caseData = $this->repository->loadCaseVariables(caseId: $caseId);

		// Resolve template variables.
		$subjectPattern = (string)($template['subjectPattern'] ?? '');
		$bodyPattern = (string)($template['body'] ?? '');

		// 🔴 REFUSED RATHER THAN SENT WITH A HOLE IN IT. `substituteVariables()`
		// leaves a placeholder nothing answers exactly as it found it, which is
		// the right call for a preview and the wrong one for a mail: the
		// transport accepts it, the send reports success, and the only person
		// who learns of the defect is the citizen reading `{{contactNaam}}` in
		// their letter. dossiq#2950 found six shipped templates in that state
		// and nobody had reported one in 35 days.
		//
		// `findUnresolvedVariables()` has been sitting beside this method since
		// both were written, asked only by the preview endpoint. This is the
		// send path asking it.
		$unresolved = array_values(
			array_unique(
				array_merge(
					$this->findUnresolvedVariables(template: $subjectPattern, data: $caseData),
					$this->findUnresolvedVariables(template: $bodyPattern, data: $caseData)
				)
			)
		);

		if ($unresolved !== []) {
			// A RuntimeException that is not the transport sentinel becomes a
			// 400 carrying its message, which is what this is: caller-fixable,
			// and the fix is to name a placeholder the case can answer.
			throw new RuntimeException(
				'Email not sent: the template names '
				. implode(', ', array_map(static fn (string $n): string => '{{' . $n . '}}', $unresolved))
				. ', which this case cannot fill.'
			);
		}

		$subject = $this->resolveVariables(template: $subjectPattern, data: $caseData);
		$body = $this->resolveVariables(template: $bodyPattern, data: $caseData);

		// The template says what it is; anything it does not say, or says
		// wrongly, is a case-update and respects the opt-out. Never exempt
		// by accident.
		$category = (string)($template['messageCategory'] ?? '');
		if (in_array($category, CaseMailOptOut::CASE_MAIL_CATEGORIES, true) === false) {
			$category = CaseMailOptOut::CATEGORY_CASE_UPDATE;
		}

		return $this->sendEmail(caseId: $caseId, to: $to, subject: $subject, body: $body, category: $category);
	}//end sendFromTemplate()

	/**
	 * Resolve template variables in a string, HTML-escaping every value.
	 *
	 * Variables use {{variableName}} syntax.
	 *
	 * H6 XSS: case data containing HTML/JS (e.g. from citizen-submitted forms)
	 * must not execute in an email client, so this is the default surface.
	 *
	 * @param string $template The template string
	 * @param array<string, mixed> $data Available data for resolution
	 *
	 * @return string The resolved string
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveVariables(string $template, array $data): string {
		return $this->substituteVariables(
			template: $template,
			data: $data,
			escaping: self::ESCAPE_HTML
		);
	}//end resolveVariables()

	/**
	 * Resolve template variables in a string without escaping the values.
	 *
	 * Only for plain-text contexts, where HTML escaping would corrupt the
	 * rendered output and where no HTML parser ever sees the result.
	 *
	 * @param string $template The template string
	 * @param array<string, mixed> $data Available data for resolution
	 *
	 * @return string The resolved string
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resolveVariablesRaw(string $template, array $data): string {
		return $this->substituteVariables(
			template: $template,
			data: $data,
			escaping: self::ESCAPE_NONE
		);
	}//end resolveVariablesRaw()

	/**
	 * Shared {{variable}} substitution for both escaping modes.
	 *
	 * @param string $template The template string
	 * @param array<string, mixed> $data Available data for resolution
	 * @param string $escaping One of self::ESCAPE_HTML or self::ESCAPE_NONE
	 *
	 * @return string The resolved string
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	private function substituteVariables(string $template, array $data, string $escaping): string {
		return preg_replace_callback(
			'/\{\{(\w+)\}\}/',
			static function (array $matches) use ($data, $escaping): string {
				$key = $matches[1];
				if (isset($data[$key]) === true && is_scalar($data[$key]) === true) {
					$value = (string)$data[$key];
					if ($escaping === self::ESCAPE_HTML) {
						return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
					}

					return $value;
				}

				return $matches[0];
				// Leave unresolved variables as-is.
			},
			$template,
		) ?? $template;
	}//end substituteVariables()

	/**
	 * Find unresolved variables in a template string.
	 *
	 * @param string $template The template string
	 * @param array<string, mixed> $data Available data
	 *
	 * @return array<string> List of unresolved variable names
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function findUnresolvedVariables(string $template, array $data): array {
		$unresolved = [];
		preg_match_all('/\{\{(\w+)\}\}/', $template, $matches);

		foreach ($matches[1] as $key) {
			if (isset($data[$key]) === false || is_scalar($data[$key]) === false) {
				$unresolved[] = $key;
			}
		}

		return array_unique($unresolved);
	}//end findUnresolvedVariables()

	/**
	 * Extract case number from email subject.
	 *
	 * @param string $subject The email subject
	 *
	 * @return string|null The extracted case identifier or null
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function extractCaseNumber(string $subject): ?string {
		if (preg_match(self::CASE_NUMBER_PATTERN, $subject, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}//end extractCaseNumber()

	/**
	 * Process an inbound email and link it to a case.
	 *
	 * @param string $from Sender email address
	 * @param string $to Recipient email address
	 * @param string $subject Email subject
	 * @param string $body Email body
	 * @param string $inReplyTo In-Reply-To header (for threading)
	 *
	 * @return array<string, mixed> Processing result
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function processInbound(
		string $from,
		string $to,
		string $subject,
		string $body,
		string $inReplyTo = '',
	): array {
		return $this->receive(
			email: new ReceivedEmail(from: $from, to: $to, subject: $subject, body: $body, inReplyTo: $inReplyTo)
		);
	}//end processInbound()

	/**
	 * Link a received email to the case its subject names, or answer it unlinked.
	 *
	 * @param ReceivedEmail $email The email.
	 *
	 * @return array<string, mixed> Processing result
	 */
	private function receive(ReceivedEmail $email): array {
		$caseNumber = $this->extractCaseNumber(subject: $email->subject);
		$caseId = $this->caseNamed(caseNumber: $caseNumber);
		if ($caseId !== null) {
			return $this->linkToCase(email: $email, caseId: $caseId);
		}

		// Could not auto-link; add to unlinked queue.
		return [
			'linked' => false,
			'caseNumber' => $caseNumber,
			'from' => $email->from,
			'subject' => $email->subject,
			'method' => 'unlinked',
		];
	}//end receive()

	/**
	 * The case a case number names, or null when it names none or there is none.
	 *
	 * @param string|null $caseNumber The case number, or null when the subject named none.
	 *
	 * @return string|null The case id.
	 */
	private function caseNamed(?string $caseNumber): ?string {
		if ($caseNumber === null) {
			return null;
		}

		return $this->repository->findCaseIdByIdentifier(identifier: $caseNumber);
	}//end caseNamed()

	/**
	 * Record a received email on its case, and log the auto-link.
	 *
	 * @param ReceivedEmail $email  The email.
	 * @param string        $caseId The case it names.
	 *
	 * @return array<string, mixed> Processing result
	 */
	private function linkToCase(ReceivedEmail $email, string $caseId): array {
		$messageId = $this->repository->recordReceivedEmail(
			caseId: $caseId,
			from: $email->from,
			recipient: $email->to,
			subject: $email->subject,
			body: $email->body,
			inReplyTo: $email->inReplyTo,
		);

		$this->logger->info(
			'Inbound email auto-linked to case ' . $caseId,
			['app' => Application::APP_ID],
		);

		return ['linked' => true, 'caseId' => $caseId, 'messageId' => $messageId, 'method' => 'auto'];
	}//end linkToCase()

	/**
	 * Get email templates for a case type.
	 *
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<int, array<string, mixed>> List of templates
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getTemplatesForCaseType(string $caseTypeId): array {
		return $this->repository->findTemplatesForCaseType(caseTypeId: $caseTypeId);
	}//end getTemplatesForCaseType()
}//end class
