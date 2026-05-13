<?php

/**
 * Procest Hierarchical Routing Strategy
 *
 * Tries each `roleType` in priority order and returns the first non-empty set
 * of participants. Lets organisations fall back to a senior or department
 * head when the preferred role is currently unassigned.
 *
 * @category Service
 * @package  OCA\Procest\Service\Routing\Strategy
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

namespace OCA\Procest\Service\Routing\Strategy;

use OCA\Procest\Service\Routing\RoutingStrategyInterface;

/**
 * Hierarchical fall-through strategy.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class HierarchicalStrategy implements RoutingStrategyInterface
{
    /**
     * {@inheritDoc}
     *
     * @return string The strategy name.
     */
    public function name(): string
    {
        return 'hierarchical';
    }//end name()

    /**
     * Try each role type in `roleTypes` order; return the first non-empty
     * participant set. When all listed roles are empty, fall through to the
     * optional `fallback` roleType. Returns an empty array when nothing matches.
     *
     * @param array<string, mixed>             $rule  The routing rule
     * @param array<string, mixed>             $case  The case object
     * @param array<int, array<string, mixed>> $roles Roles bound to the case
     *
     * @return array<int, string>
     */
    public function resolve(array $rule, array $case, array $roles): array
    {
        $rawTypes = $rule['roleTypes'] ?? [];
        if (is_array($rawTypes) === false) {
            $rawTypes = [];
        }

        $priorities = [];
        foreach ($rawTypes as $type) {
            $value = (string) $type;
            if ($value !== '') {
                $priorities[] = $value;
            }
        }

        $fallback = (string) ($rule['fallback'] ?? '');
        if ($fallback !== '') {
            $priorities[] = $fallback;
        }

        foreach ($priorities as $type) {
            $matches = $this->matchesFor(roles: $roles, type: $type);
            if ($matches !== []) {
                return $matches;
            }
        }

        return [];
    }//end resolve()

    /**
     * Collect participants for a specific role type.
     *
     * @param array<int, array<string, mixed>> $roles The case roles
     * @param string                           $type  The role type UUID
     *
     * @return array<int, string>
     */
    private function matchesFor(array $roles, string $type): array
    {
        $matches = [];
        foreach ($roles as $role) {
            if ((string) ($role['roleType'] ?? '') !== $type) {
                continue;
            }

            $participant = (string) ($role['participant'] ?? '');
            if ($participant === '') {
                continue;
            }

            $matches[] = $participant;
        }

        return $matches;
    }//end matchesFor()
}//end class
