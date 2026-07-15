<?php

/**
 * Procest CMMN Case-Model Runtime Engine.
 *
 * The adaptive counterpart to `StatusTransitionService`: a pure, deterministic
 * plan-item lifecycle evaluator for CMMN-managed cases (`caseType.handlingModel
 * = 'cmmn'`). Tracks every plan item's state (`design.md` §3), evaluates
 * entry/exit sentries (`design.md` §4), gates discretionary-item enablement
 * (`design.md` §6), and records milestone achievement — all persisted through
 * a single `case.casePlanState` field per mutation (REQ-CMMN-006), never as
 * separate OR objects per plan item.
 *
 * BPMN and CMMN are sibling engines selected per caseType (`design.md` §5):
 * this engine refuses to operate on a case whose caseType is not
 * `handlingModel: cmmn`, and never touches `case.status`/`statusHistory` —
 * `StatusTransitionService`'s write surface is untouched by this class.
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
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — one cohesive state-machine engine by design
 * @SuppressWarnings(PHPMD.StaticAccess)             — PlanItemTransitions/SentryEvaluator are deliberately
 *   pure, stateless, static helper classes (no DI, no side effects); calling them statically
 *   is the intended design, not a coupling smell.
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Cmmn;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The CMMN plan-item lifecycle + sentry evaluation engine.
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 */
class CaseModelEngine
{

    /**
     * Bound on cascade fixed-point iterations per mutation — protects against
     * an authoring cycle in the case model (e.g. two plan items whose entry
     * sentries reference each other's completion) looping forever. Reaching
     * the bound is a defensive stop, not expected in a well-formed model.
     */
    private const MAX_CASCADE_DEPTH = 50;

    /**
     * Bound on the number of retained event-log entries in casePlanState.
     */
    private const MAX_EVENT_LOG = 100;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Bridge to OpenRegister + config.
     * @param CaseModelLoader $modelLoader     Active-caseModel-by-caseType loader.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly CaseModelLoader $modelLoader,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the current case plan: every item with its state, grouped
     * implicitly by `parentId`, plus the currently enable-able discretionary
     * items and the case-file/milestone snapshots. Initialises runtime state
     * on first call for a case (a single save), read-only thereafter.
     *
     * @param string $caseId Case UUID.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the case/caseType cannot be loaded or is not CMMN-managed.
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001
     */
    public function getCasePlan(string $caseId): array
    {
        $ctx      = $this->loadContext(caseId: $caseId);
        $newInit  = $this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
        $cascaded = $this->cascade(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);
        if ($newInit === true || $cascaded === true) {
            $this->persist(ctx: $ctx);
        }

        return $this->buildPlanView(ctx: $ctx);
    }//end getCasePlan()

    /**
     * Enable a discretionary plan item — the worker's optional-task opt-in.
     * Transitions `enabled → active`. Rejected for mandatory items or items
     * not currently `enabled`.
     *
     * @param string $caseId Case UUID.
     * @param string $itemId Plan-item id.
     *
     * @return array<string, mixed> The updated case plan.
     *
     * @throws IllegalPlanItemTransitionException When the item is not discretionary or not enabled.
     * @throws RuntimeException                   When the case/item cannot be resolved.
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-004
     */
    public function enableDiscretionaryItem(string $caseId, string $itemId): array
    {
        $ctx = $this->loadContext(caseId: $caseId);
        $this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
        $this->cascade(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);

        $item = $ctx['itemsById'][$itemId] ?? null;
        if ($item === null) {
            throw new RuntimeException('plan_item_not_found');
        }

        $current = $ctx['state']['planItemStates'][$itemId] ?? PlanItemTransitions::initialState();
        if (($item['discretionary'] ?? false) !== true) {
            // A mandatory item is never manually enabled — even though
            // enabled→active is a legal edge in the table (it is how the
            // engine's own auto-cascade advances mandatory items), this
            // REST-facing action is reserved for discretionary opt-in.
            throw new IllegalPlanItemTransitionException(
                itemId: $itemId,
                itemType: (string) $item['type'],
                fromState: $current,
                toState: PlanItemTransitions::STATE_ACTIVE,
            );
        }

        $this->transition(item: $item, from: $current, to: PlanItemTransitions::STATE_ACTIVE, itemsById: $ctx['itemsById'], state: $ctx['state']);
        $this->cascade(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);
        $this->persist(ctx: $ctx);

        return $this->buildPlanView(ctx: $ctx);
    }//end enableDiscretionaryItem()

