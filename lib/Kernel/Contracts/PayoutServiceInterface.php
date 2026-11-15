<?php

/**
 * Contract of PayoutService
 *
 * The public surface of PayoutService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use DateTimeImmutable;
use RuntimeException;

/**
 * Contract of PayoutService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PayoutServiceInterface {

	/**
	 * Handle a signed callback updating the payout state.
	 *
	 * @param string $reference Payment reference.
	 * @param string $status New stored status (in-handling/paid/rejected).
	 * @param DateTimeImmutable|null $paymentDate Actual payment date.
	 * @param string $betalingsreferentie ERP/bank reference.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the referentie is unknown.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-07-financial-integration/tasks.md
	 */
	public function handleCallback(
		string $reference,
		string $status,
		?DateTimeImmutable $paymentDate,
		string $betalingsreferentie = '',
	): array;

	/**
	 * Prepare a payout from request input: accountHolderName, iban and
	 * optionally receiptDate (Y-m-d, the day the notice was received).
	 *
	 * @param string $calculationId The settled calculation.
	 * @param array<string, mixed> $input The request input.
	 *
	 * @return array<string, mixed> The saved payout row.
	 *
	 * @throws RuntimeException When the calculation is missing, the IBAN is invalid, or finance refuses.
	 * @throws \InvalidArgumentException When the receipt date is malformed.
	 *
	 * @spec openspec/specs/payout/spec.md
	 */
	public function preparePayoutFrom(string $calculationId, array $input): array;

	/**
	 * Hand a payout row to the finance app and note the payable it answers with.
	 * The repair that moves the rows dossiq kept calls it with their history
	 * (REQ-DQM-004); it passes only a row finance does not hold yet.
	 *
	 * @param array<string, mixed> $row The payout row.
	 * @param array<string, string> $history For a payout settled before finance kept it: historicalState, paidOn, bankReference.
	 *
	 * @return array<string, mixed> The row, with payableId when finance took it.
	 *
	 * @throws RuntimeException When finance refuses the payout.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function raiseRowInFinance(array $row, array $history = []): array;
}//end interface
