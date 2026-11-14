<?php

/**
 * Contract of CasePaymentReader
 *
 * The public surface of CasePaymentReader that other components call, so they depend
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

/**
 * Contract of CasePaymentReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CasePaymentReaderInterface {

	/**
	 * The app that holds the money.
	 *
	 * @var string
	 */
	public const MONEY_APP = 'shillinq';

	/**
	 * The payment state of one case, and when it was read.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{paymentState: string, paymentStateCheckedAt: string} The projection.
	 *
	 * @spec openspec/specs/financial-integration/spec.md#requirement-the-payment-state-is-on-the-case-and-read-from-shillinq-req-fee-02
	 */
	public function stateOf(string $caseId): array;
}//end interface
