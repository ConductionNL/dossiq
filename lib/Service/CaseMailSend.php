<?php

/**
 * Dossiq CaseMailSend
 *
 * One case mail while CaseEmailService sends it: the case, the letter, and
 * what the case record, the sender and integriq's opt-out decision add on the
 * way out.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one case mail send, built and read by CaseEmailService only.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class CaseMailSend {
	/**
	 * The case UUID.
	 *
	 * @var string
	 */
	public readonly string $caseId;

	/**
	 * The recipient address.
	 *
	 * @var string
	 */
	public readonly string $recipient;

	/**
	 * The subject.
	 *
	 * @var string
	 */
	public string $subject = '';

	/**
	 * The body.
	 *
	 * @var string
	 */
	public string $body = '';

	/**
	 * Paths in the sending user's own files.
	 *
	 * @var array<string>
	 */
	public array $attachments = [];

	/**
	 * What the mail is.
	 *
	 * @var string
	 */
	public string $category = '';

	/**
	 * The raw case record.
	 *
	 * @var array<string, mixed>
	 */
	public array $caseData = [];

	/**
	 * The sender the configured transport chose.
	 *
	 * @var array<string, mixed>
	 */
	public array $sender = [];

	/**
	 * The envelope from-address.
	 *
	 * @var string
	 */
	public string $fromAddress = '';

	/**
	 * Integriq's opt-out decision.
	 *
	 * @var array<string, mixed>
	 */
	public array $decision = [];

	/**
	 * The allow-list entries the recipient was checked against.
	 *
	 * @var array<string>
	 */
	public array $allowlistEntries = [];

	/**
	 * The case's contact addresses the recipient was checked against.
	 *
	 * @var array<string>
	 */
	public array $caseContacts = [];

	/**
	 * What became of it: an OutboundState value.
	 *
	 * @var string
	 */
	public string $state = '';

	/**
	 * Start a send about one case to one recipient.
	 *
	 * @param string $caseId    The case UUID.
	 * @param string $recipient The recipient address.
	 */
	public function __construct(string $caseId, string $recipient) {
		$this->caseId = $caseId;
		$this->recipient = $recipient;
	}//end __construct()
}//end class
