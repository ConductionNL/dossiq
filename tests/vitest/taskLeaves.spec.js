// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The three task-anchored leaves (remove-casetask 2.1).
 *
 * Notes, appointments and history each moved from an OBJECT-anchored library
 * widget to the task's own endpoint. The move is only correct if three
 * things hold, and none of them is visible in a screenshot:
 *
 *   - the leaf reads the TASK endpoint, not an object one. An engine task
 *     has no register, no schema and no object id, so an object-anchored
 *     read answers nothing AND renders the widget's own empty state. That
 *     is what the page did before, for every task, with no error;
 *   - a leaf that FAILED says so. An unreadable list and an empty one look
 *     identical, and that is the failure shape this whole migration keeps
 *     producing;
 *   - the leaf reads the endpoint's OWN key names. A note carries `message`
 *     and `actorDisplayName`; an event carries `summary`, `dtstart` and
 *     `dtend`. Reading `content` or `title` renders a blank row and looks
 *     like missing data.
 *
 * @spec openspec/specs/task-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => ({}),
}))

/** What `readLeaf()` answers with, keyed by leaf name. Replaced per test. */
let leaves = {}
/** Every `readLeaf()` call, so the endpoint can be asserted. */
let reads = []
/** Every write, and what it answered with. */
let writes = []
let writeResult = { note: { id: 7, message: 'ok' }, error: null }
let removeResult = { removed: true, error: null }

const storeStub = {
	async readLeaf(uuid, leaf) {
		reads.push({ uuid, leaf })
		return leaves[leaf] ?? { results: [], error: null }
	},
	async writeNote(uuid, message) {
		writes.push({ kind: 'write', uuid, message })
		return writeResult
	},
	async removeNote(uuid, noteId) {
		writes.push({ kind: 'remove', uuid, noteId })
		return removeResult
	},
}

vi.mock('../../src/store/modules/engineTask.js', async (importOriginal) => ({
	...(await importOriginal()),
	useEngineTaskStore: () => storeStub,
}))

const { default: TaskNotesLeaf } = await import(
	'../../src/components/tasks/TaskNotesLeaf.vue'
)
const { default: TaskEventsLeaf } = await import(
	'../../src/components/tasks/TaskEventsLeaf.vue'
)
const { default: TaskAuditLeaf } = await import(
	'../../src/components/tasks/TaskAuditLeaf.vue'
)

/**
 * Mount one leaf over the scripted endpoint answers.
 *
 * @param {object} component The leaf component.
 * @return {Promise<object>} The mounted wrapper, after its load settles.
 */
async function mountLeaf(component) {
	const wrapper = mount(component, { props: { taskId: 'task-1' } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	leaves = {}
	reads = []
	writes = []
	writeResult = { note: { id: 7, message: 'ok' }, error: null }
	removeResult = { removed: true, error: null }
})

describe('TaskNotesLeaf', () => {
	it('reads the task notes endpoint, not an object one', async () => {
		await mountLeaf(TaskNotesLeaf)

		expect(reads).toEqual([{ uuid: 'task-1', leaf: 'notes' }])
	})

	it("renders a note by the endpoint's own key names", async () => {
		leaves.notes = {
			results: [
				{
					id: 3,
					message: 'Called the applicant, no answer.',
					actorDisplayName: 'Henk Bakker',
					createdAt: '2026-09-09T10:00:00+00:00',
					isCurrentUser: false,
				},
			],
			error: null,
		}
		const wrapper = await mountLeaf(TaskNotesLeaf)

		const note = wrapper.find('[data-testid="task-notes-leaf-note"]')
		expect(note.text()).toContain('Called the applicant, no answer.')
		expect(note.text()).toContain('Henk Bakker')
	})

	it("offers delete only on the reader's own note", async () => {
		leaves.notes = {
			results: [
				{ id: 3, message: 'Theirs', isCurrentUser: false },
				{ id: 4, message: 'Mine', isCurrentUser: true },
			],
			error: null,
		}
		const wrapper = await mountLeaf(TaskNotesLeaf)

		expect(
			wrapper.find('[data-testid="task-notes-leaf-delete-3"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="task-notes-leaf-delete-4"]').exists(),
		).toBe(true)

		await wrapper
			.find('[data-testid="task-notes-leaf-delete-4"]')
			.trigger('click')
		await flushPromises()

		expect(writes[0]).toEqual({ kind: 'remove', uuid: 'task-1', noteId: 4 })
	})

	it('writes a note, clears the composer and re-reads', async () => {
		// Re-reads rather than appending the reply: the endpoint is the
		// ordering authority, and a locally appended row sits in the wrong
		// place the moment two people write at once.
		const wrapper = await mountLeaf(TaskNotesLeaf)
		reads.length = 0

		await wrapper.find('textarea').setValue('Drawings are incomplete.')
		await wrapper.find('[data-testid="task-notes-leaf-add"]').trigger('click')
		await flushPromises()

		expect(writes).toEqual([
			{ kind: 'write', uuid: 'task-1', message: 'Drawings are incomplete.' },
		])
		expect(reads).toEqual([{ uuid: 'task-1', leaf: 'notes' }])
		expect(wrapper.find('textarea').element.value).toBe('')
	})

	it('keeps the draft and shows the reason when a write is refused', async () => {
		writeResult = { note: null, error: 'Note message is required' }
		const wrapper = await mountLeaf(TaskNotesLeaf)

		await wrapper.find('textarea').setValue('Something')
		await wrapper.find('[data-testid="task-notes-leaf-add"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="task-notes-leaf-error"]').text()).toBe(
			'Note message is required',
		)
		expect(wrapper.find('textarea').element.value).toBe('Something')
	})

	it('tells a failed read apart from an empty one', async () => {
		leaves.notes = { results: [], error: 'No such task' }
		const wrapper = await mountLeaf(TaskNotesLeaf)

		expect(wrapper.find('[data-testid="task-notes-leaf-error"]').text()).toBe(
			'No such task',
		)
		expect(wrapper.find('[data-testid="task-notes-leaf-empty"]').exists()).toBe(
			false,
		)
	})
})

