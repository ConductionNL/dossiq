/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * One reading of an engine task row, for every widget that shows tasks.
 *
 * 🔴 THE ENGINE DOES NOT ANSWER IN `caseTask`'s NAMES, AND NOTHING MAPS THEM.
 * `GET /apps/openregister/api/flow-tasks` serialises `Task::jsonSerialize()`:
 * the identity is `uuid`, the state is `state`, the deadline is `dueAt` and
 * the case is `objectUuid`. There is no `dueDate`, no `status` and no `case`
 * key anywhere in the response, and `useEngineTaskStore` hands the rows back
 * exactly as they arrive. Measured against the live API on 2026-09-10: fifty
 * rows, none of them carrying any of those three keys.
 *
 * A widget that reads the old names therefore reads `undefined` and renders
 * an empty cell rather than failing, which is why this shaping is in one
 * place and not repeated per widget. `MyTasksWidget` had exactly that defect:
 * it read `task.id` (the engine's NUMERIC row id, not its uuid) into a task
 * deep link and `task.dueDate` (absent) into the deadline line, so every row
 * linked to a url no route accepts and every row said "No deadline".
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/signalering-widgets/spec.md
 */

/**
 * The signed distance to a task's deadline, in whole days.
 *
 * The engine reports the two directions in two fields and never both:
 * `daysUntilDue` counts down and is null once the deadline has passed,
 * `daysOverdue` counts up and is null before it. A single signed number is
 * what a "days left" column reads, with a task already past due carrying a
 * negative one, so the two are folded here rather than in each widget.
 *
 * @param {object} task The engine row.
 * @return {number|null} Days left, negative when overdue, null with no deadline.
 * @spec openspec/specs/dashboard/spec.md
 */
export function signedDaysUntilDue(task) {
	const row = task && typeof task === 'object' ? task : {}

	if (typeof row.daysOverdue === 'number') {
		// `-0` is a number a template prints as "0" but a `< 0` test rejects,
		// so a task overdue by less than a day stays a plain zero.
		return row.daysOverdue === 0 ? 0 : -row.daysOverdue
	}

	if (typeof row.daysUntilDue === 'number') {
		return row.daysUntilDue
	}

	return null
}

/**
 * One engine task row in the names dossiq's task widgets read.
 *
 * `caseTitle` comes from the engine's own `subject` block, which is the case
 * the task hangs off resolved to `{ uuid, register, schema, title }` by
 * `TaskInboxService::row()`. It is null on every row the live instance
 * answers today (fifty of fifty on 2026-09-10) because OpenRegister's
 * `TaskInboxService` takes its object mapper as an OPTIONAL constructor
 * argument and receives null, so `subjectContexts()` returns nothing and no
 * error. Read here anyway: the shaping is what has to be right the day that
 * is fixed, and a widget reading it directly would have to be found again.
 *
 * @param {object} task The engine row, as `/api/flow-tasks` answers it.
 * @return {{id: string, title: string, caseId: string, caseTitle: string, dueDate: string|null, daysUntilDue: number|null}} The shaped row.
 * @spec openspec/specs/dashboard/spec.md
 */
export function shapeEngineTask(task) {
	const row = task && typeof task === 'object' ? task : {}
	const subject = row.subject && typeof row.subject === 'object' ? row.subject : {}

	return {
		id: String(row.uuid ?? ''),
		title: String(row.title ?? ''),
		caseId: String(row.objectUuid ?? ''),
		caseTitle: String(subject.title ?? ''),
		dueDate: row.dueAt ?? null,
		daysUntilDue: signedDaysUntilDue(row),
	}
}