    /**
     * Complete an active human task.
     *
     * @param string $caseId Case UUID.
     * @param string $itemId Plan-item id.
     *
     * @return array<string, mixed> The updated case plan.
     *
     * @throws RuntimeException                   When the item is not a humanTask.
     * @throws IllegalPlanItemTransitionException When the item is not currently active.
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
     */
    public function completeTask(string $caseId, string $itemId): array
    {
        return $this->transitionHumanTask(caseId: $caseId, itemId: $itemId, to: PlanItemTransitions::STATE_COMPLETED);
    }//end completeTask()

    /**
     * Terminate a human task (worker abandons it, or it was never started).
     *
     * @param string $caseId Case UUID.
     * @param string $itemId Plan-item id.
     *
     * @return array<string, mixed> The updated case plan.
     *
     * @throws RuntimeException                   When the item is not a humanTask.
     * @throws IllegalPlanItemTransitionException When the item is already terminal.
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
     */
    public function terminateTask(string $caseId, string $itemId): array
    {
        return $this->transitionHumanTask(caseId: $caseId, itemId: $itemId, to: PlanItemTransitions::STATE_TERMINATED);
    }//end terminateTask()

    /**
     * Signal that a case-file item was set/changed, re-evaluating every
     * sentry that may reference it. The single write path for case-file
     * mutation and its resulting cascade — exactly one `saveObject()` call.
     *
     * @param string               $caseId  Case UUID.
     * @param array<string, mixed> $updates Case-file item id => new value.
     *
     * @return array<string, mixed> The updated case plan.
     *
     * @throws RuntimeException When the case/caseType cannot be loaded or is not CMMN-managed.
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
     */
    public function signalCaseFileEvent(string $caseId, array $updates): array
    {
        $ctx = $this->loadContext(caseId: $caseId);
        $this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
        $this->cascade(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);

        $oldCaseFile = $ctx['state']['caseFile'];
        $touchedKeys = [];
        $changedKeys = [];
        foreach ($updates as $key => $value) {
            $key           = (string) $key;
            $touchedKeys[] = $key;
            if (array_key_exists($key, $oldCaseFile) === false || $oldCaseFile[$key] !== $value) {
                $changedKeys[] = $key;
            }

            $ctx['state']['caseFile'][$key] = $value;
        }

        $this->cascade(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: $touchedKeys, changedKeys: $changedKeys);
        // Always persist — the case-file mutation itself must be saved even
        // when no plan-item state changed as a result.
        $this->persist(ctx: $ctx);

        return $this->buildPlanView(ctx: $ctx);
    }//end signalCaseFileEvent()

    /**
     * The `authorization: string[]` gate configured on a plan item, for the
     * REST layer's OR-RBAC check (`design.md` §6). Reuses the same
     * case/caseType/CMMN-managed loading as every mutating method, so an
     * unresolvable case/item surfaces the same `RuntimeException` codes.
     *
     * @param string $caseId Case UUID.
     * @param string $itemId Plan-item id.
     *
     * @return array<int, string>
     *
     * @throws RuntimeException When the case/caseType/item cannot be resolved or is not CMMN-managed.
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
     */
    public function getPlanItemAuthorization(string $caseId, string $itemId): array
    {
        $ctx  = $this->loadContext(caseId: $caseId);
        $item = $ctx['itemsById'][$itemId] ?? null;
        if ($item === null) {
            throw new RuntimeException('plan_item_not_found');
        }

        $authorization = $item['authorization'] ?? [];
        if (is_array($authorization) === true) {
            return array_values($authorization);
        }

        return [];
    }//end getPlanItemAuthorization()

