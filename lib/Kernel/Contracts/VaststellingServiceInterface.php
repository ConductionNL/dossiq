<?php

/**
 * Contract of VaststellingService
 *
 * The public surface of VaststellingService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Contract of VaststellingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VaststellingServiceInterface {

	/**
	 * Finalise a settlement: persist the vastgesteld bedrag and, when the
	 * advances exceed it, open a clawback case for the difference. The
	 * clawback case itself is created in "draft" awaiting manager
	 * approval — this method never publishes it.
	 *
	 * @param string $determinationId The settlement id.
	 * @param float $grantedAmount The granted amount.
	 * @param float $actualCost The total actual costs.
	 * @param float $totalAdvances The cumulative disbursed advances.
	 *
	 * @return array<string, mixed> The finalisation result with optional clawback.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidie-settlement-case-costs/spec.md#requirement-settling-a-grant-records-the-settled-amount-on-the-vaststelling-and-writes-nothing-to-the-linked-case-req-ssc-005
	 */
	public function finalize(
		string $determinationId,
		float $grantedAmount,
		float $actualCost,
		float $totalAdvances,
	): array;
}//end interface
