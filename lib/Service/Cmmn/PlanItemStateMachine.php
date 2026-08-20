<?php

/**
 * Procest CMMN plan-item state machine.
 *
 * Applies ONE validated plan-item transition and everything that structurally
 * follows from it. Every state change in the CMMN engine — worker-driven,
 * sentry-driven or cascade-driven — funnels through {@see transition()}, so
 * the legality check, the event-log entry, milestone achievement and the
 * parent/child side effects can never be bypassed by a caller that writes
 * `planItemStates` directly.
 *
 * Two structural side effects are owned here:
 *   - a STAGE reaching `completed` disables any discretionary child that was
 *     never enabled — it will never be worked on now the stage is done;
 *   - a STAGE reaching `terminated` force-terminates every non-terminal
 *     child, and recursively their children, because {@see transition()}
 *     re-enters for any child that is itself a stage.
 *
 * Split out of CaseModelEngine so that "what a single transition means" is
 * separable from "which transitions the cascade decides to make".
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

use DateTimeImmutable;

/**
 * Applies a single validated plan-item transition and its side effects.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 */
class PlanItemStateMachine {

	/**
	 * Bound on the number of retained event-log entries in casePlanState.
	 */
	private const MAX_EVENT_LOG = 100;

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
	 * Apply a single validated transition, recording it and cascading its
	 * structural side effects: a stage reaching `completed` disables any
	 * still-unplanned discretionary children; a stage (or any item) reaching
	 * `terminated` force-terminates every non-terminal descendant.
	 *
	 * @param array<string, mixed> $item The plan item.
	 * @param string $from Current state.
	 * @param string $to Target state.
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 *
	 * @return void
	 *
	 * @throws IllegalPlanItemTransitionException When the transition is not legal.
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md
	 */
	public function transition(array $item, string $from, string $to, array &$itemsById, array &$state): void {
		$this->transitions->assertLegal(itemId: (string)$item['id'], itemType: (string)$item['type'], fromState: $from, toState: $to);

		$state['planItemStates'][$item['id']] = $to;
		$this->appendEvent(state: $state, itemId: (string)$item['id'], itemType: (string)$item['type'], from: $from, to: $to);

		if ($to === PlanItemTransitions::STATE_COMPLETED && $item['type'] === PlanItemTransitions::TYPE_MILESTONE) {
			$state['milestones'][$item['id']] = [
				'achieved' => true,
				'achievedAt' => $this->now(),
			];
		}

		if ($item['type'] === PlanItemTransitions::TYPE_STAGE) {
			if ($to === PlanItemTransitions::STATE_COMPLETED) {
				$this->disableUnplannedDiscretionaryChildren(stageId: (string)$item['id'], itemsById: $itemsById, state: $state);
			} elseif ($to === PlanItemTransitions::STATE_TERMINATED) {
				$this->forceTerminateChildren(stageId: (string)$item['id'], itemsById: $itemsById, state: $state);
			}
		}
	}//end transition()

	/**
	 * When a stage completes naturally, any discretionary child that was
	 * never enabled (still `available`/`enabled`) is disabled — it will
	 * never be worked on now the stage is done.
	 *
	 * @param string $stageId Stage plan-item id.
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 *
	 * @return void
	 */
	private function disableUnplannedDiscretionaryChildren(string $stageId, array &$itemsById, array &$state): void {
		foreach ($itemsById as $id => $item) {
			if (($item['parentId'] ?? null) !== $stageId || ($item['discretionary'] ?? false) !== true) {
				continue;
			}

			$current = $state['planItemStates'][$id] ?? $this->transitions->initialState();
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
	 * @param string $stageId Stage plan-item id.
	 * @param array<string, array<string, mixed>> $itemsById Plan items by id.
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 *
	 * @return void
	 */
	private function forceTerminateChildren(string $stageId, array &$itemsById, array &$state): void {
		foreach ($itemsById as $id => $item) {
			if (($item['parentId'] ?? null) !== $stageId) {
				continue;
			}

			$current = $state['planItemStates'][$id] ?? $this->transitions->initialState();
			if ($this->transitions->isTerminal(state: $current) === true) {
				continue;
			}

			$this->transition(item: $item, from: $current, to: PlanItemTransitions::STATE_TERMINATED, itemsById: $itemsById, state: $state);
		}
	}//end forceTerminateChildren()

	/**
	 * Append a bounded event-log entry.
	 *
	 * @param array<string, mixed> $state Runtime state, mutated in place.
	 * @param string $itemId Plan-item id.
	 * @param string $itemType Plan-item type.
	 * @param string $from Prior state.
	 * @param string $to New state.
	 *
	 * @return void
	 */
	private function appendEvent(array &$state, string $itemId, string $itemType, string $from, string $to): void {
		$state['eventLog'][] = [
			'at' => $this->now(),
			'itemId' => $itemId,
			'itemType' => $itemType,
			'from' => $from,
			'to' => $to,
		];

		if (count($state['eventLog']) > self::MAX_EVENT_LOG) {
			$state['eventLog'] = array_slice($state['eventLog'], -self::MAX_EVENT_LOG);
		}
	}//end appendEvent()

	/**
	 * Current UTC timestamp in ATOM format.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DATE_ATOM);
	}//end now()
}//end class
