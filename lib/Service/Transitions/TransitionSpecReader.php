<?php

/**
 * Dossiq Transition Spec Reader.
 *
 * Reads the shapes a workflowTemplate transition may legally take. Split out
 * of StatusTransitionService so that service keeps only the engine logic: the
 * knowledge that a transition may carry its guards as `guards: []` or as a
 * bare `allowedRoles: []` to be promoted into a roleGuard, that its actions
 * may be spelled `automaticActions` or `actions`, and that a silently-failing
 * roleGuard hides a transition rather than reporting it — all of that
 * tolerance for template dialects lives here and nowhere else.
 *
 * Stateless and dependency-free: every method is a pure function of the
 * transition (or guard-evaluation) array it is handed.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * Normalises the guard, action and role-visibility shapes of a transition.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class TransitionSpecReader {
	/**
	 * Extract the guards list from a transition definition (supports both
	 * `guards: []` and a single `guard: {...}` shape).
	 *
	 * @param array<string, mixed> $transition The transition.
	 *
	 * @return array<int, array<string, mixed>> The normalised guard list.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function extractGuards(array $transition): array {
		$guards = $transition['guards'] ?? [];
		if (is_array($guards) === false) {
			$guards = [];
		}

		// Promote allowedRoles[] on the transition itself into a roleGuard entry.
		$allowedRoles = $transition['allowedRoles'] ?? null;
		if (is_array($allowedRoles) === true && count($allowedRoles) > 0) {
			$guards[] = ['type' => 'roleGuard', 'allowedRoles' => $allowedRoles];
		}

		$list = [];
		foreach ($guards as $guard) {
			if (is_array($guard) === true) {
				$list[] = $guard;
			}
		}

		return $list;
	}//end extractGuards()

	/**
	 * Extract automaticActions[] from a transition definition.
	 *
	 * @param array<string, mixed> $transition The transition.
	 *
	 * @return array<int, array<string, mixed>> The normalised action list.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function extractActions(array $transition): array {
		$actions = $transition['automaticActions'] ?? ($transition['actions'] ?? []);
		if (is_array($actions) === false) {
			return [];
		}

		$list = [];
		foreach ($actions as $action) {
			if (is_array($action) === true) {
				$list[] = $action;
			}
		}

		return $list;
	}//end extractActions()

	/**
	 * Detect whether the role guard has hidden the transition silently.
	 *
	 * @param array<int, array<string, mixed>> $evalResults Guard evaluation snapshots.
	 *
	 * @return bool True when the transition must not be offered at all.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isRoleHidden(array $evalResults): bool {
		foreach ($evalResults as $entry) {
			if (($entry['type'] ?? '') === 'roleGuard'
				&& $entry['passed'] === false
				&& (($entry['details']['silent'] ?? false) === true)
			) {
				return true;
			}
		}

		return false;
	}//end isRoleHidden()
}//end class
