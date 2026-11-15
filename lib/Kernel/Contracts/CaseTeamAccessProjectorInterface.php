<?php

/**
 * Contract of CaseTeamAccessProjector
 *
 * The public surface of CaseTeamAccessProjector that other components call, so they depend
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
 * Contract of CaseTeamAccessProjector.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTeamAccessProjectorInterface {

	/**
	 * Every case type with its handling teams.
	 *
	 * @return array<int, array{id: string, teams: array<int, string>}> The case types.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function caseTypeTeams(): array;

	/**
	 * Compute the case schema's block from the case types and write it when it changed.
	 *
	 * A failure is logged and answered false: a case type save must not fail
	 * because the schema could not be written, and the next save or import
	 * writes the rules again.
	 *
	 * @return bool True when the schema was rewritten.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function reapply(): bool;
}//end interface
