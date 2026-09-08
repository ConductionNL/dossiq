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
 * Build the request payload for `POST /api/cases/bulk-transition/preview`.
 *
 * @param {{columnId: string|null, caseIds: Array<string>}} selection Current selection state.
 * @param {string} transitionId The transition id to preview.
 * @return {{caseIds: Array<string>, transitionId: string}}
 */
export function buildPreviewPayload(selection, transitionId) {
	const caseIds =
		selection && Array.isArray(selection.caseIds) ? [...selection.caseIds] : []
	return { caseIds, transitionId: transitionId || '' }
}

/**
 * Build the request payload for `POST /api/cases/bulk-transition/execute`.
 *
 * @param {{columnId: string|null, caseIds: Array<string>}} selection Current selection state.
 * @param {string} transitionId The transition id to execute.
 * @param {string|null} [comment] Optional free-form comment applied to every case.
 * @return {{caseIds: Array<string>, transitionId: string, comment: string|null}}
 */
export function buildExecutePayload(selection, transitionId, comment) {
	const caseIds =
		selection && Array.isArray(selection.caseIds) ? [...selection.caseIds] : []
	return { caseIds, transitionId: transitionId || '', comment: comment || null }
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
 */
export function isLifecycleGesture(mode) {
	return LIFECYCLE_GESTURES.includes(mode)
}

/**
 * Build the request payload for a bulk LIFECYCLE preview.
 *
 * The endpoint is the same one a transition previews through
 * (`/api/cases/bulk-transition/preview`); `gesture` is what tells it apart.
 * One endpoint because the preview, the per-case result map and the
 * partial-failure reporting are the parts worth keeping equal across all
 * four bulk actions — which is also why one dialog serves them.
 *
 * @param {{columnId: string|null, caseIds: Array<string>}} selection Current selection state.
 * @param {string} gesture One of suspend, resume, extend.
 * @return {{caseIds: Array<string>, gesture: string}}
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */
export function buildLifecyclePreviewPayload(selection, gesture) {
	const caseIds =
		selection && Array.isArray(selection.caseIds) ? [...selection.caseIds] : []
	return { caseIds, gesture: gesture || '' }
}

/**
 * Build the request payload for a bulk LIFECYCLE execute.
 *
 * The reason is sent for every gesture, never only for suspend. Each of the
 * three is a statutory act someone has to justify later, and the server
 * refuses a batch without one — a bulk gesture is exactly when a
 * justification goes unwritten, so it is required rather than optional here
 * the way the transition `comment` is.
 *
 * `days` is sent only for suspend and `newEndDate` only for extend: a key
 * the gesture has no use for is noise in the audit trail and a value the
 * next reader has to explain away.
 *
 * @param {{columnId: string|null, caseIds: Array<string>}} selection Current selection state.
 * @param {string} gesture One of suspend, resume, extend.
 * @param {{reason?: string, days?: (number|string), newEndDate?: string}} [fields] The dialog's fields.
 * @return {{caseIds: Array<string>, gesture: string, reason: string, days?: number, newEndDate?: string}}
 *
 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
 */
export function buildLifecycleExecutePayload(selection, gesture, fields) {
	const caseIds =
		selection && Array.isArray(selection.caseIds) ? [...selection.caseIds] : []
	const given = fields || {}
	const payload = {
		caseIds,
		gesture: gesture || '',
		reason: (given.reason || '').trim(),
	}

	if (gesture === 'suspend') {
		payload.days = Number(given.days) || 0
	}

	if (gesture === 'extend') {
		payload.newEndDate = given.newEndDate || ''
	}

	return payload
}

/**
 * Summarise a bulk preview/execute `results` map (`{caseId: {status, reasons?}}`)
 * into per-status counts and the list of non-ready/non-succeeded entries
 * (blocked/failed/error) so a dialog can render "N ready, M blocked" plus the
 * specific per-case failure reasons — partial failure is always surfaced,
 * never silently swallowed.
 *
 * @param {{[key: string]: {status: string, reasons?: Array}}} results The per-case results map.
 * @return {{total: number, counts: {[key: string]: number}, failed: Array<{caseId: string, status: string, reasons: Array}>}}
 */
export function summarizeResults(results) {
	const map = results && typeof results === 'object' ? results : {}
	const counts = {}
	const failed = []

	for (const [caseId, entry] of Object.entries(map)) {
		const status = (entry && entry.status) || 'unknown'
		counts[status] = (counts[status] || 0) + 1
		if (status !== 'ready' && status !== 'succeeded') {
			failed.push({ caseId, status, reasons: (entry && entry.reasons) || [] })
		}
	}

	return { total: Object.keys(map).length, counts, failed }
}
