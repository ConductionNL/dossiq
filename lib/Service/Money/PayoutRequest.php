<?php

/**
 * One payout of a decided dwangsom being prepared
 *
 * The accruing amount it pays, whom to and on which account, the amount
 * decided, and the latest day it may be paid: the input of
 * PayoutService::preparePayout(), carried as one value to the row it stores.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use RuntimeException;

/**
 * The fields of one payout to prepare; it never leaves the service that built it.
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
 */
final class PayoutRequest {

	/**
	 * The accruing amount id.
	 *
	 * @var string
	 */
	public string $calculationId = '';

	/**
	 * Whom the payout goes to.
	 *
	 * @var string
	 */
	public string $accountHolderName = '';

	/**
	 * The account, as typed.
	 *
	 * @var string
	 */
	public string $iban = '';

	/**
	 * The decided amount in cents.
	 *
	 * @var integer
	 */
	public int $amount = 0;

	/**
	 * The latest day the payout may be paid (Y-m-d).
	 *
	 * @var string
	 */
	public string $paymentDateLatest = '';

	/**
	 * Refuse a request whose IBAN is invalid or whose account holder is blank.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the IBAN or the account holder name is invalid.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function assertPayable(): void {
		if ($this->hasValidIban() === false) {
			throw new RuntimeException('Invalid IBAN provided for dwangsom uitbetaling');
		}

		if (trim($this->accountHolderName) === '') {
			throw new RuntimeException('rekeninghouderNaam is required');
		}
	}//end assertPayable()

	/**
	 * Conservative IBAN check of the account (length + mod-97).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function hasValidIban(): bool {
		$iban = strtoupper(preg_replace('/\s+/', '', $this->iban));
		if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,32}$/', $iban) !== 1) {
			return false;
		}

		$rearranged = substr($iban, 4) . substr($iban, 0, 4);
		$expanded = '';
		foreach (str_split($rearranged) as $ch) {
			if (ctype_alpha($ch) === true) {
				$expanded .= (string)(ord($ch) - 55);
				continue;
			}

			$expanded .= $ch;
		}

		// Mod-97 over a string (PHP int can't hold this directly).
		$remainder = '';
		foreach (str_split($expanded) as $digit) {
			$remainder = (string)(((int)($remainder . $digit)) % 97);
		}

		return ((int)$remainder === 1);
	}//end hasValidIban()
}//end class
