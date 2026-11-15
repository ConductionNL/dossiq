<?php

/**
 * Contract of PenaltyPaymentRows
 *
 * The public surface of PenaltyPaymentRows that other components call, so they depend
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
 * Contract of PenaltyPaymentRows.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PenaltyPaymentRowsInterface {

	/**
	 * The payments forfeited in a year, newest forfeit first, and their total.
	 *
	 * @param int $year The year.
	 *
	 * @return array<string, mixed> The year, its rows, the summary and the summary as one row (`summaryRows`).
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md#requirement-the-annual-audit-lists-the-forfeited-payments-by-case
	 */
	public function forYear(int $year): array;
}//end interface
