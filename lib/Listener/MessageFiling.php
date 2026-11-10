<?php

/**
 * Dossiq message filing
 *
 * What answering one message integriq offers shares between its steps.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

/**
 * One offered message on its way to a case, or to the inbox.
 *
 * Built and filled by {@see MessageReceivedListener} only; it never leaves it.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
final class MessageFiling {

	/**
	 * The message integriq offers.
	 *
	 * @var array<string, mixed>
	 */
	public array $message = [];

	/**
	 * Integriq's id of the message.
	 *
	 * @var string
	 */
	public string $messageUuid = '';

	/**
	 * The case reference integriq detected in it, or an empty string.
	 *
	 * @var string
	 */
	public string $reference = '';

	/**
	 * The case it is filed on, or an empty string.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The intake log outcome.
	 *
	 * @var string
	 */
	public string $logOutcome = '';

	/**
	 * Why it was filed this way, as the intake log says.
	 *
	 * @var string
	 */
	public string $reason = '';

	/**
	 * The outcome integriq is told: linked, created or declined.
	 *
	 * @var string
	 */
	public string $answer = '';
}//end class
