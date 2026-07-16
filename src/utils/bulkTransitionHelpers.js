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
	const current = selection && typeof selection === 'object' ? selection : emptySelection()

	if (current.columnId !== columnId) {
		// Selecting in a new/different column resets the selection.
		return { columnId, caseIds: [caseId] }
	}

	const caseIds = Array.isArray(current.caseIds) ? current.caseIds : []
	const exists = caseIds.some(id => String(id) === String(caseId))
	const nextCaseIds = exists
		? caseIds.filter(id => String(id) !== String(caseId))
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
	return selection.caseIds.some(id => String(id) === String(caseId))
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
	const caseIds = selection && Array.isArray(selection.caseIds) ? [...selection.caseIds] : []
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
	const caseIds = selection && Array.isArray(selection.caseIds) ? [...selection.caseIds] : []
	return { caseIds, transitionId: transitionId || '', comment: comment || null }
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
