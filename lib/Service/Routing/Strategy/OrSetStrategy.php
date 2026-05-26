<?php

/**
 * Procest OR-Set Routing Strategy
 *
 * Returns the union of participants whose role binding matches any of the
 * rule's `roleTypes`. Used to normalise the legacy
 * `StatusTransition.allowedRoles` array — any of the listed roles may act.
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
 * OR-set strategy.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class OrSetStrategy implements RoutingStrategyInterface
{
    /**
     * {@inheritDoc}
     *
     * @return string The strategy name.
     */
    /** @spec openspec/specs/role-based-step-routing/spec.md */
    public function name(): string
    {
        return 'or-set';
    }//end name()

    /**
     * Union over `roleTypes`. Duplicate participants are collapsed; the order
     * follows first-match across the supplied role types.
     *
     * @param array<string, mixed>             $rule  The routing rule
     * @param array<string, mixed>             $case  The case object
     * @param array<int, array<string, mixed>> $roles Roles bound to the case
     *
     * @return array<int, string>
     */
    /** @spec openspec/specs/role-based-step-routing/spec.md */
    public function resolve(array $rule, array $case, array $roles): array
    {
        $rawTypes = $rule['roleTypes'] ?? [];
        if (is_array($rawTypes) === false) {
            return [];
        }

        $allowed = [];
        foreach ($rawTypes as $type) {
            $value = (string) $type;
            if ($value !== '') {
                $allowed[$value] = true;
            }
        }

        if ($allowed === []) {
            return [];
        }

        $seen   = [];
        $result = [];
        foreach ($roles as $role) {
            $type = (string) ($role['roleType'] ?? '');
            if (isset($allowed[$type]) === false) {
                continue;
            }

            $participant = (string) ($role['participant'] ?? '');
            if ($participant === '' || isset($seen[$participant]) === true) {
                continue;
            }

            $seen[$participant] = true;
            $result[]           = $participant;
        }

        return $result;
    }//end resolve()
}//end class
