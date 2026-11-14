<?php

/**
 * Contract of RoleResolverService
 *
 * The public surface of RoleResolverService that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Routing\RoutingStrategyMissingException;

/**
 * Contract of RoleResolverService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RoleResolverServiceInterface {

	/**
	 * Invalidate the cache for every rule against a case.
	 *
	 * Called by the role-mutation listener.
	 *
	 * @param string $caseId The case UUID/id
	 *
	 * @return void
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function invalidateCache(string $caseId): void;

	/**
	 * Normalise a step or transition into a concrete routing rule.
	 *
	 * Order of precedence:
	 *   1. Explicit `routingRule` object on the step/transition.
	 *   2. Legacy `assigneeRole` (UUID) -> single-role.
	 *   3. Legacy `allowedRoles` (UUID array) -> or-set.
	 *
	 * Returns null when nothing routable is declared (caller decides default).
	 *
	 * @param array<string, mixed> $entry The step or transition payload
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 */
	public function normaliseRule(array $entry): ?array;

	/**
	 * Resolve a routing rule against a case.
	 *
	 * @param array<string, mixed> $rule The (already normalised) routing rule
	 * @param array<string, mixed> $case The case object (must include id, caseType)
	 *
	 * @return array<int, string> Ordered participant refs (post-delegation)
	 *
	 * @throws RoutingStrategyMissingException When the rule's strategy is unknown
	 * @throws RefusedException When the case's roles could not be read at all
	 *
	 * @spec openspec/specs/role-based-step-routing/spec.md
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	public function resolve(array $rule, array $case): array;
}//end interface
