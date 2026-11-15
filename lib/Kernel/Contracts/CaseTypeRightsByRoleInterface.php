<?php

/**
 * Contract of CaseTypeRightsByRole
 *
 * The public surface of CaseTypeRightsByRole that other components call, so they depend
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
 * Contract of CaseTypeRightsByRole.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeRightsByRoleInterface {

	/**
	 * One row per role: `{ role, seeCases, handle, decide, documents }`.
	 *
	 * See cases names the departments whose cases the role reads (Own team for
	 * `$ownTeam`, All for every department). Handle is All phases, the status
	 * names the role's edit rights are limited to, or No. Decide reads the
	 * mandate register: the highest ceiling of the role's mandates for this case
	 * type, Unlimited without a ceiling, No without a mandate. Documents is All
	 * (delete), Add or Read, from `documentActions` or, without it, the verbs.
	 * A row past its `until` grants nothing.
	 *
	 * @param string $caseTypeId The case type id.
	 *
	 * @return array<int, array<string, string>>|null The rows, or null when there is no such case type.
	 *
	 * @spec openspec/changes/case-type-rights-by-role/specs/case-types/spec.md
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function rowsFor(string $caseTypeId): ?array;
}//end interface