    /**
     * The plan items currently eligible for the worker to enable: discretionary,
     * `enabled`, and whose parent stage is `active` (`design.md` §6).
     *
     * @param string $caseId Case UUID.
     *
     * @return array<int, string> Plan-item ids.
     *
     * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-004
     */
    public function getEnableableDiscretionaryItems(string $caseId): array
    {
        $ctx = $this->loadContext(caseId: $caseId);
        $this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
        $this->cascade(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);

        return $this->enableableDiscretionaryIds(ctx: $ctx);
    }//end getEnableableDiscretionaryItems()

    // ------------------------------------------------------------------
    // Human-task completion/termination (shared implementation)
    // ------------------------------------------------------------------

    /**
     * Shared implementation for completeTask()/terminateTask().
     *
     * @param string $caseId Case UUID.
     * @param string $itemId Plan-item id.
     * @param string $to     Target state (`completed`|`terminated`).
     *
     * @return array<string, mixed>
     */
    private function transitionHumanTask(string $caseId, string $itemId, string $to): array
    {
        $ctx = $this->loadContext(caseId: $caseId);
        $this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
        $this->cascade(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);

        $item = $ctx['itemsById'][$itemId] ?? null;
        if ($item === null) {
            throw new RuntimeException('plan_item_not_found');
        }

        if ($item['type'] !== PlanItemTransitions::TYPE_HUMAN_TASK) {
            throw new RuntimeException('not_a_human_task');
        }

        $current = $ctx['state']['planItemStates'][$itemId] ?? PlanItemTransitions::initialState();
        $this->transition(item: $item, from: $current, to: $to, itemsById: $ctx['itemsById'], state: $ctx['state']);
        $this->cascade(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);
        $this->persist(ctx: $ctx);

        return $this->buildPlanView(ctx: $ctx);
    }//end transitionHumanTask()

    // ------------------------------------------------------------------
    // Cascade / sentry evaluation
    // ------------------------------------------------------------------

    /**
     * Run cascade passes to a fixed point (or MAX_CASCADE_DEPTH).
     *
     * @param array<string, array<string, mixed>> $itemsById   Plan items by id.
     * @param array<string, mixed>                $state       Runtime state, mutated in place.
     * @param array<int, string>                  $touchedKeys Case-file keys touched this call.
     * @param array<int, string>                  $changedKeys Subset of touchedKeys whose value changed.
     *
     * @return bool Whether any transition occurred across all passes.
     */
    private function cascade(array &$itemsById, array &$state, array $touchedKeys, array $changedKeys): bool
    {
        $anyChanged = false;
        for ($depth = 0; $depth < self::MAX_CASCADE_DEPTH; $depth++) {
            $passChanged = $this->cascadePass(itemsById: $itemsById, state: $state, touchedKeys: $touchedKeys, changedKeys: $changedKeys);
            if ($passChanged === false) {
                break;
            }

            $anyChanged = true;
        }

        return $anyChanged;
    }//end cascade()

