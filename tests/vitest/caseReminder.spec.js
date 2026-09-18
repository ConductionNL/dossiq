/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A reminder you set for a colleague from a case.
 *
 * The reminder is an engine task and nothing about it is special, which is
 * exactly why it is worth pinning. Every rule here fails SILENTLY when it
 * breaks: an `open-modal` action whose target is not a `kind: "modal"`
 * registry entry opens nothing and logs one console warning; an icon that is
 * not in `src/icons.js` renders no glyph rather than a fallback; a payload key
 * the engine store does not map is dropped on the way out with no error; and
 * a `kind` spelled differently in the dialog than in the Tasks facet still
 * creates, assigns and notifies perfectly, and is simply missing from the one
 * lens that exists to find it.
 *
 * @spec openspec/changes/case-reminder-as-task/specs/task-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	defaultReminderDate,
	isReminderComplete,
	REMINDER_KIND,
	reminderPayload,
} from '../../src/utils/reminderHelpers.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const registrySource = fs.readFileSync(
	path.join(ROOT, 'src', 'registry.js'),
	'utf8',
)
const storeSource = fs.readFileSync(
	path.join(ROOT, 'src', 'store', 'modules', 'engineTask.js'),
	'utf8',
)
const dialogSource = fs.readFileSync(
	path.join(ROOT, 'src', 'dialogs', 'RemindDialog.vue'),
	'utf8',
)

/**
 * One page of the manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	return manifest.pages.find((entry) => entry.id === id)
}

/**
 * One header action of the CaseDetail page.
 *
 * @param {string} id The action id.
 * @return {object|undefined} The action.
 */
function headerAction(id) {
	return page('CaseDetail').config.headerActions.find(
		(action) => action.id === id,
	)
}

describe('the Remind action', () => {
	it('is a header action on the case page', () => {
		expect(headerAction('case-remind')).toBeTruthy()
	})

	it('opens a modal the registry declares, so the click does something', () => {
		const action = headerAction('case-remind')
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('RemindDialog')
		expect(registrySource).toContain('\n\tRemindDialog: {')
		expect(
			registrySource
				.slice(registrySource.indexOf('\n\tRemindDialog: {'))
				.split('\n\t},')[0],
		).toContain("kind: 'modal'")
	})

	it('names an icon that is registered, so a glyph renders', () => {
		const icon = headerAction('case-remind').icon
		expect(iconsSource).toContain(
			`import ${icon} from 'vue-material-design-icons/${icon}.vue'`,
		)
		expect(new RegExp(`^\\t${icon},$`, 'm').test(iconsSource)).toBe(true)
	})

	it('passes no props, because open-modal forwards them unresolved', () => {
		expect(headerAction('case-remind').props).toBeUndefined()
		// So the dialog must read the case from the route instead.
		expect(dialogSource).toContain('this.$route?.params?.id')
	})
})

describe('what a reminder may be', () => {
	it('opens on tomorrow, never today', () => {
		expect(defaultReminderDate(new Date('2026-09-18T09:00:00Z'))).toBe(
			'2026-09-19',
		)
		// Across a month boundary, which is where a naive +1 on the day number
		// produces the 31st of September.
		expect(defaultReminderDate(new Date('2026-09-30T23:00:00Z'))).toBe(
			'2026-10-01',
		)
	})

	it('needs all three of who, when and what', () => {
		const earliest = '2026-09-19'
		const complete = {
			assignee: 'anna',
			date: '2026-09-19',
			title: 'Call the applicant',
		}

		expect(isReminderComplete(complete, earliest)).toBe(true)
		expect(isReminderComplete({ ...complete, assignee: '  ' }, earliest)).toBe(
			false,
		)
		expect(isReminderComplete({ ...complete, date: '' }, earliest)).toBe(false)
		expect(isReminderComplete({ ...complete, title: '   ' }, earliest)).toBe(
			false,
		)
	})

	it('refuses a date before the earliest, which would be born overdue', () => {
		expect(
			isReminderComplete(
				{ assignee: 'anna', date: '2026-09-17', title: 'Call' },
				'2026-09-19',
			),
		).toBe(false)
	})
})

describe('the payload the engine store receives', () => {
	const built = reminderPayload(
		{ assignee: ' anna ', date: '2026-10-03', title: ' Call the applicant ' },
		'case-1',
	)

	it('carries who, when, what, the case and the kind', () => {
		expect(built).toEqual({
			title: 'Call the applicant',
			assignee: 'anna',
			dueDate: '2026-10-03T23:59:59+00:00',
			case: 'case-1',
			kind: 'reminder',
		})
	})

	it('is due at the END of the chosen day', () => {
		// Midnight would make the reminder overdue for the whole of the one day
		// it was meant to be a reminder.
		expect(built.dueDate.startsWith('2026-10-03T23:59:59')).toBe(true)
	})

	it('spells every key the way the engine store maps it', () => {
		// The store maps the dossiq spelling onto the engine's: `case` becomes
		// `objectUuid`, `dueDate` becomes `dueAt`, and `kind` travels
		// unchanged. A payload written in the engine's own words is dropped by
		// that mapping without a word, so the spellings are pinned against the
		// store itself rather than against a copy of them.
		expect(storeSource).toContain("['dueDate', 'dueAt'],")
		expect(storeSource).toContain("['assignee', 'assignee'],")
		expect(storeSource).toContain("['kind', 'kind'],")
		expect(storeSource).toContain('task.case ?? task.objectUuid')
		// `title` is not in that loop: the store reads it directly, which is
		// the one key that is never absent.
		expect(storeSource).toContain('title: String(task.title')
	})
})

describe('the kind is one spelling, read by three surfaces', () => {
	it('is what the dialog writes', () => {
		expect(REMINDER_KIND).toBe('reminder')
		expect(reminderPayload({}, 'case-1').kind).toBe(REMINDER_KIND)
	})

	it('is what the Tasks sidebar offers', () => {
		const field = page('Tasks').config.sidebar.fields.kind
		expect(field).toBeTruthy()
		expect(field.facetable).toBe(true)
		expect(field.enum).toContain(REMINDER_KIND)
		expect(field.enumLabels[REMINDER_KIND]).toBe('Reminder')
	})

	it('keeps the sidebar orders distinct, so the facets do not stack', () => {
		const fields = page('Tasks').config.sidebar.fields
		const orders = Object.values(fields).map((entry) => entry.order)
		expect(new Set(orders).size).toBe(orders.length)
	})
})
