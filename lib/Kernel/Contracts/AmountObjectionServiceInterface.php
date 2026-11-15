<?php

/**
 * Contract of AmountObjectionService
 *
 * The public surface of AmountObjectionService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of AmountObjectionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AmountObjectionServiceInterface {

	/**
	 * Register an objection against an accruing amount.
	 *
	 * Freezes the berekening (status=bezwaar-bevroren) and puts the
	 * linked uitbetaling on hold.
	 *
	 * @param string $calculationId Accruing amount id.
	 * @param string $basis Legal basis citation.
	 * @param string $rationale Reasoning.
	 *
	 * @return array<string, mixed> The frozen berekening row.
	 *
	 * @throws RuntimeException When the berekening is missing.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function registerObjection(string $calculationId, string $basis, string $rationale): array;

	/**
	 * Resolve a bezwaar with a corrected amount.
	 *
	 * @param string $calculationId Berekening id.
	 * @param int $newAmountCents Corrected amount in EUR cents.
	 * @param string $basis Legal basis.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When berekening missing or amount invalid.
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function resolveObjection(string $calculationId, int $newAmountCents, string $basis): array;
}//end interface
