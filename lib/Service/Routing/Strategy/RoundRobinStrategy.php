<?php

/**
 * Procest Round-Robin Routing Strategy
 *
 * Rotates across the participants of `roleType` for the case's caseType. The
 * cursor is persisted in IAppConfig under
 * `routing.rr.<caseTypeUuid>.<roleTypeUuid>` so rotation is stable across
 * worker processes and PHP-FPM restarts.
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

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Routing\RoutingStrategyInterface;
use OCP\IAppConfig;

/**
 * Round-robin strategy.
 *
 * @spec openspec/changes/role-based-step-routing/tasks.md#T03
 */
class RoundRobinStrategy implements RoutingStrategyInterface
{
    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig Persists the per-(caseType, roleType) cursor
     */
    public function __construct(private readonly IAppConfig $appConfig)
    {
    }//end __construct()

    /**
     * {@inheritDoc}
     *
     * @return string The strategy name.

     * @spec openspec/specs/role-based-step-routing/spec.md
     */
    public function name(): string
    {
        return 'round-robin';
    }//end name()

    /**
     * Pick the next participant in rotation; advance and persist the cursor.
     *
     * Returns a single-element array. When no participants match the rule's
     * `roleType` for the case, returns an empty array.
     *
     * @param array<string, mixed>             $rule  The routing rule
     * @param array<string, mixed>             $case  The case object
     * @param array<int, array<string, mixed>> $roles Roles bound to the case
     *
     * @return array<int, string>

     * @spec openspec/specs/role-based-step-routing/spec.md
     */
    public function resolve(array $rule, array $case, array $roles): array
    {
        $target = (string) ($rule['roleType'] ?? '');
        if ($target === '') {
            return [];
        }

        $participants = [];
        foreach ($roles as $role) {
            if ((string) ($role['roleType'] ?? '') !== $target) {
                continue;
            }

            $participant = (string) ($role['participant'] ?? '');
            if ($participant !== '') {
                $participants[] = $participant;
            }
        }

        $participants = array_values(array_unique($participants));
        $count        = count($participants);
        if ($count === 0) {
            return [];
        }

        $caseType = (string) ($case['caseType'] ?? '');
        $key      = sprintf('routing.rr.%s.%s', $caseType, $target);
        $cursor   = (int) $this->appConfig->getValueInt(
            Application::APP_ID,
            $key,
            0,
        );

        $pick = $participants[$cursor % $count];
        $this->appConfig->setValueInt(
            Application::APP_ID,
            $key,
            (($cursor + 1) % max($count, 1)),
        );

        return [$pick];
    }//end resolve()
}//end class
