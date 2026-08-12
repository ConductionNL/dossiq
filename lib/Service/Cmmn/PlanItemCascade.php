<?php

/**
 * Procest CMMN cascade evaluator.
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
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Cmmn;

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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — one evaluation pass over the state machine's
	 *   own branches (exit sentry, entry sentry, mandatory-cascade, stage auto-complete); splitting
	 *   it would scatter one pass across several methods that all need the same $context snapshot.
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
			$current = $state['planItemStates'][$id] ?? $this->transitions->initialState();
			if ($this->transitions->isTerminal(state: $current) === true) {
				continue;
			}

			if ($this->tree->isParentActive(item: $item, state: $state) === false) {
				continue;
			}

			$exitCriteria = $item['exitCriteria'] ?? [];
			if (is_array($exitCriteria) === true && count($exitCriteria) > 0
				&& $this->sentries->anyFires(sentries: $exitCriteria, context: $context) === true
			) {
				$this->stateMachine->transition(
					item: $item,
					from: $current,
					to: PlanItemTransitions::STATE_TERMINATED,
					itemsById: $itemsById,
					state: $state,
				);
				$changed = true;
				continue;
			}

			if ($current === PlanItemTransitions::STATE_AVAILABLE) {
				$entryCriteria = $item['entryCriteria'] ?? [];
				$hasNoCriteria = (is_array($entryCriteria) === false || count($entryCriteria) === 0);
				$satisfied = $hasNoCriteria || $this->sentries->anyFires(sentries: $entryCriteria, context: $context);

				if ($satisfied === true) {
					$this->advanceFromAvailable(item: $item, current: $current, itemsById: $itemsById, state: $state);
					$changed = true;
				}

				continue;
			}

			if ($current === PlanItemTransitions::STATE_ACTIVE && $item['type'] === PlanItemTransitions::TYPE_STAGE) {
				if ($this->tree->stageMandatoryChildrenAllTerminal(stageId: $id, itemsById: $itemsById, state: $state) === true) {
					$this->stateMachine->transition(
						item: $item,
						from: $current,
						to: PlanItemTransitions::STATE_COMPLETED,
						itemsById: $itemsById,
						state: $state,
					);
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
