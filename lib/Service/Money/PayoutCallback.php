<?php

/**
 * One signed callback on a payout
 *
 * The reference it names, the state it reports, and, when given, the day it
 * was paid and the bank's reference.
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

use DateTimeImmutable;

/**
 * The callback PayoutService applies to the payout row it names.
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
 */
final class PayoutCallback {
	/**
	 * Constructor.
	 *
	 * @param string                 $reference           Payment reference.
	 * @param string                 $status              New stored status (in-handling/paid/rejected).
	 * @param DateTimeImmutable|null $paymentDate         Actual payment date.
	 * @param string                 $betalingsreferentie ERP/bank reference, '' when none.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $reference,
		public readonly string $status,
		public readonly ?DateTimeImmutable $paymentDate,
		public readonly string $betalingsreferentie,
	) {
	}//end __construct()
}//end class
