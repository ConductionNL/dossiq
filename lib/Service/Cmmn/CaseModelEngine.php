<?php

/**
 * Dossiq CMMN Case-Model Runtime Engine.
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
 * This class is the REST-facing orchestrator; the four pieces it drives each
 * own one concern:
 *   - {@see CasePlanRepository}   loads the case/caseType/plan items and
 *                                 performs the single persist per mutation;
 *   - {@see PlanItemCascade}      drives the plan to a fixed point;
 *   - {@see PlanItemStateMachine} applies one validated transition;
 *   - {@see PlanItemTree}         answers structural queries over the hierarchy.
 *
 * Every mutating method follows the same shape — load, initialise, cascade,
 * act, cascade again, persist — so a worker action and its knock-on effects
 * settle within one request and reach storage in one write.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cmmn
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cmmn;

use RuntimeException;

/**
 * The CMMN plan-item lifecycle + sentry evaluation engine.
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 */
class CaseModelEngine {
	/**
	 * Constructor.
	 *
	 * @param CasePlanRepository $repository Case/caseType/plan-item loading and persistence.
	 * @param PlanItemCascade $cascade Fixed-point sentry evaluation.
	 * @param PlanItemStateMachine $stateMachine Single validated transition application.
	 * @param PlanItemTree $tree Structural queries over the plan-item hierarchy.
	 * @param PlanItemTransitions $transitions Legal plan-item transition table.
	 */
	public function __construct(
		private readonly CasePlanRepository $repository,
		private readonly PlanItemCascade $cascade,
		private readonly PlanItemStateMachine $stateMachine,
		private readonly PlanItemTree $tree,
		private readonly PlanItemTransitions $transitions,
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
	public function getCasePlan(string $caseId): array {
		$ctx = $this->repository->loadContext(caseId: $caseId);
		$newInit = $this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
		$cascaded = $this->cascade->run(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);
		if ($newInit === true || $cascaded === true) {
			$this->repository->persist(ctx: $ctx);
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
	 * @throws RuntimeException When the case/item cannot be resolved.
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-004
	 */
	public function enableDiscretionaryItem(string $caseId, string $itemId): array {
		$ctx = $this->repository->loadContext(caseId: $caseId);
		$this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
		$this->cascade->run(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);

		$item = $ctx['itemsById'][$itemId] ?? null;
		if ($item === null) {
			throw new RuntimeException('plan_item_not_found');
		}

		$current = $ctx['state']['planItemStates'][$itemId] ?? $this->transitions->initialState();
		if (($item['discretionary'] ?? false) !== true) {
			// A mandatory item is never manually enabled — even though
			// enabled→active is a legal edge in the table (it is how the
			// engine's own auto-cascade advances mandatory items), this
			// REST-facing action is reserved for discretionary opt-in.
			throw new IllegalPlanItemTransitionException(
				itemId: $itemId,
				itemType: (string)$item['type'],
				fromState: $current,
				toState: PlanItemTransitions::STATE_ACTIVE,
			);
		}

		$this->stateMachine->transition(
			item: $item,
			from: $current,
			to: PlanItemTransitions::STATE_ACTIVE,
			itemsById: $ctx['itemsById'],
			state: $ctx['state'],
		);
		$this->cascade->run(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);
		$this->repository->persist(ctx: $ctx);

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
	 * @throws RuntimeException When the item is not a humanTask.
	 * @throws IllegalPlanItemTransitionException When the item is not currently active.
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
	 */
	public function completeTask(string $caseId, string $itemId): array {
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
	 * @throws RuntimeException When the item is not a humanTask.
	 * @throws IllegalPlanItemTransitionException When the item is already terminal.
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
	 */
	public function terminateTask(string $caseId, string $itemId): array {
		return $this->transitionHumanTask(caseId: $caseId, itemId: $itemId, to: PlanItemTransitions::STATE_TERMINATED);
	}//end terminateTask()

	/**
	 * Signal that a case-file item was set/changed, re-evaluating every
	 * sentry that may reference it. The single write path for case-file
	 * mutation and its resulting cascade — exactly one `saveObject()` call.
	 *
	 * @param string $caseId Case UUID.
	 * @param array<string, mixed> $updates Case-file item id => new value.
	 *
	 * @return array<string, mixed> The updated case plan.
	 *
	 * @throws RuntimeException When the case/caseType cannot be loaded or is not CMMN-managed.
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
	 */
	public function signalCaseFileEvent(string $caseId, array $updates): array {
		$ctx = $this->repository->loadContext(caseId: $caseId);
		$this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
		$this->cascade->run(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);

		$oldCaseFile = $ctx['state']['caseFile'];
		$touchedKeys = [];
		$changedKeys = [];
		foreach ($updates as $key => $value) {
			$key = (string)$key;
			$touchedKeys[] = $key;
			if (array_key_exists($key, $oldCaseFile) === false || $oldCaseFile[$key] !== $value) {
				$changedKeys[] = $key;
			}

			$ctx['state']['caseFile'][$key] = $value;
		}

		$this->cascade->run(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: $touchedKeys, changedKeys: $changedKeys);
		// Always persist — the case-file mutation itself must be saved even
		// when no plan-item state changed as a result.
		$this->repository->persist(ctx: $ctx);

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
	public function getPlanItemAuthorization(string $caseId, string $itemId): array {
		$ctx = $this->repository->loadContext(caseId: $caseId);
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
	public function getEnableableDiscretionaryItems(string $caseId): array {
		$ctx = $this->repository->loadContext(caseId: $caseId);
		$this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
		$this->cascade->run(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);

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
	 * @param string $to Target state (`completed`|`terminated`).
	 *
	 * @return array<string, mixed>
	 */
	private function transitionHumanTask(string $caseId, string $itemId, string $to): array {
		$ctx = $this->repository->loadContext(caseId: $caseId);
		$this->ensureInitialized(state: $ctx['state'], itemsById: $ctx['itemsById']);
		$this->cascade->run(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);

		$item = $ctx['itemsById'][$itemId] ?? null;
		if ($item === null) {
			throw new RuntimeException('plan_item_not_found');
		}

		if ($item['type'] !== PlanItemTransitions::TYPE_HUMAN_TASK) {
			throw new RuntimeException('not_a_human_task');
		}

		$current = $ctx['state']['planItemStates'][$itemId] ?? $this->transitions->initialState();
		$this->stateMachine->transition(item: $item, from: $current, to: $to, itemsById: $ctx['itemsById'], state: $ctx['state']);
		$this->cascade->run(itemsById: $ctx['itemsById'], state: $ctx['state'], touchedKeys: [], changedKeys: []);
		$this->repository->persist(ctx: $ctx);

		return $this->buildPlanView(ctx: $ctx);
	}//end transitionHumanTask()

	// ------------------------------------------------------------------
	// Runtime-state initialisation / view
	// ------------------------------------------------------------------

	/**
	 * Set every plan item not yet present in runtime state to `available`.
	 *
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 *
	 * @return bool Whether any item was newly initialised.
	 */
	private function ensureInitialized(array &$state, array $itemsById): bool {
		$changed = false;
		foreach ($itemsById as $id => $item) {
			unset($item);
			if (isset($state['planItemStates'][$id]) === false) {
				$state['planItemStates'][$id] = $this->transitions->initialState();
				$changed = true;
			}
		}

		return $changed;
	}//end ensureInitialized()

	/**
	 * Build the REST-facing plan view from the current context.
	 *
	 * @param array{itemsById: array<string, array<string, mixed>>, state: array<string, mixed>} $ctx Context.
	 *
	 * @return array<string, mixed>
	 */
	private function buildPlanView(array $ctx): array {
		$items = [];
		foreach ($ctx['itemsById'] as $id => $item) {
			$items[] = [
				'id' => $id,
				'type' => $item['type'],
				'name' => $item['name'],
				'discretionary' => $item['discretionary'],
				'parentId' => $item['parentId'],
				'state' => $ctx['state']['planItemStates'][$id] ?? $this->transitions->initialState(),
			];
		}

		return [
			'items' => $items,
			'enableableDiscretionary' => $this->enableableDiscretionaryIds(ctx: $ctx),
			'milestones' => $ctx['state']['milestones'],
			'caseFile' => $ctx['state']['caseFile'],
		];
	}//end buildPlanView()

	/**
	 * Compute the enable-able discretionary item ids from the current context.
	 *
	 * @param array{itemsById: array<string, array<string, mixed>>, state: array<string, mixed>} $ctx Context.
	 *
	 * @return array<int, string>
	 */
	private function enableableDiscretionaryIds(array $ctx): array {
		$ids = [];
		foreach ($ctx['itemsById'] as $id => $item) {
			if (($item['discretionary'] ?? false) !== true) {
				continue;
			}

			$current = $ctx['state']['planItemStates'][$id] ?? $this->transitions->initialState();
			if ($current !== PlanItemTransitions::STATE_ENABLED) {
				continue;
			}

			if ($this->tree->isParentActive(item: $item, state: $ctx['state']) === true) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end enableableDiscretionaryIds()
}//end class
