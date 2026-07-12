/**
 * Workflow-board helper utilities.
 *
 * Pure logic extracted from CaseCard.vue so the keyboard "Move to…" menu's
 * target-column resolution is unit-testable without mounting a component.
 *
 * @spec openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
 */

/**
 * Resolve the set of status columns a case card can move to: every board
 * column except the one the case is currently in.
 *
 * @param {Array<{id: string, name: string}>} columns All board columns (status types).
 * @param {string} currentStatusId The case's current status id.
 * @return {Array<{id: string, name: string}>} Columns other than the current one.
 */
export function columnsExcludingCurrent(columns, currentStatusId) {
	if (!Array.isArray(columns)) return []
	return columns.filter(col => String(col.id) !== String(currentStatusId))
}
