<?php

/**
 * Procest Single-Role Routing Strategy
 *
 * Returns all participants currently bound to the rule's `roleType` on the
 * case in case-role creation order. Used as the default normalised form of
 * the legacy `WorkflowStep.assigneeRole` field.
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
 * Single-role strategy.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class SingleRoleStrategy implements RoutingStrategyInterface
{
    /**
     * {@inheritDoc}
     *
     * @return string The strategy name.
     */
    public function name(): string
    {
        return 'single-role';
    }//end name()

    /**
     * Return all participants whose role binding matches `rule.roleType`.
     *
     * Sorts case-role objects by their creation timestamp (`created` /
     * `@self.created`) so callers see a stable, predictable order. Missing
     * timestamps degrade gracefully to the input order.
     *
     * @param array<string, mixed>             $rule  The routing rule
     * @param array<string, mixed>             $case  The case object
     * @param array<int, array<string, mixed>> $roles Roles bound to the case
     *
     * @return array<int, string>
     */
    public function resolve(array $rule, array $case, array $roles): array
    {
        $target = (string) ($rule['roleType'] ?? '');
        if ($target === '') {
            return [];
        }

        $matches = [];
        foreach ($roles as $role) {
            if ((string) ($role['roleType'] ?? '') !== $target) {
                continue;
            }

            $participant = (string) ($role['participant'] ?? '');
            if ($participant === '') {
                continue;
            }

            $matches[] = [
                'participant' => $participant,
                'sortKey'     => (string) (
                    $role['created'] ?? ($role['@self']['created'] ?? '')
                ),
            ];
        }

        usort(
            $matches,
            static function (array $left, array $right): int {
                return strcmp((string) $left['sortKey'], (string) $right['sortKey']);
            },
        );

        return array_values(
                array_map(
            static fn (array $match): string => (string) $match['participant'],
            $matches,
        )
                );
    }//end resolve()
}//end class
