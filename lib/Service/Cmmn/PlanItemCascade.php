<?php

/**
 * Dossiq CMMN cascade evaluator.
 *
 * Drives the plan to a fixed point after any mutation: repeatedly evaluates
 * every non-terminal plan item's exit and entry sentries until a pass changes
 * nothing, so one worker action or case-file write settles the whole plan in a
 * single call rather than leaving it to the next request.
 *
 * Two properties are what this class exists to hold:
 *
 *   - ORDER INDEPENDENCE. Each pass evaluates against a `$context` snapshot
 *     taken at the START of the pass, so an item's result never depends on
 *     where it happens to sit in the iteration order; anything a pass changes
 *     is picked up by the NEXT pass instead.
 *   - TERMINATION. The loop is bounded by MAX_CASCADE_DEPTH. A well-formed
 *     model reaches its fixed point long before that; hitting the bound means
 *     the model has an authoring cycle (e.g. two items whose entry sentries
 *     reference each other's completion) and the bound is what stops it
 *     looping forever.
 *
 * Split out of CaseModelEngine so the fixed-point loop sits next to the single
 * pass it repeats, and apart from both the transition semantics
 * ({@see PlanItemStateMachine}) and the persistence
 * ({@see CasePlanRepository}).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cmmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cmmn;

/**
 * Evaluates entry/exit sentries to a fixed point after a plan mutation.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
 */
class PlanItemCascade {

	/**
	 * Bound on cascade fixed-point iterations per mutation — protects against
	 * an authoring cycle in the case model (e.g. two plan items whose entry
	 * sentries reference each other's completion) looping forever. Reaching
	 * the bound is a defensive stop, not expected in a well-formed model.
	 */
	private const MAX_CASCADE_DEPTH = 50;

	/**
	 * Constructor.
	 *
	 * @param PlanItemTransitions $transitions Legal plan-item transition table.
	 * @param SentryEvaluator $sentries Pure sentry-firing evaluator.
	 * @param PlanItemTree $tree Structural queries over the plan-item hierarchy.
	 * @param PlanItemStateMachine $stateMachine Single-transition application.
	 */
	public function __construct(
		private readonly PlanItemTransitions $transitions,
		private readonly SentryEvaluator $sentries,
		private readonly PlanItemTree $tree,
		private readonly PlanItemStateMachine $stateMachine,
	) {
	}//end __construct()

	/**
	 * Run cascade passes to a fixed point (or MAX_CASCADE_DEPTH).
	 *
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 * @param array<int, string> $touchedKeys Case-file keys touched this call.
	 * @param array<int, string> $changedKeys Subset of touchedKeys whose value changed.
	 *
	 * @return bool Whether any transition occurred across all passes.
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
	 */
	public function run(array &$itemsById, array &$state, array $touchedKeys, array $changedKeys): bool {
		$anyChanged = false;
		for ($depth = 0; $depth < self::MAX_CASCADE_DEPTH; $depth++) {
			$passChanged = $this->cascadePass(itemsById: $itemsById, state: $state, touchedKeys: $touchedKeys, changedKeys: $changedKeys);
			if ($passChanged === false) {
				break;
			}

			$anyChanged = true;
		}

		return $anyChanged;
	}//end run()

	/**
	 * One evaluation pass over every non-terminal item, against a snapshot
	 * taken at the start of the pass (so results are independent of item
	 * iteration order — a later pass picks up anything this pass changed).
	 *
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 * @param array<int, string> $touchedKeys Case-file keys touched this call.
	 * @param array<int, string> $changedKeys Subset of touchedKeys whose value changed.
	 *
	 * @return bool Whether this pass changed any item's state.
	 */
	private function cascadePass(array &$itemsById, array &$state, array $touchedKeys, array $changedKeys): bool {
		$changed = false;
		$context = [
			'planItemStates' => $state['planItemStates'],
			'caseFile' => $state['caseFile'],
			'touchedKeys' => $touchedKeys,
			'changedKeys' => $changedKeys,
		];

		foreach ($itemsById as $id => $item) {
			if ($this->evaluateItem(id: $id, item: $item, itemsById: $itemsById, state: $state, context: $context) === true) {
				$changed = true;
			}
		}

		return $changed;
	}//end cascadePass()

