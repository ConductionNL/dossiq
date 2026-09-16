// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The end-of-day screen lists what you opened, and time is humaniq's.
 *
 * Two silent failures are held here and neither looks like one on screen.
 *
 * 🔴 "CHANGED TODAY" IS NOT "YOU TOUCHED IT". A list built from the object's
 * own updated timestamp shows a colleague's afternoon as the reader's, and it
 * looks exactly right: a plausible number of plausible cases. So the filter is
 * the register's per-reader read state, and the spec below fails if it ever
 * goes back to reading the object.
 *
 * 🔴 A DOSSIQ TIME FIELD WOULD LOOK LIKE THE HOURS LEAF. It would accept
 * numbers, show them back, and record hours nowhere humaniq can find them. So
 * there is no time field of dossiq's own, on any instance, and the screen
 * offers none at all when humaniq is absent.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	t: (app, text) => text,
}))

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')

const { HOURS_LEAF_ID, humaniqIsPresent, todayOf, touchedToday } =
	await import('../../src/utils/personalQueueHelpers.js')

const { notesUrlFor } = await import('../../src/utils/endOfDayHelpers.js')

const screen = readFileSync(
	resolve(root, 'src/views/queue/EndOfDayView.vue'),
	'utf8',
)

afterEach(() => {
	delete globalThis.OC
})

describe('what the reader touched today', () => {
	const items = [
		{ id: 'assigned-cases:case:a', subjectId: 'a', subjectType: 'case' },
		{ id: 'assigned-cases:case:b', subjectId: 'b', subjectType: 'case' },
		{ id: 'tasks:task:c', subjectId: 'c', subjectType: 'task' },
	]

	it('keeps the items this reader opened today', () => {
		const states = {
			'assigned-cases:case:a': { lastSeenAt: '2026-09-15T09:14:00+02:00' },
			'assigned-cases:case:b': { lastSeenAt: '2026-09-14T17:02:00+02:00' },
			'tasks:task:c': { lastSeenAt: '2026-09-15T16:40:00+02:00' },
		}

		expect(touchedToday(items, states, '2026-09-15').map((i) => i.id)).toEqual([
			'assigned-cases:case:a',
			'tasks:task:c',
		])
	})

	it('lists nothing the reader never opened', () => {
		// An item with no read state is NOT listed. The alternative reads as
		// "you worked on this today" about something nobody opened.
		expect(touchedToday(items, {}, '2026-09-15')).toEqual([])
	})

	it('survives a read state that answered nothing', () => {
		expect(touchedToday(items, null, '2026-09-15')).toEqual([])
		expect(touchedToday(null, {}, '2026-09-15')).toEqual([])
	})

	it('reads today in the format the read state answers in', () => {
		expect(todayOf(new Date(2026, 8, 5))).toBe('2026-09-05')
	})

	it('asks the register for the read state rather than keeping one', () => {
		expect(screen).toContain('fetchReadState')
		expect(screen).toContain('touchedToday')
		expect(screen).not.toContain('lastModified')
	})
})

describe('time goes to humaniq, or nowhere', () => {
	it('knows humaniq by its web root', () => {
		expect(humaniqIsPresent()).toBe(false)

		globalThis.OC = { appswebroots: { humaniq: '/custom_apps/humaniq' } }
		expect(humaniqIsPresent()).toBe(true)
	})

	it('places the leaf humaniq owns, by its id', () => {
		expect(HOURS_LEAF_ID).toBe('humaniq-hours')
		expect(screen).toContain('leafTab(HOURS_LEAF_ID)')
	})

	it('offers no time field of its own', () => {
		// Not a search for the word "hours": the screen legitimately names
		// humaniq's leaf. What must not exist is a field dossiq would store.
		expect(screen).not.toMatch(/v-model="[^"]*hours/i)
		expect(screen).not.toMatch(/saveHours|recordHours|postHours/)
	})

	it('shows the time box only when the leaf is really there', () => {
		expect(screen).toContain('hoursLeafAvailable')
		expect(screen).toContain(
			'this.humaniqPresent && this.hoursLeaf !== undefined',
		)
	})

	it('says so when humaniq is present and the leaf is not', () => {
		// A present app whose leaf never registered is the dark-surface case:
		// without this line the reader sees an empty space and no reason.
		expect(screen).toContain(
			'The hours leaf is not available, so time cannot be recorded here.',
		)
	})
})

describe('an update is recorded on the thing it is about', () => {
	it('writes a case update onto the case', () => {
		expect(notesUrlFor({ subjectType: 'case', subjectId: 'case-1' })).toContain(
			'/apps/openregister/api/objects/dossiq/case/case-1/notes',
		)
	})

	it('writes a task update onto the task, which is not an object', () => {
		expect(notesUrlFor({ subjectType: 'task', subjectId: 'task-1' })).toContain(
			'/apps/openregister/api/flow-tasks/task-1/notes',
		)
	})

	it('writes nothing for a subject with no notes', () => {
		expect(notesUrlFor({ subjectType: 'mention', subjectId: 'x' })).toBeNull()
		expect(notesUrlFor({ subjectType: 'case', subjectId: '' })).toBeNull()
	})
})
