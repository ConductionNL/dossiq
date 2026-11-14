<?php

/**
 * Contract of MandaatCheckService
 *
 * The public surface of MandaatCheckService that other components call, so they depend
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
use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of MandaatCheckService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MandaatCheckServiceInterface {

	/**
	 * Applicable mandates for the given user (filtered to their active role).
	 *
	 * Returns the same row shape as {@see getApplicableMandaten()}, augmented
	 * with a `unilateral` flag (true when the user can take the decision
	 * unilaterally, i.e. without escalation). Empty result when the user holds
	 * no active role.
	 *
	 * @param string $userId User id.
	 * @param string $caseType Case type slug (empty = no filter).
	 * @param string $decisionType Decision type slug (empty = list all).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RefusedException When the mandate register could not be read at all.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-08-user-ui/tasks.md
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	public function getApplicableForUser(string $userId, string $caseType = '', string $decisionType = ''): array;

	/**
	 * Decide whether the user is authorized for the (decisionType, case) pair.
	 *
	 * @param string $userId Nextcloud user id.
	 * @param string $decisionType Decision type slug.
	 * @param string $caseId Case id.
	 * @param array<string, mixed> $caseProperties Case properties for condition matching.
	 * @param DateTimeImmutable|null $decisionDate Optional override (defaults to now).
	 *
	 * @return array{authorized:bool, mandaatId?:string, reden?:string|null, conflictReason?:string, failedConditions?:array<int,string>,
	 *               relationCheck?:string, relationCheckReason?:string}
	 *
	 * @throws RefusedException When the mandate register could not be read at all.
	 *
	 * @spec openspec/changes/archive/2026-06-13-mandaat-matrix-02-authorization-engine/tasks.md
	 * @spec openspec/specs/quality-gates/spec.md
	 * @spec openspec/changes/brp-client-retired/specs/mandaat-matrix/spec.md#requirement-conflict-of-interest-detection
	 */
	public function isAuthorized(
		string $userId,
		string $decisionType,
		string $caseId,
		array $caseProperties = [],
		?DateTimeImmutable $decisionDate = null,
	): array;
}//end interface
