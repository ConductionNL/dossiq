<?php

/**
 * Contract of SubstitutionService
 *
 * The public surface of SubstitutionService that other components call, so they depend
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
 * Contract of SubstitutionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubstitutionServiceInterface {

	/**
	 * Create a substitution after validation.
	 *
	 * Rejects self-substitution, missing/invalid period, and a same-period
	 * overlapping full-scope substitution for the same absentee. A disjoint
	 * scope for the same period is accepted.
	 *
	 * @param string $absentee Handler being covered (user id).
	 * @param string $substitute Waarnemer (user id).
	 * @param string $startDate Inclusive start (Y-m-d).
	 * @param string $endDate Inclusive end (Y-m-d), required.
	 * @param string $scope One of all|caseTypes|cases.
	 * @param array<int, string> $scopeRefs caseType/case UUIDs when narrowed.
	 * @param string $reason One of verlof|ziekte|anders.
	 * @param string $createdBy Creating user id (self or coordinator).
	 * @param string $comment Optional free-text comment.
	 *
	 * @return array<string, mixed> The created substitution object.
	 *
	 * @throws \InvalidArgumentException On any validation failure.
	 * @throws \RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function create(
		string $absentee,
		string $substitute,
		string $startDate,
		string $endDate,
		string $scope = 'all',
		array $scopeRefs = [],
		string $reason = 'verlof',
		string $createdBy = '',
		string $comment = '',
	): array;

	/**
	 * Resolve the substituted open cases and tasks routed to a waarnemer.
	 *
	 * For each active substitution the absentee's open cases/tasks within the
	 * substitution scope are gathered. Because the OpenRegister ObjectService
	 * search runs in the calling user's (the substitute's) RBAC context, items
	 * the substitute cannot read are already excluded — the service never
	 * elevates. Each returned item is annotated with the substitution context
	 * so the UI can render the "waargenomen voor {naam}" badge.
	 *
	 * @param string $userId The waarnemer (substitute) user id.
	 * @param DateTimeImmutable|null $date Reference date; defaults to today.
	 *
	 * @return array{cases: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function getSubstitutedWorkFor(string $userId, ?DateTimeImmutable $date = null): array;

	/**
	 * Revoke a substitution immediately (status -> revoked).
	 *
	 * @param string $id The substitution UUID.
	 *
	 * @return array<string, mixed>|null The updated object, or null when not found.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function revoke(string $id): ?array;
}//end interface