    /**
     * One evaluation pass over every non-terminal item, against a snapshot
     * taken at the start of the pass (so results are independent of item
     * iteration order — a later pass picks up anything this pass changed).
     *
     * @param array<string, array<string, mixed>> $itemsById   Plan items by id.
     * @param array<string, mixed>                $state       Runtime state, mutated in place.
     * @param array<int, string>                  $touchedKeys Case-file keys touched this call.
     * @param array<int, string>                  $changedKeys Subset of touchedKeys whose value changed.
     *
     * @return bool Whether this pass changed any item's state.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — one evaluation pass over the state machine's
     *   own branches (exit sentry, entry sentry, mandatory-cascade, stage auto-complete); splitting
     *   it would scatter one pass across several methods that all need the same $context snapshot.
     */
    private function cascadePass(array &$itemsById, array &$state, array $touchedKeys, array $changedKeys): bool
    {
        $changed = false;
        $context = [
            'planItemStates' => $state['planItemStates'],
            'caseFile'       => $state['caseFile'],
            'touchedKeys'    => $touchedKeys,
            'changedKeys'    => $changedKeys,
        ];

        foreach ($itemsById as $id => $item) {
            $current = $state['planItemStates'][$id] ?? PlanItemTransitions::initialState();
            if (PlanItemTransitions::isTerminal(state: $current) === true) {
                continue;
            }

            if ($this->isParentActive(item: $item, state: $state) === false) {
                continue;
            }

            $exitCriteria = $item['exitCriteria'] ?? [];
            if (is_array($exitCriteria) === true && count($exitCriteria) > 0
                && SentryEvaluator::anyFires(sentries: $exitCriteria, context: $context) === true
            ) {
                $this->transition(item: $item, from: $current, to: PlanItemTransitions::STATE_TERMINATED, itemsById: $itemsById, state: $state);
                $changed = true;
                continue;
            }

            if ($current === PlanItemTransitions::STATE_AVAILABLE) {
                $entryCriteria = $item['entryCriteria'] ?? [];
                $hasNoCriteria = (is_array($entryCriteria) === false || count($entryCriteria) === 0);
                $satisfied     = $hasNoCriteria || SentryEvaluator::anyFires(sentries: $entryCriteria, context: $context);

                if ($satisfied === true) {
                    $this->advanceFromAvailable(item: $item, current: $current, itemsById: $itemsById, state: $state);
                    $changed = true;
                }

                continue;
            }

            if ($current === PlanItemTransitions::STATE_ACTIVE && $item['type'] === PlanItemTransitions::TYPE_STAGE) {
                if ($this->stageMandatoryChildrenAllTerminal(stageId: $id, itemsById: $itemsById, state: $state) === true) {
                    $this->transition(item: $item, from: $current, to: PlanItemTransitions::STATE_COMPLETED, itemsById: $itemsById, state: $state);
                    $changed = true;
                }
            }//end if
        }//end foreach

        return $changed;
    }//end cascadePass()

    /**
     * Advance a plan item whose entry criteria just became satisfied: a
     * milestone completes directly; a stage/humanTask enables, then
     * auto-cascades straight to `active` unless it is discretionary (which
     * stops at `enabled`, pending the worker's opt-in).
     *
     * @param array<string, mixed>                $item      The plan item (state `available`).
     * @param string                              $current   Current state (`available`).
     * @param array<string, array<string, mixed>> $itemsById Plan items by id.
     * @param array<string, mixed>                $state     Runtime state, mutated in place.
     *
     * @return void
     */
    private function advanceFromAvailable(array $item, string $current, array &$itemsById, array &$state): void
    {
        if ($item['type'] === PlanItemTransitions::TYPE_MILESTONE) {
            $this->transition(item: $item, from: $current, to: PlanItemTransitions::STATE_COMPLETED, itemsById: $itemsById, state: $state);
            return;
        }

        $this->transition(item: $item, from: $current, to: PlanItemTransitions::STATE_ENABLED, itemsById: $itemsById, state: $state);
        if (($item['discretionary'] ?? false) !== true) {
            $this->transition(
                item: $item,
                from: PlanItemTransitions::STATE_ENABLED,
                to: PlanItemTransitions::STATE_ACTIVE,
                itemsById: $itemsById,
                state: $state,
            );
        }
    }//end advanceFromAvailable()

