<?php

/**
 * Contract of StatusDeclarations
 *
 * The public surface of StatusDeclarations that other components call, so they depend
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

/**
 * Contract of StatusDeclarations.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StatusDeclarationsInterface {

	/**
	 * Settle the dwell fields on a payload about to be saved.
	 *
	 * @param array<string, mixed>   $case     The case payload.
	 * @param string                 $toStatus The status being entered.
	 * @param DateTimeImmutable|null $now      The moment of the move.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function applyStatusChange(array $case, string $toStatus, ?DateTimeImmutable $now = null): array;
}//end interface
