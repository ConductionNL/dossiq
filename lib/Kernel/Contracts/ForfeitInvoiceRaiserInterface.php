<?php

/**
 * Contract of ForfeitInvoiceRaiser
 *
 * The public surface of ForfeitInvoiceRaiser that other components call, so they depend
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

use OCA\Dossiq\Exception\ForfeitInvoiceNotRaisedException;

/**
 * Contract of ForfeitInvoiceRaiser.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ForfeitInvoiceRaiserInterface {

	/**
	 * Raise the invoice for a forfeited act, when its case type says so.
	 *
	 * @param array<string, mixed> $action The forfeited enforcement act.
	 *
	 * @return string|null The payment request id, or null when the case type does not ask
	 *                     for an invoice, nobody can be billed or there is no finance app.
	 *
	 * @throws ForfeitInvoiceNotRaisedException When the case cannot be read or the finance app refuses.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
	 */
	public function raise(array $action): ?string;
}//end interface
