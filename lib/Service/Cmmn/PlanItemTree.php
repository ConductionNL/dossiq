<?php

/**
 * Procest CMMN plan-item tree queries.
 *
 * Pure structural questions about the plan-item hierarchy, asked by both the
 * cascade evaluator and the engine's REST-facing views: is an item's
 * containing stage active, and has every mandatory child of a stage reached a
 * terminal state?
 *
 * Split out of CaseModelEngine because these are read-only queries over
 * (itemsById, state) with no side effects at all — no transition, no event, no
 * persistence — which makes them the one part of the engine that can be
 * reasoned about, and reused, without the state machine in scope.
 *
 * Two conventions here are load-bearing and deliberate:
 *   - a ROOT item (no parentId) is always "parent-active", so top-level items
 *     are never gated on a stage that does not exist;
 *   - a stage with NO mandatory children never auto-completes from
 *     {@see stageMandatoryChildrenAllTerminal()}. "All zero of zero children
 *     are terminal" is vacuously true, and returning true would complete such
 *     a stage the instant it activated.
 *
 * @category Service
 * @package  OCA\Procest\Service\Cmmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Cmmn;

/**
 * Read-only structural queries over the CMMN plan-item hierarchy.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 */
class PlanItemTree
{
    /**
     * Constructor.
     *
     * @param PlanItemTransitions $transitions Legal plan-item transition table.
     */
    public function __construct(
        private readonly PlanItemTransitions $transitions,
    ) {
    }//end __construct()

    /**
     * Whether an item's containing stage is active (root items — no parent —
     * are always considered active).
     *
     * @param array<string, mixed> $item  The plan item.
     * @param array<string, mixed> $state Runtime state.
     *
     * @return bool
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md
     */
    public function isParentActive(array $item, array $state): bool
    {
        $parentId = $item['parentId'] ?? null;
        if ($parentId === null || $parentId === '') {
            return true;
        }

        return ($state['planItemStates'][$parentId] ?? $this->transitions->initialState()) === PlanItemTransitions::STATE_ACTIVE;
    }//end isParentActive()

    /**
     * Whether every mandatory (non-discretionary) direct child of a stage is
     * in a terminal state. A stage with no mandatory children never
     * auto-completes from this rule (it stays active until an exit sentry
     * fires or is otherwise driven, since "all zero of zero children are
     * terminal" would trivially auto-complete it on activation).
     *
     * @param string                              $stageId   Stage plan-item id.
     * @param array<string, array<string, mixed>> $itemsById Plan items by id.
     * @param array<string, mixed>                $state     Runtime state.
     *
     * @return bool
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md
     */
    public function stageMandatoryChildrenAllTerminal(string $stageId, array $itemsById, array $state): bool
    {
        $mandatoryFound = false;
        foreach ($itemsById as $id => $item) {
            if (($item['parentId'] ?? null) !== $stageId) {
                continue;
            }

            if (($item['discretionary'] ?? false) === true) {
                continue;
            }

            $mandatoryFound = true;
            $childState     = $state['planItemStates'][$id] ?? $this->transitions->initialState();
            if ($this->transitions->isTerminal(state: $childState) === false) {
                return false;
            }
        }

        return $mandatoryFound;
    }//end stageMandatoryChildrenAllTerminal()
}//end class
