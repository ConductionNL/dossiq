<?php

/**
 * Procest CMMN Plan-Item Transition Table.
 *
 * The single source of truth for which plan-item state transitions are
 * legal, per the CMMN 1.1 state model documented in
 * `openspec/changes/cmmn-adaptive-case/design.md` §3. Pure and stateless — no
 * I/O, no case data, just the transition graph plus the guard that rejects
 * anything not in it. Injected as a collaborator (see `CaseModelEngine`)
 * rather than called statically.
 *
 * @category Service
 * @package  OCA\Procest\Service\Cmmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Cmmn;

/**
 * Legal plan-item states and the transition table between them.
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
 */
final class PlanItemTransitions
{

    public const STATE_AVAILABLE  = 'available';
    public const STATE_ENABLED    = 'enabled';
    public const STATE_ACTIVE     = 'active';
    public const STATE_COMPLETED  = 'completed';
    public const STATE_TERMINATED = 'terminated';
    public const STATE_DISABLED   = 'disabled';

    public const TYPE_STAGE      = 'stage';
    public const TYPE_HUMAN_TASK = 'humanTask';
    public const TYPE_MILESTONE  = 'milestone';

    /**
     * Exhaustive legal-transition table, keyed by plan-item type, then by
     * `"{fromState}->{toState}"`. Presence in the table = legal. Anything
     * absent (including a same-state "transition") is illegal.
     *
     * @var array<string, array<string, bool>>
     */
    private const TABLE = [
        self::TYPE_STAGE      => [
            self::STATE_AVAILABLE.'->'.self::STATE_ENABLED    => true,
            self::STATE_AVAILABLE.'->'.self::STATE_DISABLED   => true,
            self::STATE_AVAILABLE.'->'.self::STATE_TERMINATED => true,
            self::STATE_ENABLED.'->'.self::STATE_ACTIVE       => true,
            self::STATE_ENABLED.'->'.self::STATE_TERMINATED   => true,
            self::STATE_ENABLED.'->'.self::STATE_DISABLED     => true,
            self::STATE_ACTIVE.'->'.self::STATE_COMPLETED     => true,
            self::STATE_ACTIVE.'->'.self::STATE_TERMINATED    => true,
        ],
        self::TYPE_HUMAN_TASK => [
            self::STATE_AVAILABLE.'->'.self::STATE_ENABLED    => true,
            self::STATE_AVAILABLE.'->'.self::STATE_DISABLED   => true,
            self::STATE_AVAILABLE.'->'.self::STATE_TERMINATED => true,
            self::STATE_ENABLED.'->'.self::STATE_ACTIVE       => true,
            self::STATE_ENABLED.'->'.self::STATE_TERMINATED   => true,
            self::STATE_ENABLED.'->'.self::STATE_DISABLED     => true,
            self::STATE_ACTIVE.'->'.self::STATE_COMPLETED     => true,
            self::STATE_ACTIVE.'->'.self::STATE_TERMINATED    => true,
        ],
        self::TYPE_MILESTONE  => [
            self::STATE_AVAILABLE.'->'.self::STATE_COMPLETED  => true,
            self::STATE_AVAILABLE.'->'.self::STATE_TERMINATED => true,
        ],
    ];

    /**
     * Terminal states — no outgoing transition exists for any of them in
     * {@see TABLE}; kept as an explicit set only so callers can cheaply ask
     * "is this item done" without scanning the table.
     *
     * @var array<string, bool>
     */
    private const TERMINAL_STATES = [
        self::STATE_COMPLETED  => true,
        self::STATE_TERMINATED => true,
        self::STATE_DISABLED   => true,
    ];

    /**
     * The initial state every plan item starts in.
     *
     * @return string
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
     */
    public function initialState(): string
    {
        return self::STATE_AVAILABLE;
    }//end initialState()

    /**
     * Whether a state is terminal (no legal outgoing transition).
     *
     * @param string $state The state to check.
     *
     * @return bool
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
     */
    public function isTerminal(string $state): bool
    {
        return isset(self::TERMINAL_STATES[$state]);
    }//end isTerminal()

    /**
     * Whether a transition is legal for the given plan-item type.
     *
     * @param string $itemType  `stage`|`humanTask`|`milestone`.
     * @param string $fromState Current state.
     * @param string $toState   Requested target state.
     *
     * @return bool
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
     */
    public function isLegal(string $itemType, string $fromState, string $toState): bool
    {
        $table = self::TABLE[$itemType] ?? [];
        return isset($table[$fromState.'->'.$toState]);
    }//end isLegal()

    /**
     * Assert a transition is legal, throwing when it is not.
     *
     * @param string $itemId    Plan-item id (for the exception context).
     * @param string $itemType  `stage`|`humanTask`|`milestone`.
     * @param string $fromState Current state.
     * @param string $toState   Requested target state.
     *
     * @return void
     *
     * @throws IllegalPlanItemTransitionException When the transition is not in the table.
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
     */
    public function assertLegal(string $itemId, string $itemType, string $fromState, string $toState): void
    {
        if ($this->isLegal(itemType: $itemType, fromState: $fromState, toState: $toState) === false) {
            throw new IllegalPlanItemTransitionException(
                itemId: $itemId,
                itemType: $itemType,
                fromState: $fromState,
                toState: $toState,
            );
        }
    }//end assertLegal()
}//end class
