/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type's flow read as an ordered list: the trigger first, then every
 * status with its steps under it, numbered. The List view of the flow page
 * and the canvas read and write the same steps array, so this module only
 * derives rows from it and reorders it; it keeps no state of its own.
 */

/** The step kinds the board draws a pill for. A step without one is a Task. */
export const STEP_KINDS = ['task', 'message', 'action']

/** The trigger a case type's flow starts on when its template names none. */
export const DEFAULT_TRIGGER = 'Case created'

/**
 * The kind of one step: the kind it declares when it is one the board knows,
 * otherwise a task.
 *
 * @param {object} step A workflow step.
 * @return {string} One of STEP_KINDS.
 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
 */
export function stepKind(step) {
	const kind = String(step?.kind ?? '').toLowerCase()
	return STEP_KINDS.includes(kind) ? kind : 'task'
}

/**
 * Order rows by their `order` field, keeping the given order for ties and
 * for rows without one.
 *
 * @param {Array<object>} rows Statuses or steps.
 * @return {Array<object>} A sorted copy.
 */
function byOrder(rows) {
	return rows
		.map((row, index) => ({ row, index }))
		.sort((a, b) => {
			const left = Number(a.row?.order ?? Number.MAX_SAFE_INTEGER)
			const right = Number(b.row?.order ?? Number.MAX_SAFE_INTEGER)
			return left === right ? a.index - b.index : left - right
		})
		.map(({ row }) => row)
}

/**
 * The flow as the List view shows it: the trigger, then each status followed
 * by its steps, numbered from 1 across the whole list.
 *
 * @param {Array<object>} statuses The case type's statuses (`id`, `name`, `order`).
 * @param {Array<object>} steps The template's steps (`id`, `title`, `status`, `order`, optional `kind`).
 * @param {string|null} trigger The trigger the template names, if any.
 * @return {{trigger: string, rows: Array<{number: number, id: string, kind: string, label: string, statusId: string, first: boolean, last: boolean}>}} The list.
 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
 */
export function flowListRows(statuses = [], steps = [], trigger = null) {
	const rows = []
	for (const status of byOrder(statuses || [])) {
		rows.push({
			id: status.id,
			kind: 'status',
			label: status.name || '',
			statusId: status.id,
			first: true,
			last: true,
		})
		const own = byOrder((steps || []).filter((step) => step.status === status.id))
		own.forEach((step, index) => {
			rows.push({
				id: step.id,
				kind: stepKind(step),
				label: step.title || '',
				statusId: status.id,
				first: index === 0,
				last: index === own.length - 1,
			})
		})
	}
	return {
		trigger: trigger || DEFAULT_TRIGGER,
		rows: rows.map((row, index) => ({ number: index + 1, ...row })),
	}
}

/**
 * Move one step up or down among the steps of its own status, renumbering
 * that status's steps 1..n. Steps of other statuses are left as they are.
 *
 * @param {Array<object>} steps The template's steps.
 * @param {string} stepId The step to move.
 * @param {string} direction `up` or `down`.
 * @return {Array<object>|null} The new steps, or null when the step cannot move that way.
 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#scenario-a-change-in-the-list-is-the-same-change-on-the-canvas
 */
export function moveStepWithin(steps, stepId, direction) {
	const copy = (steps || []).map((step) => ({ ...step }))
	const moving = copy.find((step) => step.id === stepId)
	if (!moving) {
		return null
	}
	const siblings = byOrder(copy.filter((step) => step.status === moving.status))
	const from = siblings.indexOf(moving)
	const to = direction === 'up' ? from - 1 : from + 1
	if (direction !== 'up' && direction !== 'down') {
		return null
	}
	if (to < 0 || to >= siblings.length) {
		return null
	}
	siblings.splice(from, 1)
	siblings.splice(to, 0, moving)
	siblings.forEach((step, index) => {
		step.order = index + 1
	})
	return copy
}
