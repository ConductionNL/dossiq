<?php

/**
 * Contract of CaseEmailService
 *
 * The public surface of CaseEmailService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use OCA\Dossiq\Exception\RecipientOptedOutException;
use OCA\Dossiq\Service\Email\CaseMailOptOut;

/**
 * Contract of CaseEmailService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseEmailServiceInterface {

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
	public function findUnresolvedVariables(string $template, array $data): array;

	/**
	 * Get email templates for a case type.
	 *
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return array<int, array<string, mixed>> List of templates
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getTemplatesForCaseType(string $caseTypeId): array;

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
	): string;

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
	public function resolveVariables(string $template, array $data): string;

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
	): array;

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
	): array;
}//end interface