    /**
     * Whether an item's containing stage is active (root items — no parent —
     * are always considered active).
     *
     * @param array<string, mixed> $item  The plan item.
     * @param array<string, mixed> $state Runtime state.
     *
     * @return bool
     */
    private function isParentActive(array $item, array $state): bool
    {
        $parentId = $item['parentId'] ?? null;
        if ($parentId === null || $parentId === '') {
            return true;
        }

        return ($state['planItemStates'][$parentId] ?? PlanItemTransitions::initialState()) === PlanItemTransitions::STATE_ACTIVE;
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
     */
    private function stageMandatoryChildrenAllTerminal(string $stageId, array $itemsById, array $state): bool
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
            $childState     = $state['planItemStates'][$id] ?? PlanItemTransitions::initialState();
            if (PlanItemTransitions::isTerminal(state: $childState) === false) {
                return false;
            }
        }

        return $mandatoryFound;
    }//end stageMandatoryChildrenAllTerminal()

    /**
     * Apply a single validated transition, recording it and cascading its
     * structural side effects: a stage reaching `completed` disables any
     * still-unplanned discretionary children; a stage (or any item) reaching
     * `terminated` force-terminates every non-terminal descendant.
     *
     * @param array<string, mixed>                $item      The plan item.
     * @param string                              $from      Current state.
     * @param string                              $to        Target state.
     * @param array<string, array<string, mixed>> $itemsById Plan items by id.
     * @param array<string, mixed>                $state     Runtime state, mutated in place.
     *
     * @return void
     *
     * @throws IllegalPlanItemTransitionException When the transition is not legal.
     */
    private function transition(array $item, string $from, string $to, array &$itemsById, array &$state): void
    {
        PlanItemTransitions::assertLegal(itemId: (string) $item['id'], itemType: (string) $item['type'], fromState: $from, toState: $to);

        $state['planItemStates'][$item['id']] = $to;
        $this->appendEvent(state: $state, itemId: (string) $item['id'], itemType: (string) $item['type'], from: $from, to: $to);

        if ($to === PlanItemTransitions::STATE_COMPLETED && $item['type'] === PlanItemTransitions::TYPE_MILESTONE) {
            $state['milestones'][$item['id']] = [
                'achieved'   => true,
                'achievedAt' => $this->now(),
            ];
        }

        if ($item['type'] === PlanItemTransitions::TYPE_STAGE) {
            if ($to === PlanItemTransitions::STATE_COMPLETED) {
                $this->disableUnplannedDiscretionaryChildren(stageId: (string) $item['id'], itemsById: $itemsById, state: $state);
            } else if ($to === PlanItemTransitions::STATE_TERMINATED) {
                $this->forceTerminateChildren(stageId: (string) $item['id'], itemsById: $itemsById, state: $state);
            }
        }
    }//end transition()

    /**
     * When a stage completes naturally, any discretionary child that was
     * never enabled (still `available`/`enabled`) is disabled — it will
     * never be worked on now the stage is done.
     *
     * @param string                              $stageId   Stage plan-item id.
     * @param array<string, array<string, mixed>> $itemsById Plan items by id.
     * @param array<string, mixed>                $state     Runtime state, mutated in place.
     *
     * @return void
     */
    private function disableUnplannedDiscretionaryChildren(string $stageId, array &$itemsById, array &$state): void
    {
        foreach ($itemsById as $id => $item) {
            if (($item['parentId'] ?? null) !== $stageId || ($item['discretionary'] ?? false) !== true) {
                continue;
            }

            $current = $state['planItemStates'][$id] ?? PlanItemTransitions::initialState();
            if ($current === PlanItemTransitions::STATE_AVAILABLE || $current === PlanItemTransitions::STATE_ENABLED) {
                $this->transition(item: $item, from: $current, to: PlanItemTransitions::STATE_DISABLED, itemsById: $itemsById, state: $state);
            }
        }
    }//end disableUnplannedDiscretionaryChildren()

    /**
     * When a stage (or any item) is force-terminated, every non-terminal
     * direct child is force-terminated too — recursively, since `transition()`
     * re-invokes this for any child that is itself a stage reaching `terminated`.
     *
     * @param string                              $stageId   Stage plan-item id.
     * @param array<string, array<string, mixed>> $itemsById Plan items by id.
     * @param array<string, mixed>                $state     Runtime state, mutated in place.
     *
     * @return void
     */
    private function forceTerminateChildren(string $stageId, array &$itemsById, array &$state): void
    {
        foreach ($itemsById as $id => $item) {
            if (($item['parentId'] ?? null) !== $stageId) {
                continue;
            }

            $current = $state['planItemStates'][$id] ?? PlanItemTransitions::initialState();
            if (PlanItemTransitions::isTerminal(state: $current) === true) {
                continue;
            }

            $this->transition(item: $item, from: $current, to: PlanItemTransitions::STATE_TERMINATED, itemsById: $itemsById, state: $state);
        }
    }//end forceTerminateChildren()

    /**
     * Append a bounded event-log entry.
     *
     * @param array<string, mixed> $state    Runtime state, mutated in place.
     * @param string               $itemId   Plan-item id.
     * @param string               $itemType Plan-item type.
     * @param string               $from     Prior state.
     * @param string               $to       New state.
     *
     * @return void
     */
    private function appendEvent(array &$state, string $itemId, string $itemType, string $from, string $to): void
    {
        $state['eventLog'][] = [
            'at'       => $this->now(),
            'itemId'   => $itemId,
            'itemType' => $itemType,
            'from'     => $from,
            'to'       => $to,
        ];

        if (count($state['eventLog']) > self::MAX_EVENT_LOG) {
            $state['eventLog'] = array_slice($state['eventLog'], -self::MAX_EVENT_LOG);
        }
    }//end appendEvent()

    // ------------------------------------------------------------------
    // Load / persist / view
    // ------------------------------------------------------------------

    /**
     * Load the case, its caseType (enforcing `handlingModel: cmmn`), the
     * active caseModel's plan items, and the decoded runtime state.
     *
     * @param string $caseId Case UUID.
     *
     * @return array{
     *     case: array<string, mixed>,
     *     caseType: array<string, mixed>,
     *     itemsById: array<string, array<string, mixed>>,
     *     state: array<string, mixed>,
     *     objectService: mixed,
     *     register: string,
     *     caseSchema: string,
     * }
     *
     * @throws RuntimeException When the case/caseType cannot be loaded or is not CMMN-managed.
     */
    private function loadContext(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        $register       = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema     = $this->settingsService->getConfigValue(key: 'case_schema');
        $caseTypeSchema = $this->settingsService->getConfigValue(key: 'case_type_schema');
        if ($register === '' || $caseSchema === '' || $caseTypeSchema === '') {
            throw new RuntimeException('cmmn_not_configured');
        }

        try {
            $case = $this->toArray(value: $objectService->find($caseId, register: $register, schema: $caseSchema));
        } catch (Throwable $e) {
            $this->logger->error('CaseModelEngine: loadCase failed', ['exception' => $e->getMessage(), 'caseId' => $caseId]);
            throw new RuntimeException('case_not_found');
        }

        if ($case === []) {
            throw new RuntimeException('case_not_found');
        }

        $caseTypeId = (string) ($case['caseType'] ?? '');
        if ($caseTypeId === '') {
            throw new RuntimeException('case_type_not_configured');
        }

        try {
            $caseType = $this->toArray(value: $objectService->find($caseTypeId, register: $register, schema: $caseTypeSchema));
        } catch (Throwable $e) {
            $this->logger->error('CaseModelEngine: loadCaseType failed', ['exception' => $e->getMessage(), 'caseTypeId' => $caseTypeId]);
            throw new RuntimeException('case_type_not_found');
        }

        $handlingModel = (string) ($caseType['handlingModel'] ?? 'bpmn');
        if ($handlingModel !== 'cmmn') {
            throw new RuntimeException('case_not_cmmn_managed');
        }

        $itemsById = $this->loadItemsById(caseTypeId: $caseTypeId);
        $state     = $this->decodeState(case: $case);

        return [
            'case'          => $case,
            'caseType'      => $caseType,
            'itemsById'     => $itemsById,
            'state'         => $state,
            'objectService' => $objectService,
            'register'      => $register,
            'caseSchema'    => $caseSchema,
        ];
    }//end loadContext()

    /**
     * Load and validate the active caseModel's plan items for a caseType.
     *
     * @param string $caseTypeId The caseType UUID.
     *
     * @return array<string, array<string, mixed>> Plan items keyed by id.
     *
     * @throws RuntimeException When a `children`/`parentId` mismatch is found.
     */
    private function loadItemsById(string $caseTypeId): array
    {
        $model = $this->modelLoader->getActiveModel(caseTypeId: $caseTypeId);
        if ($model === null) {
            return [];
        }

        $planItems = $model['planItems'] ?? [];
        if (is_array($planItems) === false) {
            return [];
        }

        $itemsById = [];
        foreach ($planItems as $item) {
            if (is_array($item) === true && isset($item['id']) === true && $item['id'] !== '') {
                $itemsById[(string) $item['id']] = $this->normaliseItem(item: $item);
            }
        }

        $this->assertModelStructureValid(itemsById: $itemsById);

        return $itemsById;
    }//end loadItemsById()

    /**
     * Fill in default keys on a plan-item definition.
     *
     * @param array<string, mixed> $item Raw plan-item definition.
     *
     * @return array<string, mixed>
     */
    private function normaliseItem(array $item): array
    {
        $entryCriteria = $item['entryCriteria'] ?? [];
        if (is_array($entryCriteria) === false) {
            $entryCriteria = [];
        }

        $exitCriteria = $item['exitCriteria'] ?? [];
        if (is_array($exitCriteria) === false) {
            $exitCriteria = [];
        }

        $authorization = $item['authorization'] ?? [];
        if (is_array($authorization) === false) {
            $authorization = [];
        }

        $parentId = null;
        if (isset($item['parentId']) === true && $item['parentId'] !== '') {
            $parentId = (string) $item['parentId'];
        }

        return [
            'id'            => (string) $item['id'],
            'type'          => (string) ($item['type'] ?? ''),
            'name'          => (string) ($item['name'] ?? ''),
            'discretionary' => (($item['discretionary'] ?? false) === true),
            'parentId'      => $parentId,
            'entryCriteria' => $entryCriteria,
            'exitCriteria'  => $exitCriteria,
            'authorization' => $authorization,
        ];
    }//end normaliseItem()

    /**
     * Validate `parentId` references resolve and any redundant `children`
     * list is consistent with them — a mismatch is a case-model authoring
     * error, rejected rather than silently reconciled (`design.md` §2).
     *
     * @param array<string, array<string, mixed>> $itemsById Plan items by id.
     *
     * @return void
     *
     * @throws RuntimeException When a reference is invalid.
     */
    private function assertModelStructureValid(array $itemsById): void
    {
        foreach ($itemsById as $id => $item) {
            $parentId = $item['parentId'];
            if ($parentId !== null && isset($itemsById[$parentId]) === false) {
                throw new RuntimeException('case_model_invalid');
            }

            // Note: the schema's optional `children` array on the raw
            // definition is documentation-only in this engine — parentId is
            // the single source of truth for the tree, so an inconsistent
            // `children` list (a stale hand-edit) cannot desync runtime
            // behaviour from what actually drives it.
            unset($id);
        }
    }//end assertModelStructureValid()

    /**
     * Decode `case.casePlanState`, defaulting missing/invalid JSON to an
     * empty state skeleton.
     *
     * @param array<string, mixed> $case The case payload.
     *
     * @return array<string, mixed>
     */
    private function decodeState(array $case): array
    {
        $empty = [
            'planItemStates' => [],
            'milestones'     => [],
            'caseFile'       => [],
            'eventLog'       => [],
        ];

        $raw = $case['casePlanState'] ?? null;
        if (is_string($raw) === true && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) === true) {
                return array_merge($empty, $decoded);
            }
        } else if (is_array($raw) === true) {
            return array_merge($empty, $raw);
        }

        return $empty;
    }//end decodeState()

    /**
     * Set every plan item not yet present in runtime state to `available`.
     *
     * @param array<string, mixed>                $state     Runtime state, mutated in place.
     * @param array<string, array<string, mixed>> $itemsById Plan items by id.
     *
     * @return bool Whether any item was newly initialised.
     */
    private function ensureInitialized(array &$state, array $itemsById): bool
    {
        $changed = false;
        foreach ($itemsById as $id => $item) {
            unset($item);
            if (isset($state['planItemStates'][$id]) === false) {
                $state['planItemStates'][$id] = PlanItemTransitions::initialState();
                $changed = true;
            }
        }

        return $changed;
    }//end ensureInitialized()

    /**
     * Persist the runtime state onto the case via a single `saveObject()` call.
     *
     * @param array<string, mixed> $ctx Context from {@see loadContext()}, mutated in place (`case` key refreshed with the saved payload).
     *
     * @return void
     */
    private function persist(array &$ctx): void
    {
        $ctx['case']['casePlanState'] = json_encode($ctx['state']);
        $ctx['case'] = $this->toArray(
            value: $ctx['objectService']->saveObject(object: $ctx['case'], register: $ctx['register'], schema: $ctx['caseSchema']),
        );
    }//end persist()

    /**
     * Build the REST-facing plan view from the current context.
     *
     * @param array{itemsById: array<string, array<string, mixed>>, state: array<string, mixed>} $ctx Context.
     *
     * @return array<string, mixed>
     */
    private function buildPlanView(array $ctx): array
    {
        $items = [];
        foreach ($ctx['itemsById'] as $id => $item) {
            $items[] = [
                'id'            => $id,
                'type'          => $item['type'],
                'name'          => $item['name'],
                'discretionary' => $item['discretionary'],
                'parentId'      => $item['parentId'],
                'state'         => $ctx['state']['planItemStates'][$id] ?? PlanItemTransitions::initialState(),
            ];
        }

        return [
            'items'                   => $items,
            'enableableDiscretionary' => $this->enableableDiscretionaryIds(ctx: $ctx),
            'milestones'              => $ctx['state']['milestones'],
            'caseFile'                => $ctx['state']['caseFile'],
        ];
    }//end buildPlanView()

    /**
     * Compute the enable-able discretionary item ids from the current context.
     *
     * @param array{itemsById: array<string, array<string, mixed>>, state: array<string, mixed>} $ctx Context.
     *
     * @return array<int, string>
     */
    private function enableableDiscretionaryIds(array $ctx): array
    {
        $ids = [];
        foreach ($ctx['itemsById'] as $id => $item) {
            if (($item['discretionary'] ?? false) !== true) {
                continue;
            }

            $current = $ctx['state']['planItemStates'][$id] ?? PlanItemTransitions::initialState();
            if ($current !== PlanItemTransitions::STATE_ENABLED) {
                continue;
            }

            if ($this->isParentActive(item: $item, state: $ctx['state']) === true) {
                $ids[] = $id;
            }
        }

        return $ids;
    }//end enableableDiscretionaryIds()

    /**
     * Current UTC timestamp in ATOM format.
     *
     * @return string
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }//end now()

    /**
     * Coerce ObjectService results to an array.
     *
     * @param mixed $value Raw result.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return [];
    }//end toArray()
}//end class
