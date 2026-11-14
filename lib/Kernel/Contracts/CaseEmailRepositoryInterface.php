<?php

/**
 * Contract of CaseEmailRepository
 *
 * The public surface of CaseEmailRepository that other components call, so they depend
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

/**
 * Contract of CaseEmailRepository.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseEmailRepositoryInterface {

	/**
	 * Find a case UUID by its human-readable identifier.
	 *
	 * @param string $identifier The case identifier (e.g., 2026-0042)
	 *
	 * @return string|null The case UUID or null
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function findCaseIdByIdentifier(string $identifier): ?string;

	/**
	 * Record a received email.
	 *
	 * @param string $caseId Case UUID
	 * @param string $from Sender
	 * @param string $recipient Recipient (the mailbox the message arrived on)
	 * @param string $subject Subject
	 * @param string $body Body
	 * @param string $inReplyTo Threading header
	 *
	 * @return string The recorded message ID
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function recordReceivedEmail(
		string $caseId,
		string $from,
		string $recipient,
		string $subject,
		string $body,
		string $inReplyTo,
	): string;
}//end interface
