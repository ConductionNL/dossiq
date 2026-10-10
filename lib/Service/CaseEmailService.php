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

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RecipientOptedOutException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Email\CaseContactDirectory;
use OCA\Dossiq\Service\Email\CaseEmailRepository;
use OCA\Dossiq\Service\Email\CaseMailOptOut;
use OCA\Dossiq\Service\Email\MailTransportPolicy;
use OCA\Dossiq\Service\Email\OutboundCaseMail;
use OCA\Dossiq\Service\Email\OutboundState;
use OCA\Dossiq\Service\Email\RecipientAllowlist;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for case-integrated email functionality.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The twelfth and thirteenth
 * types are CaseTimeline and TimelineKinds, and they replaced nothing: a sent
 * mail now also records a line on the case timeline, which is a new fact about
 * this class rather than a new way of doing an old one. Control: per-file phpmd
 * on this file at 43150ddf is clean, and reports thirteen here, so the two are
 * exactly what crossed the threshold. The alternatives are worse than the
 * suppression. Naming the kind as a bare string would drop TimelineKinds and
 * take the drift guard with it, and an undeclared kind is refused, caught and
 * logged rather than shown. Moving the call behind a per-writer method on
 * CaseTimeline would drop TimelineKinds here and make that class know the shape
 * of every writer in the app, which is the coupling this rule exists to stop,
 * moved somewhere it is not measured.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
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
	 * The categories a case mail can carry. A handler picks the first two;
	 * a template may also declare `statutory`.
	 */
	private const CATEGORIES = [
		OptOutGate::CATEGORY_CASE_UPDATE,
		OptOutGate::CATEGORY_BESLUIT,
		OptOutGate::CATEGORY_STATUTORY,
	];

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
	 * @param OutboundCaseMail $outbound The case's Mail account, which sends and files the message
	 * @param RecipientAllowlist $allowlist Outbound recipient policy
	 * @param CaseTimeline $timeline The one seam that writes a timeline entry
	 * @param CaseMailOptOut $optOut Asks integriq first and places its unsubscribe link
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly CaseEmailRepository $repository,
		private readonly CaseContactDirectory $contactDirectory,
		private readonly OutboundCaseMail $outbound,
		private readonly RecipientAllowlist $allowlist,
		private readonly CaseTimeline $timeline,
		private readonly CaseMailOptOut $optOut,
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
	 * @return array<string, mixed> Send result with message ID and `state`
	 *         (sent, sent-not-filed, or queued when the account took it but could not send it yet)
	 *
	 * @throws \RuntimeException If the case cannot be read or the recipient is not allowed
	 * @throws RefusedException sender-not-held, or mail-account-unavailable when the account took nothing
	 * @throws RecipientOptedOutException If integriq says this person may not be sent it
	 * @throws InvalidArgumentException If the category is not one a case mail can carry
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function sendEmail(
		string $caseId,
		string $to,
		string $subject,
		string $body,
		array $attachments = [],
		string $category = OptOutGate::CATEGORY_CASE_UPDATE,
	): array {
		if (in_array($category, self::CATEGORIES, true) === false) {
			throw new InvalidArgumentException('invalid-category');
		}

		// C4 IDOR: Load the case via OR with RBAC enabled to verify the current user
		// has read access. If the case is not found (or the user has no access), OR
		// returns null — we treat that as 403.
		// The RAW record, not loadCaseVariables()'s six-key projection: the
		// recipient policy reads the case's contact fields, and the projection
		// drops every field it does not name.
		$caseData = $this->repository->loadCaseRecord(caseId: $caseId);
		if (empty($caseData) === true) {
			throw new RuntimeException('Zaak niet gevonden of geen toegang.');
		}

		// The configured transport decides the sender (decisions 165, 182).
		// Through the Mail account: the case type's declared account, else the
		// one an administrator picked; an address no account holds is refused
		// here, before anything is built (REQ-IMF-12).
		$sender      = $this->outbound->senderFor(caseData: $caseData, kind: MailTransportPolicy::KIND_CASE_MAIL);
		$fromAddress = $sender['from'];

		// H4: Validate the recipient against the allow-list. This prevents
		// open-relay abuse where any email address could be supplied.
		$this->assertRecipientAllowed(
			recipient: $to,
			caseData: $caseData,
			caseId: $caseId,
			fromAddress: $fromAddress,
		);

		// Ask integriq after the allow-list and before anything is built or
		// sent (opt-out-before-send). A refusal leaves no mail and no record.
		$decision = $this->optOut->decide(recipient: $to, category: $category, caseId: $caseId);
		if ($decision['send'] === false) {
			$this->logger->info(
				'Case mail not sent: integriq said this person may not be sent it',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'category' => $category, 'code' => $decision['code']]
			);
			throw new RecipientOptedOutException(reasonCode: $decision['code'], reason: $decision['reason']);
		}

		// H5: attachments are paths in the sending user's own files; Nextcloud
		// Mail reads them from that folder and checks the download permission.
		// Through the Mail account the unsubscribe link travels in the body
		// only, because Mail builds its own headers.
		$delivery = $this->outbound->send(
			sender: $sender,
			to: $to,
			subject: $subject,
			body: $body,
			unsubscribe: $decision['unsubscribe'],
			attachments: $attachments,
		);

		$messageId = $this->recordSentEmail(
			caseId: $caseId,
			fromAddress: $fromAddress,
			to: $to,
			subject: $subject,
			body: $body,
			delivery: $delivery['state'],
		);

		$this->logger->info(
			'Email for case {caseId} handed to the Mail account: {state}',
			['app' => Application::APP_ID, 'caseId' => $caseId, 'state' => $delivery['state']],
		);

		return [
			'messageId' => $messageId,
			'to' => $to,
			'from' => $fromAddress,
			'subject' => $subject,
			'state' => $delivery['state'],
			'sentAt' => date('Y-m-d\TH:i:s'),
		];
	}//end sendEmail()

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
	 * @param string $delivery    What became of it: sent, sent-not-filed or queued.
	 *
	 * @return string The stored message id.
	 *
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 */
	public function recordSentEmail(
		string $caseId,
		string $fromAddress,
		string $to,
		string $subject,
		string $body,
		string $delivery = OutboundState::SENT,
	): string {
		// Record the sent email as a case document.
		$messageId = $this->repository->recordSentEmail(
			caseId: $caseId,
			fromAddress: $fromAddress,
			to: $to,
			subject: $subject,
			body: $body,
		);

		// PUBLIC: the recipient already has this message in their own inbox,
		// so hiding its line from the timeline they are shown would only hide
		// it from the person who has it.
		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::MAIL_OUT,
			message: $subject,
			fields: [
				'recipient' => $to,
				'subject' => $subject,
				'documentId' => (string)$messageId,
				'delivery' => $delivery,
				'sender' => $fromAddress,
			],
			visibility: CaseTimeline::PUBLIC_ENTRY,
		);

		return (string)$messageId;
	}//end recordSentEmail()

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
	 * @param string $recipient The recipient email address
	 * @param array<string, mixed> $caseData The raw case record
	 * @param string $caseId The case UUID (logging context)
	 * @param string $fromAddress The resolved envelope from-address
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the address is invalid or not allowed
	 */
	private function assertRecipientAllowed(
		string $recipient,
		array $caseData,
		string $caseId,
		string $fromAddress,
	): void {
		if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
			throw new RuntimeException('Ongeldig e-mailadres opgegeven.');
		}

		$entries = $this->allowlist->entriesFor(fromAddress: $fromAddress);
		$caseContacts = $this->contactDirectory->collectAddresses(caseData: $caseData);

		$permitted = $this->allowlist->permits(
			recipient: $recipient,
			entries: $entries,
			caseContacts: $caseContacts,
		);
		if ($permitted === true) {
			return;
		}

		$this->logger->warning(
			'Blocked email to a recipient outside the allow-list',
			[
				'app' => Application::APP_ID,
				'to' => $recipient,
				'caseId' => $caseId,
				'allowlistEntries' => count($entries),
				'caseContacts' => count($caseContacts),
			]
		);

		throw new RuntimeException(
			'Ontvanger staat niet op de lijst met toegestane e-mailadressen. '
			. 'Voeg het adres of het domein toe bij de e-mailinstellingen.'
		);
	}//end assertRecipientAllowed()

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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
		if (in_array($category, self::CATEGORIES, true) === false) {
			$category = OptOutGate::CATEGORY_CASE_UPDATE;
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function processInbound(
		string $from,
		string $to,
		string $subject,
		string $body,
		string $inReplyTo = '',
	): array {
		$caseNumber = $this->extractCaseNumber(subject: $subject);

		if ($caseNumber !== null) {
			// Auto-link to case.
			$caseId = $this->repository->findCaseIdByIdentifier(identifier: $caseNumber);
			if ($caseId !== null) {
				$messageId = $this->repository->recordReceivedEmail(
					caseId: $caseId,
					from: $from,
					recipient: $to,
					subject: $subject,
					body: $body,
					inReplyTo: $inReplyTo,
				);

				$this->logger->info(
					'Inbound email auto-linked to case ' . $caseId,
					['app' => Application::APP_ID],
				);

				return [
					'linked' => true,
					'caseId' => $caseId,
					'messageId' => $messageId,
					'method' => 'auto',
				];
			}//end if
		}//end if

		// Could not auto-link; add to unlinked queue.
		return [
			'linked' => false,
			'caseNumber' => $caseNumber,
			'from' => $from,
			'subject' => $subject,
			'method' => 'unlinked',
		];
	}//end processInbound()

	/**
	 * Get email templates for a case type.
	 *
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<int, array<string, mixed>> List of templates
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getTemplatesForCaseType(string $caseTypeId): array {
		return $this->repository->findTemplatesForCaseType(caseTypeId: $caseTypeId);
	}//end getTemplatesForCaseType()
}//end class
