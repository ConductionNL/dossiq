<?php

/**
 * Contract of CaseRiskAssessmentService
 *
 * The public surface of CaseRiskAssessmentService that other components call, so they depend
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
 * Contract of CaseRiskAssessmentService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRiskAssessmentServiceInterface {

	/**
	 * The top-level mirror of the level, for the save that is being written.
	 *
	 * A facet cannot be taken over a nested object property, so the work list
	 * filters on `riskLevel` beside the assessment. It is derived here on
	 * every save rather than typed, so the mirror and the assessment cannot
	 * disagree, and it sits behind the same declared rule so it is absent for
	 * a reader without the permission rather than blanked in a column they can
	 * see.
	 *
	 * @param array<string, mixed> $case The case being saved.
	 *
	 * @return array{riskLevel: string|null} The field to write back.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function resolve(array $case): array;
}//end interface