	/**
	 * Evaluate one plan item against the pass's snapshot: an exit sentry terminates it, a
	 * satisfied entry advances it from `available`, and an active stage whose mandatory
	 * children are all terminal completes.
	 *
	 * @param int|string $id The plan item id.
	 * @param array<string, mixed> $item The plan item.
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 * @param array<string, mixed> $context The sentry context snapshot of this pass.
	 *
	 * @return bool Whether the item's state changed.
	 */
	private function evaluateItem(int|string $id, array $item, array &$itemsById, array &$state, array $context): bool {
		$current = $state['planItemStates'][$id] ?? $this->transitions->initialState();
		if ($this->transitions->isTerminal(state: $current) === true
			|| $this->tree->isParentActive(item: $item, state: $state) === false
		) {
			return false;
		}

		if ($this->criteriaFire(criteria: $item['exitCriteria'] ?? [], context: $context) === true) {
			$this->stateMachine->transition(
				item: $item,
				from: $current,
				to: PlanItemTransitions::STATE_TERMINATED,
				itemsById: $itemsById,
				state: $state,
			);
			return true;
		}

		if ($current === PlanItemTransitions::STATE_AVAILABLE) {
			// No entry criteria means the item may start; otherwise one of them must fire.
			$entryCriteria = $item['entryCriteria'] ?? [];
			if ($this->hasCriteria(criteria: $entryCriteria) === true
				&& $this->criteriaFire(criteria: $entryCriteria, context: $context) === false
			) {
				return false;
			}

			$this->advanceFromAvailable(item: $item, current: $current, itemsById: $itemsById, state: $state);
			return true;
		}

		return $this->completeStageIfDone(id: $id, item: $item, current: $current, itemsById: $itemsById, state: $state);
	}//end evaluateItem()

	/**
	 * Complete an active stage whose mandatory children are all terminal.
	 *
	 * @param int|string $id The plan item id.
	 * @param array<string, mixed> $item The plan item.
	 * @param string $current Its current state.
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 *
	 * @return bool Whether the stage completed.
	 */
	private function completeStageIfDone(int|string $id, array $item, string $current, array &$itemsById, array &$state): bool {
		if ($current !== PlanItemTransitions::STATE_ACTIVE || $item['type'] !== PlanItemTransitions::TYPE_STAGE
			|| $this->tree->stageMandatoryChildrenAllTerminal(stageId: $id, itemsById: $itemsById, state: $state) === false
		) {
			return false;
		}

		$this->stateMachine->transition(
			item: $item,
			from: $current,
			to: PlanItemTransitions::STATE_COMPLETED,
			itemsById: $itemsById,
			state: $state,
		);
		return true;
	}//end completeStageIfDone()

	/**
	 * Whether a criteria list declares any sentry.
	 *
	 * @param mixed $criteria The item's entry or exit criteria.
	 *
	 * @return bool True when it is a non-empty list.
	 */
	private function hasCriteria(mixed $criteria): bool {
		return is_array($criteria) === true && count($criteria) > 0;
	}//end hasCriteria()

	/**
	 * Whether any sentry of a non-empty criteria list fires against the snapshot.
	 *
	 * @param mixed $criteria The item's entry or exit criteria.
	 * @param array<string, mixed> $context The sentry context snapshot of this pass.
	 *
	 * @return bool True when the list is non-empty and one of its sentries fires.
	 */
	private function criteriaFire(mixed $criteria, array $context): bool {
		return $this->hasCriteria(criteria: $criteria) === true
			&& $this->sentries->anyFires(sentries: $criteria, context: $context) === true;
	}//end criteriaFire()

	/**
	 * Advance a plan item whose entry criteria just became satisfied: a
	 * milestone completes directly; a stage/humanTask enables, then
	 * auto-cascades straight to `active` unless it is discretionary (which
	 * stops at `enabled`, pending the worker's opt-in).
	 *
	 * @param array<string, mixed> $item The plan item (state `available`).
	 * @param string $current Current state (`available`).
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 *
	 * @return void
	 */
	private function advanceFromAvailable(array $item, string $current, array &$itemsById, array &$state): void {
		if ($item['type'] === PlanItemTransitions::TYPE_MILESTONE) {
			$this->stateMachine->transition(
				item: $item,
				from: $current,
				to: PlanItemTransitions::STATE_COMPLETED,
				itemsById: $itemsById,
				state: $state,
			);
			return;
		}

		$this->stateMachine->transition(
			item: $item,
			from: $current,
			to: PlanItemTransitions::STATE_ENABLED,
			itemsById: $itemsById,
			state: $state,
		);
		if (($item['discretionary'] ?? false) !== true) {
			$this->stateMachine->transition(
				item: $item,
				from: PlanItemTransitions::STATE_ENABLED,
				to: PlanItemTransitions::STATE_ACTIVE,
				itemsById: $itemsById,
				state: $state,
			);
		}
	}//end advanceFromAvailable()
}//end class
