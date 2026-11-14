<?php

/**
 * Contract of LhsRecommendationService
 *
 * The public surface of LhsRecommendationService that other components call, so they depend
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
 * Contract of LhsRecommendationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LhsRecommendationServiceInterface {

	/**
	 * Apply an override to an existing LHS recommendation.
	 *
	 * Override-down (selecting an intervention of equal or lower severity than
	 * the recommendation) is allowed for any inspector with a justification of
	 * at least 20 non-whitespace characters. Override-up (harsher than the
	 * recommendation) requires the manager role and is persisted with
	 * `overrideAuthority = "manager"`.
	 *
	 * 🔴 THE STORED ROW IS THE ONLY SOURCE. This method takes an ID and reads
	 * the recommendation back from OpenRegister; it does NOT accept the row
	 * from the caller.
	 *
	 * It used to. The caller passed the whole recommendation array, and the
	 * escalation guard compared the requested intervention against the
	 * `recommendedIntervention` IN THAT ARRAY. So an inspector could post a body
	 * claiming the matrix had already recommended `bestuursdwang`, "override
	 * down" to anything, and never meet the manager gate — while the same
	 * array_merge also let them rewrite `severity`, `behaviour`, `matrixVersion`
	 * and `recommendedBy` on the persisted row. The audit record of what the
	 * matrix said would then agree with them.
	 *
	 * @param string $recommendationId Id of the stored recommendation
	 * @param string $intervention Chosen intervention (enum value)
	 * @param string $justification Mandatory justification (>= 20 chars)
	 * @param string $userRole Caller role: "inspector" or "manager"
	 *
	 * @return array<string, mixed> The updated recommendation row
	 *
	 * @throws RuntimeException When validation fails (HTTP-mapped by controller).
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function override(
		string $recommendationId,
		string $intervention,
		string $justification,
		string $userRole,
	): array;

	/**
	 * Recommend an intervention for the given (ernst, gedrag, actorType).
	 *
	 * Loads the matrix (active by default, or the explicitly requested
	 * version), maps cells into an in-memory dictionary keyed
	 * "severity:behaviour:actorType", and persists an `lhsRecommendation` row
	 * carrying the lookup result. Identity is always derived from the
	 * session — never from caller-supplied data.
	 *
	 * @param string $caseId The parent case UUID
	 * @param string $severity Severity axis value
	 * @param string $behaviour Behaviour axis value
	 * @param string $actorType Actor-type axis value
	 * @param int|null $lhsVersion Optional explicit matrix version; null = active
	 * @param string|null $inspection Optional inspection rapport UUID for traceability
	 *
	 * @return array<string, mixed> The persisted recommendation row
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, no matching
	 *                          matrix exists, or no cell matches the triple.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function recommend(
		string $caseId,
		string $severity,
		string $behaviour,
		string $actorType,
		?int $lhsVersion = null,
		?string $inspection = null,
	): array;
}//end interface
