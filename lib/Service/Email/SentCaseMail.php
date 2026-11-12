<?php

/**
 * One mail that went out about a case, as CaseEmailService records it.
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
 * @spec openspec/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * A sent mail: the case, who sent it to whom, its subject and body, and how it was delivered.
 *
 * @spec openspec/specs/case-history-surface/spec.md
 */
final class SentCaseMail {

	/**
	 * Constructor.
	 *
	 * @param string $caseId      The case UUID.
	 * @param string $fromAddress The sending address.
	 * @param string $to          The recipient address.
	 * @param string $subject     The subject.
	 * @param string $body        The body.
	 * @param string $delivery    How the mail left: `sent`, or the delivery state the outbox answered.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $fromAddress,
		public readonly string $to,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $delivery,
	) {
	}//end __construct()
}//end class
