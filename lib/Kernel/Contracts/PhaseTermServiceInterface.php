<?php

/**
 * Contract of PhaseTermService
 *
 * The public surface of PhaseTermService that other components call, so they depend
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
 * Contract of PhaseTermService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PhaseTermServiceInterface {

	/**
	 * A case enters a phase: stop the phase clock it left, start this one.
	 *
	 * One call, because the two halves are one move. Two calls let a handler
	 * arrive in a new phase with the previous phase's clock still running, and
	 * the case then reads as overdue in a phase it has left.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $caseTypeId The case type UUID.
	 * @param string $statusTypeId The phase being entered.
	 * @param DateTimeImmutable|null $when When the move happened (default now).
	 *
	 * @return array<string, mixed>|null The started phase term, or null when the
	 *         phase declares no clock and the chain declares no term either.
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	public function enterPhase(
		string $caseId,
		string $caseTypeId,
		string $statusTypeId,
		?DateTimeImmutable $when = null,
	): ?array;
}//end interface
