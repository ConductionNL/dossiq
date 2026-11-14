<?php

/**
 * Contract of CaseTypeRetirementService
 *
 * The public surface of CaseTypeRetirementService that other components call, so they depend
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
 * Contract of CaseTypeRetirementService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeRetirementServiceInterface {

	/**
	 * Offer a retired case type again.
	 *
	 * @param string $caseTypeId The case type's id.
	 *
	 * @return array{ok: bool, reason: string, state: string} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function restore(string $caseTypeId): array;

	/**
	 * Stop a case type taking new cases, from today.
	 *
	 * @param string $caseTypeId The case type's id.
	 *
	 * @return array{ok: bool, reason: string, state: string} What happened.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	public function retire(string $caseTypeId): array;
}//end interface
