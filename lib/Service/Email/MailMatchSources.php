<?php

/**
 * What matching a mail to a case reads: the preferences, the case number recognizer and the mail messages.
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
 * @spec openspec/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

/**
 * What matching a mail to a case reads: the preferences, the case number recognizer and the mail messages.
 *
 * @spec openspec/specs/email-case-matching/spec.md
 */
final class MailMatchSources {
	/**
	 * Constructor.
	 *
	 * @param CaseEmailMatchPreferences $preferences Per-user settings, cursor and status.
	 * @param CaseNumberRecognizer      $recognizer  Case numbers in text, resolved to cases.
	 * @param MailMessageSource         $messages    Mail accounts and messages.
	 */
	public function __construct(
		public readonly CaseEmailMatchPreferences $preferences,
		public readonly CaseNumberRecognizer $recognizer,
		public readonly MailMessageSource $messages,
	) {
	}//end __construct()
}//end class
