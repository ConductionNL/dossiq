// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Pure logic behind TaskWaitingCaseSection.vue, extracted so the node-env
 * vitest suite can exercise it (dossiq's vitest project mounts no Vue
 * components — see tests/vitest/caseListExportAction.spec.js for the
 * pattern and the reason).
 *
 * A task is "holding up a case" only when a flow stamped it with the run it
 * blocks. An ordinary to-do also names a case, but nothing waits on it, so
 * saying "the case is waiting" there would be untrue. The distinction is the
 * `flowRun` field: DossiqAskPersonNode writes it, nothing else does.
 */

/**
 * The id of the case this task is holding up, or null.
 *
 * Null for every task no run is waiting on: a task without a flowRun, a flow
 * task whose case reference is missing, or a non-object input. Callers render
 * NOTHING on null, which is what keeps non-flow tasks looking exactly as they
 * did before this feature existed.
 *
 * The case reference is a relation and may arrive as a bare id string or as
 * an expanded object; both shapes are read.
 *
 * @param {object|null|undefined} task The task object as the store returns it.
 * @return {string|null} The case id, or null when no case is waiting.
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */
export function waitingCaseIdFrom(task) {
	if (!task || typeof task !== 'object') {
		return null
	}

	// `runUuid` is the engine's name for it; `flowRun` was caseTask's. Both
	// are read so a row from either store resolves during the cutover.
	const run = String(task.runUuid ?? task.flowRun ?? '').trim()
	if (run === '') {
		return null
	}

	return caseIdFrom(taskCaseRef(task))
}

/**
 * The case a task names, whichever store it came from.
 *
 * An engine task carries `objectUuid`, because the case IS the object and
 * OpenRegister has no case entity. `caseTask` carried a typed `case` $ref.
 * Reading only one of them makes every task on the other store look like a
 * task with no case, which renders as nothing at all rather than as an
 * error.
 *
 * @param {object} task The task row.
 * @return {string|object|null} The case reference.
 * @spec openspec/changes/remove-casetask/tasks.md
 */
export function taskCaseRef(task) {
	if (!task || typeof task !== 'object') {
		return null
	}

	return task.objectUuid ?? task.case ?? null
}

/**
 * Read a case reference in either of the shapes the store returns.
 *
 * Exported since task-on-the-case: TaskCaseCard asks the same question of
 * the same field for a different reason (which case is this task ON, rather
 * than which run is waiting on me), and a second reader of `$ref` shapes is
 * exactly the copy that drifts.
 *
 * @param {string|object|null|undefined} ref The task's case reference.
 * @return {string|null} The case id, or null when unreadable.
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */
export function caseIdFrom(ref) {
	if (typeof ref === 'string') {
		const id = ref.trim()
		return id === '' ? null : id
	}

	if (ref && typeof ref === 'object') {
		const id = String(ref.id ?? ref.uuid ?? ref['@self']?.id ?? '').trim()
		return id === '' ? null : id
	}

	return null
}

/**
 * The in-app route to the waiting case's detail page.
 *
 * @param {string} caseId The case id.
 * @return {string} The vue-router path for the manifest CaseDetail page.
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */
export function caseRouteFor(caseId) {
	return `/cases/${encodeURIComponent(caseId)}`
}
