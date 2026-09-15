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
 * @spec openspec/specs/task-management/spec.md
 */

import { TERMINAL_STATES } from '../store/modules/engineTask.js'

/**
 * The states that take a task out of the pane and put the next one in its
 * place.
 *
 * RE-EXPORTED from the engine store rather than declared here. These used to
 * be read off `caseTask`'s own `x-openregister-lifecycle.final`, and they are
 * the SAME three the engine calls terminal, which is exactly why a second
 * copy is a liability: it can drift, and nothing would say so. The name is
 * kept so the existing callers and their tests do not churn.
 *
 * @type {string[]}
 */
export const FINAL_TASK_STATUSES = TERMINAL_STATES

/**
 * The id of an OpenRegister row, in either shape the store returns it.
 *
 * @param {object|null|undefined} row The object row.
 * @return {string} The id, or an empty string when unreadable.
 * @spec openspec/specs/task-management/spec.md
 */
export function taskIdOf(row) {
	if (!row || typeof row !== 'object') {
		return ''
	}
	// `uuid` first: an engine task is keyed by uuid, and its numeric `id` is
	// a database primary key that no route or verb accepts. The register
	// shapes are kept so a row read before the cutover still resolves.
	return String(row.uuid ?? row.id ?? row['@self']?.id ?? '').trim()
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
 * @spec openspec/specs/task-management/spec.md
 */
export function isFinalStatus(to) {
	return FINAL_TASK_STATUSES.includes(String(to ?? '').trim())
}

/**
 * The query that fetches the open tasks of one case, earliest due first.
 *
 * The open/closed split is still made SERVER-side, by the engine's inbox
 * rather than by `caseTask`'s materialised `isTerminalStatus`. Filtering
 * client-side over a paged window would silently drop every open task past
 * the page boundary and show an empty pane on a case that has work left.
 *
 * @param {string} objectId The case id.
 * @param {object} [content] The widget content blob (`limit` is honoured).
 * @return {object} Params for `useEngineTaskStore().list(…)`.
 * @spec openspec/specs/task-management/spec.md
 */
export function openTasksQuery(objectId, content = {}) {
	const limit = Number(content?.limit)
	return {
		// The case IS the object: the engine stores `objectUuid` and has no
		// typed case reference, because OpenRegister has no case entity.
		objectUuid: String(objectId ?? ''),
		// `all`, not the caller's assigned set. The case page shows the
		// case's work, not the reader's, and scoping to the reader hides a
		// colleague's task and makes the case look finished when it is not.
		scope: 'all',
		sort: 'dueAt',
		limit: Number.isFinite(limit) && limit > 0 ? limit : 25,
	}
}

/**
 * The route to a task's detail page, by manifest page id.
 *
 * @param {object} row The task row.
 * @param {object} [content] The widget content blob (`rowRoute`).
 * @return {{name: string, params: {id: string}}|null} The route, or null.
 * @spec openspec/specs/task-management/spec.md
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
 * @spec openspec/specs/task-management/spec.md
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

/**
 * The number a task is referred to by, or nothing.
 *
 * 🔴 IT IS NOT INVENTED. The task engine has no number of its own: dossiq
 * reads one where the engine grows one, shows the engine's identifier where it
 * has not, and generates nothing. A dossiq-side task number would be the
 * schema nobody writes to that `remove-casetask` spent a change deleting, and
 * a made-up number is worse than none — somebody quotes it in an email and the
 * engine has never heard of it.
 *
 * @param {object|null|undefined} row The task row.
 * @return {string} The number, or an empty string when the engine answers none.
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
export function taskNumberOf(row) {
	if (!row || typeof row !== 'object') {
		return ''
	}
	return String(row.number ?? row.taskNumber ?? '').trim()
}

/**
 * How a task is referred to on screen: its number, or the engine's identifier.
 *
 * @param {object|null|undefined} row The task row.
 * @return {{value: string, isNumber: boolean}} What to show, and whether it is a number.
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
export function taskReferenceOf(row) {
	const number = taskNumberOf(row)
	if (number !== '') {
		return { value: number, isNumber: true }
	}
	return { value: taskIdOf(row), isNumber: false }
}

/**
 * Whether a task is locked, when the engine says at all.
 *
 * Three answers, not two. `true` and `false` are the engine answering; `null`
 * is the engine having no lock to answer about, which a surface states rather
 * than rendering as "not locked". An unlocked task and a task whose lock
 * nobody tracks are different facts, and only the second means a colleague can
 * be editing it right now with nothing to say so.
 *
 * @param {object|null|undefined} row The task row.
 * @return {boolean|null} Locked, not locked, or not answered.
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
export function taskLockOf(row) {
	if (!row || typeof row !== 'object') {
		return null
	}
	const lock = row.locked ?? row.lockedBy ?? row.lock ?? null
	if (lock === null || lock === undefined || lock === '') {
		return null
	}
	if (typeof lock === 'boolean') {
		return lock
	}
	return true
}

/**
 * Whether this task is waiting for somebody to take it.
 *
 * A task with candidates and no assignee is offered to a team. A task with no
 * assignee and no candidates is simply unassigned, which is not the same
 * thing: nobody was asked.
 *
 * @param {object|null|undefined} row The task row.
 * @return {boolean} True when it is claimable in principle.
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
export function isUnclaimed(row) {
	if (!row || typeof row !== 'object') {
		return false
	}
	if (String(row.assignee ?? '').trim() !== '') {
		return false
	}
	return candidatesOf(row).length > 0
}

/**
 * Who a task is offered to, groups and users together.
 *
 * @param {object|null|undefined} row The task row.
 * @return {string[]} The candidate names.
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
export function candidatesOf(row) {
	const groups = Array.isArray(row?.candidateGroups) ? row.candidateGroups : []
	const users = Array.isArray(row?.candidateUsers) ? row.candidateUsers : []
	return [...groups, ...users]
		.map((name) => String(name ?? '').trim())
		.filter((name) => name !== '')
}

/**
 * The form a task carries, as the engine describes it, or null.
 *
 * The engine answers `{kind, state, fields: [{field, required, renderable,
 * reason}]}` on a task read. A form whose state is not `ready` is shown with
 * its reason rather than rendered: a broken field list completed anyway is a
 * verslag with holes in it that reports success.
 *
 * @param {object|null|undefined} row The task row.
 * @return {object|null} The form, or null when the task carries none.
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
export function taskFormOf(row) {
	const form = row?.form
	if (!form || typeof form !== 'object' || !form.kind) {
		return null
	}
	return form
}

/**
 * The fields of a task's form that must be answered.
 *
 * @param {object|null|undefined} row The task row.
 * @return {string[]} The required field names, in declared order.
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
export function requiredFieldsOf(row) {
	const form = taskFormOf(row)
	if (!form || !Array.isArray(form.fields)) {
		return []
	}
	return form.fields
		.filter((field) => field?.required === true)
		.map((field) => String(field?.field ?? '').trim())
		.filter((name) => name !== '')
}

/**
 * The first required field this answer set leaves empty, or ''.
 *
 * The same rule the server applies, so the pane can name the field before the
 * round trip and the two never disagree about what "empty" means. `0` and
 * `false` are answers: a required amount answered with zero is answered.
 *
 * @param {object|null|undefined} row The task row.
 * @param {object} answers The answers, keyed by field.
 * @return {string} The field's name, or '' when nothing required is missing.
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
export function missingRequiredField(row, answers = {}) {
	for (const field of requiredFieldsOf(row)) {
		const answer = answers?.[field]
		const blank =
			answer === undefined ||
			answer === null ||
			(typeof answer === 'string' && answer.trim() === '') ||
			(Array.isArray(answer) && answer.length === 0)
		if (blank) {
			return field
		}
	}
	return ''
}
