<?php

/**
 * Procest Least-Loaded Routing Strategy
 *
 * Picks the participant currently holding the lowest count of open tasks.
 * Open-task counts are taken from the case's `openTaskCountsByParticipant`
 * map when supplied by the caller (RoleResolverService precomputes it once
 * per resolve pass to avoid N+1 queries).
 *
 * @category Service
 * @package  OCA\Procest\Service\Routing\Strategy
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Procest\Service\Routing\Strategy;

use OCA\Procest\Service\Routing\RoutingStrategyInterface;

/**
 * Least-loaded strategy.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class LeastLoadedStrategy implements RoutingStrategyInterface
{
    /**
     * {@inheritDoc}
     *
     * @return string The strategy name.
     */
    /** @spec openspec/specs/role-based-step-routing/spec.md */
    public function name(): string
    {
        return 'least-loaded';
    }//end name()

    /**
     * Return the participant with the lowest count of open tasks.
     *
     * Reads counts from `$case['openTaskCountsByParticipant']` (string =>
     * int). Ties are broken by participant order (first match wins).
     * Returns an empty array when no participants match the rule.
     *
     * @param array<string, mixed>             $rule  The routing rule
     * @param array<string, mixed>             $case  The case object (must include `openTaskCountsByParticipant`)
     * @param array<int, array<string, mixed>> $roles Roles bound to the case
     *
     * @return array<int, string>
     */
    /** @spec openspec/specs/role-based-step-routing/spec.md */
    public function resolve(array $rule, array $case, array $roles): array
    {
        $target = (string) ($rule['roleType'] ?? '');
        if ($target === '') {
            return [];
        }

        $counts = [];
        $raw    = $case['openTaskCountsByParticipant'] ?? [];
        if (is_array($raw) === true) {
            foreach ($raw as $key => $value) {
                $counts[(string) $key] = (int) $value;
            }
        }

        $bestParticipant = null;
        $bestCount       = null;
        foreach ($roles as $role) {
            if ((string) ($role['roleType'] ?? '') !== $target) {
                continue;
            }

            $participant = (string) ($role['participant'] ?? '');
            if ($participant === '') {
                continue;
            }

            $count = $counts[$participant] ?? 0;
            if ($bestCount === null || $count < $bestCount) {
                $bestParticipant = $participant;
                $bestCount       = $count;
            }
        }

        if ($bestParticipant === null) {
            return [];
        }

        return [$bestParticipant];
    }//end resolve()
}//end class
