<?php

/**
 * One email handed to CaseEmailService to link to a case.
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * A received email: who sent it to whom, its subject and body, and the message it answers.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
final class ReceivedEmail {

	/**
	 * Constructor.
	 *
	 * @param string $from      Sender email address.
	 * @param string $to        Recipient email address.
	 * @param string $subject   Email subject.
	 * @param string $body      Email body.
	 * @param string $inReplyTo In-Reply-To header (for threading), '' when none.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $from,
		public readonly string $to,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $inReplyTo,
	) {
	}//end __construct()
}//end class
