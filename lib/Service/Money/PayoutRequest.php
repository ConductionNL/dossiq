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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

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
}//end class