describe('TaskEventsLeaf', () => {
	it('reads the task events endpoint, not an object one', async () => {
		await mountLeaf(TaskEventsLeaf)

		expect(reads).toEqual([{ uuid: 'task-1', leaf: 'events' }])
	})

	it("renders an appointment by the endpoint's own key names", async () => {
		// `summary`, not `title`. `dtstart` / `dtend`, not `start` / `end`.
		leaves.events = {
			results: [
				{
					id: 'visit.ics',
					summary: 'Site visit',
					dtstart: '2026-09-15T09:00:00+00:00',
					dtend: '2026-09-15T10:00:00+00:00',
					location: 'Dorpsstraat 1',
				},
			],
			error: null,
		}
		const wrapper = await mountLeaf(TaskEventsLeaf)

		const event = wrapper.find('[data-testid="task-events-leaf-event"]')
		expect(event.text()).toContain('Site visit')
		expect(event.text()).toContain('Dorpsstraat 1')
	})

	it('says so when the task has nothing booked', async () => {
		const wrapper = await mountLeaf(TaskEventsLeaf)

		expect(
			wrapper.find('[data-testid="task-events-leaf-empty"]').exists(),
		).toBe(true)
	})

	it('tells a failed read apart from an empty one', async () => {
		leaves.events = { results: [], error: 'No such task' }
		const wrapper = await mountLeaf(TaskEventsLeaf)

		expect(wrapper.find('[data-testid="task-events-leaf-error"]').text()).toBe(
			'No such task',
		)
		expect(
			wrapper.find('[data-testid="task-events-leaf-empty"]').exists(),
		).toBe(false)
	})
})

describe('TaskAuditLeaf', () => {
	it('reads nothing until the reader opens it', async () => {
		// A second request per task view, for the one part of the page a
		// handler finishing a task never looks at.
		await mountLeaf(TaskAuditLeaf)

		expect(reads).toEqual([])
	})

	it('reads the engine audit endpoint when opened, once', async () => {
		const wrapper = await mountLeaf(TaskAuditLeaf)

		await wrapper.find('[data-testid="task-audit-leaf-toggle"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="task-audit-leaf-toggle"]').trigger('click')
		await flushPromises()

		expect(reads).toEqual([{ uuid: 'task-1', leaf: 'audit' }])
	})

	it('names the action, the state it left and who did it', async () => {
		leaves.audit = {
			results: [
				{
					id: 1,
					action: 'claim',
					stateAfter: 'active',
					actor: 'hbakker',
					authorized: true,
					created: '2026-09-09T10:00:00+00:00',
				},
			],
			error: null,
		}
		const wrapper = await mountLeaf(TaskAuditLeaf)
		await wrapper.find('[data-testid="task-audit-leaf-toggle"]').trigger('click')
		await flushPromises()

		const entry = wrapper.find('[data-testid="task-audit-leaf-entry"]')
		expect(entry.text()).toContain('claim (active)')
		expect(entry.text()).toContain('hbakker')
		expect(entry.text()).not.toContain('refused')
	})

	it('marks a refused action as refused', async () => {
		// The engine writes a REFUSED verb to the trail too. Rendering it
		// identically to a successful one makes the history claim things
		// happened that did not.
		leaves.audit = {
			results: [
				{
					id: 2,
					action: 'complete',
					stateAfter: 'active',
					actor: 'jdejong',
					authorized: false,
					created: '2026-09-09T11:00:00+00:00',
				},
			],
			error: null,
		}
		const wrapper = await mountLeaf(TaskAuditLeaf)
		await wrapper.find('[data-testid="task-audit-leaf-toggle"]').trigger('click')
		await flushPromises()

		expect(
			wrapper.find('[data-testid="task-audit-leaf-entry"]').text(),
		).toContain('refused')
	})

	it('tells a failed read apart from an empty one', async () => {
		leaves.audit = { results: [], error: 'No such task' }
		const wrapper = await mountLeaf(TaskAuditLeaf)
		await wrapper.find('[data-testid="task-audit-leaf-toggle"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="task-audit-leaf-error"]').text()).toBe(
			'No such task',
		)
		expect(
			wrapper.find('[data-testid="task-audit-leaf-empty"]').exists(),
		).toBe(false)
	})
})
