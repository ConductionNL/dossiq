<?php

/**
 * Dossiq NoticeNotSentException.
 *
 * A term notice did not leave. The caller must not count it, record it as sent
 * or tell the handler it went out.
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
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
 */

declare(strict_types=1);

namespace OCA\Dossiq\Exception;

use RuntimeException;

/**
 * Nothing was sent. The code says why.
 *
 * Codes: integriq's (`opted-out`, `no-consent`, `authority-unavailable`), or
 * dossiq's own (`no-email-address`, `no-sender-address`, `mail-failed`,
 * `in-flight`).
 *
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
 */
class NoticeNotSentException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $reasonCode Why nothing was sent.
	 * @param string $reason     The sentence for the log.
	 */
	public function __construct(
		private readonly string $reasonCode,
		string $reason = '',
	) {
		if ($reason === '') {
			$reason = 'The notice was not sent.';
		}

		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * Why nothing was sent.
	 *
	 * @return string The code.
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070
	 */
	public function getReasonCode(): string {
		return $this->reasonCode;
	}//end getReasonCode()
}//end class
