<?php

/**
 * Dossiq manual filing.
 *
 * One intake log entry a handler files by hand on a case they picked, with
 * the reason they overrode the matcher.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

/**
 * A message being filed by hand.
 *
 * Built by {@see MailIntakeController::fileOnCase()} only; it never leaves it.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
final class ManualFiling {

	/**
	 * The log entry.
	 *
	 * @var string
	 */
	public string $entryId = '';

	/**
	 * The stored entry.
	 *
	 * @var array<string, mixed>
	 */
	public array $entry = [];

	/**
	 * The case the handler picked, trimmed.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * Why they overrode the match.
	 *
	 * @var string
	 */
	public string $reason = '';
}//end class
