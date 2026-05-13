<?php

/**
 * Procest Routing Strategy Interface
 *
 * Contract implemented by every routing strategy class. Each strategy receives
 * a routing rule plus the case context and returns an ordered array of
 * participant references. The RoleResolverService dispatches resolution to the
 * strategy keyed by `rule.strategy` via the StrategyRegistry.
 *
 * @category Service
 * @package  OCA\Procest\Service\Routing
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Routing;

/**
 * Common contract for routing strategies.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
interface RoutingStrategyInterface
{
    /**
     * The unique strategy name as referenced by `routingRule.strategy`.
     *
     * @return string The strategy key (e.g. "single-role")
     */
    public function name(): string;

    /**
     * Resolve a routing rule against a case to a list of participant refs.
     *
     * The returned array contains the raw participant references
     * (Nextcloud user IDs or contact UUIDs) in the order callers may choose
     * to assign. An empty array means "no eligible assignee" — callers MUST
     * surface this to the case admin.
     *
     * @param array<string, mixed>             $rule  The routing rule (strategy + parameters)
     * @param array<string, mixed>             $case  The case object (id, caseType,
     *                                                …)
     * @param array<int, array<string, mixed>> $roles Role objects bound to the case
     *
     * @return array<int, string> Ordered participant references
     */
    public function resolve(array $rule, array $case, array $roles): array;
}//end interface
