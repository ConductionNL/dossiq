<?php

/**
 * Procest Transition Authorization Stamper.
 *
 * Freezes role routing into OR-enforceable group authorization at publish
 * time. Split out of WorkflowDefinitionService so that service keeps only the
 * lifecycle write, while the one moment role names become literal Nextcloud
 * group ids lives here.
 *
 * Publishing is the one moment a definition becomes immutable, so the group
 * ids resolved here back the OR-enforced "only group X may perform this
 * transition" rule (OR PR #153 declarative gate format, ADR-022) for the
 * lifetime of that version. Any stale `authorization` key is dropped first,
 * so a transition whose role is unmapped (`roleType.ncGroupId` null) reverts
 * to open access for all authenticated users — the pre-migration default —
 * rather than keeping a group resolved under a previous mapping.
 *
 * @category Service
 * @package  OCA\Procest\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Workflow;

use OCA\Procest\Service\WorkflowStepAuthorizationResolver;

/**
 * Stamps resolved NC group ids onto a definition's transitions at publish time.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class TransitionAuthorizationStamper
{
    /**
     * Constructor.
     *
     * @param WorkflowStepAuthorizationResolver $authResolver Resolves step/transition
     *                                                        roles to NC group ids.
     *
     * @return void
     */
    public function __construct(
        private readonly WorkflowStepAuthorizationResolver $authResolver,
    ) {
    }//end __construct()

    /**
     * Resolve role routing to OR-enforceable group authorization for every
     * transition of a definition.
     *
     * @param array<int, mixed> $transitions The definition's decoded transitions.
     *
     * @return array<int, mixed>|null The enriched transitions, or null when the
     *                                definition declares none.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function stamp(array $transitions): ?array
    {
        if ($transitions === []) {
            return null;
        }

        $authored = [];
        foreach ($transitions as $transition) {
            if (is_array($transition) === false) {
                $authored[] = $transition;
                continue;
            }

            // Drop any stale authorization first so an unmapped role reverts
            // to open access rather than keeping a group resolved under a
            // previous mapping; re-stamp only when a group id resolves.
            unset($transition['authorization']);
            $groupIds = $this->authResolver->resolveGroupIds(entry: $transition);
            if ($groupIds !== []) {
                $transition['authorization'] = array_values($groupIds);
            }

            $authored[] = $transition;
        }//end foreach

        return $authored;
    }//end stamp()
}//end class
