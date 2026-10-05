<?php

/**
 * Dossiq RecipientOptedOutException.
 *
 * @category Exception
 * @package  OCA\Dossiq\Exception
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
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Exception;

use RuntimeException;

/**
 * integriq said this person may not be sent this message. Nothing was sent.
 *
 * The reason code is integriq's: `opted-out`, `no-consent`, or
 * `authority-unavailable` when integriq could not answer.
 *
 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001
 */
class RecipientOptedOutException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $reasonCode The decision code.
	 * @param string $reason     Why, as integriq said it.
	 */
	public function __construct(
		private readonly string $reasonCode,
		string $reason = '',
	) {
		parent::__construct($reason !== '' ? $reason : 'The recipient may not be sent this message. Nothing was sent.');
	}//end __construct()

	/**
	 * The decision code.
	 *
	 * @return string The code.
	 */
	public function getReasonCode(): string {
		return $this->reasonCode;
	}//end getReasonCode()
}//end class
