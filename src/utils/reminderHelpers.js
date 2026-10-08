/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a reminder is, without a component around it.
 *
 * A reminder is an engine task with three things somebody typed: who it is
 * for, when it is due, and what it says. Everything that decides whether the
 * form may be sent and what goes over the wire lives here, so it can be
 * tested without mounting a dialog and so the payload can be read in one
 * place rather than inferred from a template.
 *
 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
 */

/**
 * The kind every reminder carries.
 *
 * ONE SPELLING, NAMED ONCE. The dialog writes it, the Tasks sidebar facets on
 * it, and the e2e asks the engine for it. Three literals that must agree and
 * would fail silently if they drifted: the engine attaches no behaviour to a
 * kind, so a reminder written as `Reminder` would be created, assigned and
 * notified exactly as it should, and be missing from the one lens that exists
 * to find it.
 *
 * @type {string}
 */
export const REMINDER_KIND = 'reminder'

/**
 * The date a reminder opens on: tomorrow.
 *
 * A reminder for today is a reminder you are already reading, so it says
 * nothing you did not just decide. Tomorrow is the first date the gesture
 * means something, and it is also the earliest the form accepts.
 *
 * @param {Date} [now] The current moment, injectable for tests.
 * @return {string} The date as `YYYY-MM-DD`.
 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
 */
export function defaultReminderDate(now = new Date()) {
	const next = new Date(now.getTime())
	next.setDate(next.getDate() + 1)
	return next.toISOString().slice(0, 10)
}

/**
 * Whether the reminder may be sent.
 *
 * All three fields are required, and none of them has a sensible default the
 * form could supply on the person's behalf. A reminder with no assignee would
 * be a task nobody is told about, one with no date would never come up in a
 * due window, and one with no title would arrive as a notification that says
 * nothing. Each of those saves without complaint and then fails to remind
 * anybody, which is the shape this check exists to refuse.
 *
 * A date in the past is refused for the same reason: the engine would accept
 * it and the task would be born overdue.
 *
 * @param {object} form The form state, `{assignee, date, title}`.
 * @param {string} earliest The earliest allowed date, as `YYYY-MM-DD`.
 * @return {boolean} True when the reminder may be sent.
 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
 */
export function isReminderComplete(form, earliest) {
	const assignee = String(form?.assignee ?? '').trim()
	const date = String(form?.date ?? '').trim()
	const title = String(form?.title ?? '').trim()

	if (assignee === '' || date === '' || title === '') {
		return false
	}

	return date >= String(earliest ?? '')
}

/**
 * The reminder as the engine store reads it.
 *
 * The keys are the dossiq-shaped ones `useEngineTaskStore.create()` maps onto
 * the engine's own: `case` becomes `objectUuid`, `dueDate` becomes `dueAt`,
 * and `kind` travels unchanged. Writing the engine's spelling here instead
 * would be dropped by that mapping without a word.
 *
 * The due date is sent as the END of the chosen day. A reminder for the 3rd
 * that is due at midnight is overdue for the whole of the 3rd, which is the
 * one day it was supposed to be a reminder.
 *
 * @param {object} form The form state, `{assignee, date, title}`.
 * @param {string} caseId The case the reminder is about.
 * @return {object} The task in the store's shape.
 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
 */
export function reminderPayload(form, caseId) {
	return {
		title: String(form?.title ?? '').trim(),
		assignee: String(form?.assignee ?? '').trim(),
		dueDate: `${String(form?.date ?? '').trim()}T23:59:59+00:00`,
		case: String(caseId ?? '').trim(),
		kind: REMINDER_KIND,
	}
}
