// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Narrow the workflow board to one case type.
 *
 * The board merges every non-final status of every case type into one column
 * per status name, so an instance with twenty case types draws twenty-odd
 * columns, most of them empty for the handler looking at it. The design
 * (DqWerkbord) draws one case type at a time: the four statuses of that type,
 * and only its cases. This narrows the merged board to that view without
 * touching the merged data the drag, drop and move paths keep working on.
 *
 * @param {object} board The merged board and the case type to narrow it to.
 * @param {Array<{id: string, name: string}>} board.columns The merged columns.
 * @param {{[key: string]: Array<object>}} board.casesByStatus Cases per column id.
 * @param {Array<object>} board.statusTypes Every status type on the instance
 *   (`{ id, name, caseType, isFinal }`).
 * @param {string} board.caseType The case type id to narrow to, or empty for all.
 * @return {{columns: Array<object>, casesByStatus: {[key: string]: Array<object>}}}
 *   The columns and cases to draw. With an empty case type, the input as given.
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-027
 */
export function narrowBoard({ columns, casesByStatus, statusTypes, caseType }) {
	if (!caseType) {
		return { columns, casesByStatus }
	}
	const isFinal = (st) => st?.isFinal === true || st?.isFinal === 'true'
	const names = new Set(
		(statusTypes || [])
			.filter(
				(st) => String(st?.caseType) === String(caseType) && !isFinal(st),
			)
			.map((st) => st.name || ''),
	)
	const kept = (columns || []).filter((col) => names.has(col.name))
	const narrowed = {}
	for (const col of kept) {
		narrowed[col.id] = (casesByStatus?.[col.id] || []).filter(
			(c) => String(c?.caseType) === String(caseType),
		)
	}
	return { columns: kept, casesByStatus: narrowed }
}

/**
 * Put a column's reordered list back into the merged board while a case type
 * filter is on: the cases of the filtered type take the new order, the cases
 * of every other type stay in the column, after them, as they were.
 *
 * Without this a drop on a narrowed column would write the narrowed list back
 * and lose every case of another type that shared the column.
 *
 * @param {Array<object>} merged The column's full list.
 * @param {Array<object>} visible The column's list as drawn and reordered.
 * @param {string} caseType The case type the board is narrowed to, or empty.
 * @return {Array<object>} The column's new full list.
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-027
 */
export function mergeColumnBack(merged, visible, caseType) {
	if (!caseType) {
		return visible
	}
	const visibleIds = new Set((visible || []).map((c) => String(c?.id)))
	const others = (merged || []).filter(
		(c) =>
			String(c?.caseType) !== String(caseType)
			&& !visibleIds.has(String(c?.id)),
	)
	return [...(visible || []), ...others]
}
