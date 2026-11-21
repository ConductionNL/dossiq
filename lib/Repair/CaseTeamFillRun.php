<?php

/**
 * One pass of the case team fill repair step.
 *
 * Built by `AssignOpenCasesTheirCaseTypeTeam::assignAll()` and never handed
 * out: the ObjectService, the register and schemas it reads, and, once a case
 * type is being worked, that case type and its handling group.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * What one case team fill pass reads and writes with.
 *
 * @spec openspec/specs/case-types/spec.md
 */
final class CaseTeamFillRun {
	/**
	 * The case type schema.
	 *
	 * @var string
	 */
	public string $caseTypeSchema = '';

	/**
	 * The case schema.
	 *
	 * @var string
	 */
	public string $caseSchema = '';

	/**
	 * The case type being worked, once one is.
	 *
	 * @var string
	 */
	public string $caseTypeId = '';

	/**
	 * That case type's handling group.
	 *
	 * @var string
	 */
	public string $group = '';

	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
	) {
	}//end __construct()

	/**
	 * This pass, narrowed to one case type and its handling group.
	 *
	 * @param string $caseTypeId The case type.
	 * @param string $group      Its handling group.
	 *
	 * @return self A copy for that case type.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function forCaseType(string $caseTypeId, string $group): self {
		$run = clone $this;
		$run->caseTypeId = $caseTypeId;
		$run->group = $group;

		return $run;
	}//end forCaseType()
}//end class
