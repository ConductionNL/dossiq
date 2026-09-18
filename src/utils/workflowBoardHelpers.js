/**
 * Workflow-board helper utilities.
 *
 * Pure logic extracted from the board's move surface so what a card may be
 * moved to is unit-testable without mounting a component.
 *
 * @spec openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
 */

import {
	findTransitionToStatus,
	transitionBlockReason,
	transitionIsBlocked,
} from './caseLifecycleHelpers.js'

/**
 * The statuses a case may be moved to, from the engine's own offer.
 *
 * WHAT THIS REPLACED, because the difference is the whole point. The move
 * control used to list every board COLUMN except the card's own. Columns are
 * merged by status name across every case type on the instance, so a building
 * permit was offered statuses out of unrelated workflows — two hundred of them
 * on a real register, almost none of which the case could reach. The engine
 * already answers the question properly, per case and per role, and this maps
 * that answer onto the dialog.
 *
 * `id` is the merged column NAME, not the status id, because that is what the
 * board's move handler takes — the same argument a drop passes, so both
 * gestures go through one path.
 *
 * Deduplicated by target status, KEEPING THE FIRST: two transitions can end on
 * the same status ("Approve" and "Approve with conditions" both closing a
 * case), and `findTransitionToStatus` picks the first match when the move is
 * posted. Keeping the first here means the entry's blocked state and reason
 * belong to the transition that will actually run, rather than describing one
 * and posting another.
 *
 * A blocked transition is listed, disabled, carrying its reason. The engine
 * offers it rather than hiding it, and the case page shows it the same way: an
 * absent option tells the handler nothing about what to do next.
 *
 * @param {Array<object>} transitions The `/available-transitions` answer's `transitions`.
 * @param {Object<string, {name: string}>} statusById Status types by id, for naming the target.
 * @return {Array<{id: string, label: string, statusId: string, disabled: boolean, reason: string}>} The offered targets.
 * @spec openspec/specs/status-transition-engine/spec.md#requirement-transition-execution
 */
export function moveTargetsFromTransitions(transitions, statusById) {
	if (Array.isArray(transitions) === false) {
		return []
	}

	const byId = statusById || {}
	const targets = []
	const seen = new Set()

	for (const transition of transitions) {
		if (!transition || typeof transition !== 'object') {
			continue
		}

		const statusId = String(transition.toStatus ?? '')
		if (statusId === '' || seen.has(statusId)) {
			continue
		}
		seen.add(statusId)

		const name = String(byId[statusId]?.name ?? '')
		targets.push({
			// The name addresses the column; without one there is nothing for
			// the move handler to resolve, so fall back to what the engine
			// called the transition and let it refuse by name.
			id: name,
			label: name || String(transition.label ?? statusId),
			statusId,
			disabled: transitionIsBlocked(transition) || name === '',
			reason: transitionBlockReason(transition),
		})
	}

	return targets
}

/**
 * Whether the card in the air may land in a column, decided while it is still
 * in the air.
 *
 * A column the case cannot reach refuses the ghost, so the card is never
 * taken and handed back with a toast. `allowed` is null while the engine's
 * offer is still on its way: every column accepts, and the drop path checks
 * again after the fact, exactly as it did before the drag could ask.
 *
 * @param {object} drag The drag in progress.
 * @param {string} drag.fromColumn The column the card came from.
 * @param {string} drag.toColumn The column it is held over.
 * @param {string} drag.caseType The case's case type id.
 * @param {Array<object>|null} drag.offered The `/available-transitions` answer, or null while pending.
 * @param {Object<string, string>} drag.statusIdByTypeAndName `${caseType}::${statusName}` → status id.
 * @return {{allowed: boolean|null, blocked: boolean, reason: string}} The verdict.
 * @spec openspec/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
 */
export function dropVerdict({
	fromColumn,
	toColumn,
	caseType,
	offered,
	statusIdByTypeAndName,
}) {
	if (String(fromColumn) === String(toColumn)) {
		return { allowed: true, blocked: false, reason: '' }
	}
	const targetStatusId = (statusIdByTypeAndName || {})[`${caseType}::${toColumn}`]
	if (!targetStatusId) {
		return { allowed: false, blocked: false, reason: '' }
	}
	if (!Array.isArray(offered)) {
		return { allowed: null, blocked: false, reason: '' }
	}
	const transition = findTransitionToStatus(offered, targetStatusId)
	if (transition === null) {
		return { allowed: false, blocked: false, reason: '' }
	}
	if (transitionIsBlocked(transition)) {
		return {
			allowed: false,
			blocked: true,
			reason: transitionBlockReason(transition),
		}
	}
	return { allowed: true, blocked: false, reason: '' }
}
