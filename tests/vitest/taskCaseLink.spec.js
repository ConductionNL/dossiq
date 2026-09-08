// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The way back from a task to its case (task-on-the-case, REQ-TASK-015).
 *
 * Two behaviours, and each is only observable here. The link has to carry
 * the CASE id and the case TITLE, because a link built from the task id
 * looks identical on screen and lands on the wrong page, and a $ref rendered
 * raw shows a uuid that reads as a broken label. And a task with no case has
 * to render NOTHING: an empty titled box on every ad-hoc to-do is exactly
 * the clutter the null render exists to avoid, and no e2e can seed it here
 * because `case` is required on `caseTask`.
 *
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => ({}),
}))

/** Rows the store answers with, keyed by type then id. Replaced per test. */
let rows = {}
/** Every fetchObject call, so a needless read can be caught. */
let calls = []

const storeStub = {
	async fetchObject(type, id) {
		calls.push({ type, id })
		const row = rows[type]?.[id]
		if (row === undefined) {
			throw new Error(`no ${type} ${id}`)
		}
		return row
	},
}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeStub,
}))

const { default: TaskCaseLink } =
	await import('../../src/components/tasks/TaskCaseLink.vue')

/** A router-link that renders its target so the route can be read off it. */
const RouterLinkStub = defineComponent({
	name: 'RouterLink',
	props: { to: { type: [String, Object], default: '' } },
	render() {
		return h('a', { 'data-to': String(this.to) }, this.$slots.default?.())
	},
})

/**
 * Mount the link.
 *
 * @param {object} props The props the page slot binds.
 * @return {Promise<object>} The mounted wrapper, after its loads settle.
 */
async function mountLink(props) {
	const wrapper = mount(TaskCaseLink, {
		props,
		global: { stubs: { RouterLink: RouterLinkStub } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	rows = {}
	calls = []
})

describe('TaskCaseLink', () => {
	it('names the case by its title and routes to the case page', async () => {
		rows = {
			caseTask: { 'task-1': { id: 'task-1', case: 'case-9' } },
			case: {
				'case-9': { id: 'case-9', title: 'Permit for 12 Mandelaplein' },
			},
		}

		const wrapper = await mountLink({ objectId: 'task-1' })

		const link = wrapper.find('[data-testid="task-case-link-link"]')
		expect(link.text()).toBe('Permit for 12 Mandelaplein')
		// The CASE id, not the task id: both are uuids and both render a
		// plausible link.
		expect(link.attributes('data-to')).toBe('/cases/case-9')
	})

	it('reads an expanded case reference and never re-reads a task the page handed over', async () => {
		rows = { case: { 'case-9': { id: 'case-9', title: 'Objection 2026-114' } } }

		const wrapper = await mountLink({
			objectId: 'task-1',
			objectData: { id: 'task-1', case: { id: 'case-9' } },
		})

		expect(wrapper.find('[data-testid="task-case-link-link"]').text()).toBe(
			'Objection 2026-114',
		)
		// One read, for the case title. The task was already on the page.
		expect(calls).toEqual([{ type: 'case', id: 'case-9' }])
	})

	it('still links when the case title cannot be read', async () => {
		rows = { caseTask: { 'task-1': { id: 'task-1', case: 'case-9' } } }

		const wrapper = await mountLink({ objectId: 'task-1' })

		// The relationship is a fact of the task; the title is only a nicer
		// label. Dropping the link because a second read failed would hide a
		// working way back.
		const link = wrapper.find('[data-testid="task-case-link-link"]')
		expect(link.attributes('data-to')).toBe('/cases/case-9')
		expect(link.text()).toBe('Open the case')
	})

	it('renders nothing at all for a task without a case', async () => {
		rows = { caseTask: { 'task-1': { id: 'task-1', case: '' } } }

		const wrapper = await mountLink({ objectId: 'task-1' })

		expect(wrapper.find('[data-testid="task-case-link"]').exists()).toBe(false)
		expect(wrapper.html()).toBe('<!--v-if-->')
	})

	it('renders nothing when the task itself cannot be read', async () => {
		const wrapper = await mountLink({ objectId: 'task-missing' })

		expect(wrapper.find('[data-testid="task-case-link"]').exists()).toBe(false)
	})
})
