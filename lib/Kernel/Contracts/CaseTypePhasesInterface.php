<?php

/**
 * Contract of CaseTypePhases
 *
 * The public surface of CaseTypePhases that other components call, so they depend
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
 * Contract of CaseTypePhases.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypePhasesInterface {

	/**
	 * The phases of a case type, each with its tasks in order, and the acts
	 * available in every phase.
	 *
	 * A step whose task is switched off is left out: the case type does not run
	 * it. A step naming a status the type does not have is not dropped silently
	 * but listed under `unplaced`, so an author sees it.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array{caseTypeId: string, phases: array<int, array<string, mixed>>,
	 *               unplaced: array<int, array<string, mixed>>,
	 *               alwaysAvailable: array<int, array<string, mixed>>, taskCount: int}|null
	 *         The phases, or null when the case type cannot be read.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function phasesFor(string $caseTypeId): ?array;
}//end interface
