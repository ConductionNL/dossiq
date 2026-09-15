/**
 * Bulk status-transition helper utilities.
 *
 * Pure logic extracted from the workflow-board selection UI and
 * BulkTransitionDialog.vue so column-scoped selection, request-payload
 * shaping, and per-case result summarisation are unit-testable without
 * mounting a component (the node-env vitest suite cannot mount SFCs).
 *
 * Selection shape: `{ columnId: string|null, caseIds: Array<string> }`.
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md
 */

/**
 * An empty selection — no column, no cases.
 *
 * @return {{columnId: null, caseIds: Array<string>}}
 */
export function emptySelection() {
	return { columnId: null, caseIds: [] }
}

/**
 * Toggle a case's membership in the selection, scoped to a single column.
 * Selecting a case in a DIFFERENT column than the current selection resets
 * the selection to contain only the newly selected case (cross-column
 * selection always clears the previous selection — never merges columns).
 * Deselecting the last case in a column clears the column scope too, so the
 * next selection in ANY column starts fresh.
 *
 * @param {{columnId: string|null, caseIds: Array<string>}} selection Current selection state.
 * @param {string} caseId The case id being toggled.
 * @param {string} columnId The column the case belongs to.
 * @return {{columnId: string|null, caseIds: Array<string>}} The next selection state (new object).
 */
export function toggleSelection(selection, caseId, columnId) {
	const current =
		selection && typeof selection === 'object' ? selection : emptySelection()

	if (current.columnId !== columnId) {
		// Selecting in a new/different column resets the selection.
		return { columnId, caseIds: [caseId] }
	}

	const caseIds = Array.isArray(current.caseIds) ? current.caseIds : []
	const exists = caseIds.some((id) => String(id) === String(caseId))
	const nextCaseIds = exists
		? caseIds.filter((id) => String(id) !== String(caseId))
		: [...caseIds, caseId]

	if (nextCaseIds.length === 0) {
		return emptySelection()
	}

	return { columnId, caseIds: nextCaseIds }
}

/**
 * Whether a given case is currently selected.
 *
 * @param {{columnId: string|null, caseIds: Array<string>}} selection Current selection state.
 * @param {string} caseId The case id to check.
 * @return {boolean}
 */
export function isSelected(selection, caseId) {
	if (!selection || !Array.isArray(selection.caseIds)) return false
	return selection.caseIds.some((id) => String(id) === String(caseId))
}

/**
 * Clear the selection entirely.
 *
 * @return {{columnId: null, caseIds: Array<string>}}
 */
export function clearSelection() {
	return emptySelection()
}

/**
 * The parameters a status transition needs as a bulk job.
 *
 * The comment travels as a parameter rather than as the job's justification:
 * the job's justification is the record of why the ACT was ordered, and the
 * comment is what lands on each case's own timeline. They are usually the same
 * sentence and are still two different things.
 *
 * @param {string} transitionId The transition to run.
 * @param {string} [comment] What to write on each case.
 *
 * @return {{transitionId: string, comment: string}} The job parameters.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function transitionParameters(transitionId, comment) {
	return { transitionId: (transitionId || ''), comment: (comment || '') }
}

/**
 * The parameters a lifecycle gesture needs as a bulk job.
 *
 * `days` goes only with suspend and `newEndDate` only with extend. A key the
 * gesture has no use for is noise in the audit trail and a value the next
 * reader has to explain away.
 *
 * The reason is a parameter AND the job's justification. The job stores it as
 * the record of the act; the per-case write reads it from the parameters,
 * because a bulk action is handed its parameters and not its job.
 *
 * @param {string} gesture One of suspend, resume, extend.
 * @param {{reason?: string, days?: (number|string), newEndDate?: string}} [fields] The dialog's fields.
 *
 * @return {object} The job parameters.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function lifecycleParameters(gesture, fields) {
	const given = (fields || {})
	const parameters = {
		gesture: (gesture || ''),
		reason: (given.reason || '').trim(),
	}

	if (gesture === 'suspend') {
		parameters.days = (Number(given.days) || 0)
	}

	if (gesture === 'extend') {
		parameters.newEndDate = (given.newEndDate || '')
	}

	return parameters
}

/**
 * The lifecycle gestures a bulk call may ask for, beside a transition.
 *
 * A transition is not one of them: it moves `case.status` and goes through
 * the status engine, which is that field's only write path. These three move
 * the statutory CLOCK instead (opschorting under Awb 4:5, hervatting,
 * verlenging under Awb 4:14) and go through `CaseLifecycleService` — the
 * same single-case gestures the case page's Actions menu calls.
 */
export const LIFECYCLE_GESTURES = ['suspend', 'resume', 'extend']

/**
 * Whether a dialog mode is one of the lifecycle gestures.
 *
 * @param {string} mode The dialog mode.
 * @return {boolean} True for suspend / resume / extend.
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */
export function isLifecycleGesture(mode) {
	return LIFECYCLE_GESTURES.includes(mode)
}
