// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Pure logic behind CaseTaskPane.vue (task-on-the-case), extracted so the
 * node-environment vitest suite can exercise it without a DOM — the same
 * split `flowTaskHelpers.js` uses for TaskWaitingCaseSection.
 *
 * The pane's job is to put the next open task of a case, and its lifecycle
 * buttons, on the case page. Which task is "next", which transition ends a
 * task, and where the remaining rows link to are all decisions that can be
 * made from data alone, so they live here.
 *
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */

/**
 * The statuses `caseTask`'s lifecycle declares final
 * (`lib/Settings/dossiq_register.json`,
 * `configuration.x-openregister-lifecycle.final`). Reaching one of these is
 * what takes a task out of the pane and puts the next one in its place.
 *
 * @type {string[]}
 */
export const FINAL_TASK_STATUSES = Object.freeze([
	'completed',
	'terminated',
	'disabled',
])

/**
 * The id of an OpenRegister row, in either shape the store returns it.
 *
 * @param {object|null|undefined} row The object row.
 * @return {string} The id, or an empty string when unreadable.
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */
export function taskIdOf(row) {
	if (!row || typeof row !== 'object') {
		return ''
	}
	return String(row.id ?? row['@self']?.id ?? '').trim()
}

/**
 * Whether a transition's target status ends the task.
 *
 * A transition to a non-final status (`activate`) leaves the task in the
 * pane with its new buttons; a final one advances the pane to the next open
 * task and is the one worth confirming with a toast.
 *
 * @param {string|null|undefined} to The transition's target status.
 * @return {boolean} True when the target status is terminal.
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */
export function isFinalStatus(to) {
	return FINAL_TASK_STATUSES.includes(String(to ?? '').trim())
}

/**
 * The query that fetches the open tasks of one case, earliest due first.
 *
 * `isTerminalStatus` is a materialised calculation on `caseTask`, so the
 * open/closed split is made SERVER-side. Filtering client-side over a paged
 * window would silently drop every open task past the page boundary and show
 * an empty pane on a case that has work left.
 *
 * @param {string} objectId The case id.
 * @param {object} [content] The widget content blob (`limit` is honoured).
 * @return {object} Params for `useObjectStore().fetchCollection('caseTask', …)`.
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */
export function openTasksQuery(objectId, content = {}) {
	const limit = Number(content?.limit)
	return {
		case: String(objectId ?? ''),
		isTerminalStatus: false,
		_order: { dueDate: 'asc' },
		_limit: Number.isFinite(limit) && limit > 0 ? limit : 25,
	}
}

/**
 * The route to a task's detail page, by manifest page id.
 *
 * @param {object} row The task row.
 * @param {object} [content] The widget content blob (`rowRoute`).
 * @return {{name: string, params: {id: string}}|null} The route, or null.
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */
export function taskRouteFor(row, content = {}) {
	const name = String(content?.rowRoute ?? '').trim()
	const id = taskIdOf(row)
	if (name === '' || id === '') {
		return null
	}
	return { name, params: { id } }
}

/**
 * The "View all" route, with `@objectId` in `viewAllQuery` resolved to the
 * case the pane is on — the same token CnObjectListWidget resolves, so the
 * footer keeps leading to the Tasks list filtered on this case.
 *
 * @param {string} objectId The case id.
 * @param {object} [content] The widget content blob.
 * @return {{name: string, query: object}|null} The route, or null.
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */
export function viewAllRouteFor(objectId, content = {}) {
	const name = String(content?.viewAllRoute ?? '').trim()
	if (name === '') {
		return null
	}

	const source =
		content?.viewAllQuery && typeof content.viewAllQuery === 'object'
			? content.viewAllQuery
			: {}
	const query = {}
	for (const [key, value] of Object.entries(source)) {
		query[key] = value === '@objectId' ? String(objectId ?? '') : value
	}
	return { name, query }
}
