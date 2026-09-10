// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The way back from a task to its case (task-on-the-case, REQ-TASK-015).
 *
 * Behaviours only observable here. The link has to carry the CASE id and the
 * case TITLE, because a link built from the task id looks identical on screen
 * and lands on the wrong page, and a $ref rendered raw shows a uuid that
 * reads as a broken label. The card's facts have to come from RESOLVED
 * references, because `case.caseType` and `case.status` are themselves $refs
 * and a uuid in a "Case type" row is worse than no row. A fact that cannot
 * be read has to be ABSENT rather than blank, because a column of em dashes
 * reads as a broken card. And a task with no case has to render NOTHING: an
 * empty titled box on every ad-hoc to-do is exactly the clutter the null
 * render exists to avoid, and no e2e can seed it here because `case` is
 * required on `caseTask`.
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

vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: defineComponent({
		name: 'CnStatusBadge',
		props: { label: { type: String, default: '' } },
		render() {
			return h('span', { 'data-testid': 'task-case-card-status' }, this.label)
		},
	}),
}))

const { default: TaskCaseCard } =
	await import('../../src/components/tasks/TaskCaseCard.vue')

/** A router-link that renders its target so the route can be read off it. */
const RouterLinkStub = defineComponent({
	name: 'RouterLink',
	props: { to: { type: [String, Object], default: '' } },
	render() {
		return h('a', { 'data-to': String(this.to) }, this.$slots.default?.())
	},
})

/**
 * Mount the card.
 *
 * @param {object} props The props the page slot binds.
 * @return {Promise<object>} The mounted wrapper, after its loads settle.
 */
async function mountCard(props) {
	const wrapper = mount(TaskCaseCard, {
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

describe('TaskCaseCard', () => {
	it('names the case by its title and routes to the case page', async () => {
		rows = {
			caseTask: { 'task-1': { id: 'task-1', case: 'case-9' } },
			case: {
				'case-9': { id: 'case-9', title: 'Permit for 12 Mandelaplein' },
			},
		}

		const wrapper = await mountCard({ objectId: 'task-1' })

		const link = wrapper.find('[data-testid="task-case-link-link"]')
		expect(link.text()).toBe('Permit for 12 Mandelaplein')
		// The CASE id, not the task id: both are uuids and both render a
		// plausible link.
		expect(link.attributes('data-to')).toBe('/cases/case-9')
	})

	it('carries the case identity and resolves both of the case own references', async () => {
		rows = {
			caseTask: { 'task-1': { id: 'task-1', case: 'case-9' } },
			case: {
				'case-9': {
					id: 'case-9',
					identifier: 'ZAAK-2026-0114',
					title: 'Objection 2026-114',
					caseType: 'ct-1',
					status: 'st-1',
					assignee: 'k.dijkstra',
					deadline: '2026-11-02',
				},
			},
			caseType: { 'ct-1': { id: 'ct-1', title: 'Objection' } },
			// `name`, NOT `title`. The deployed statusType schema carries
			// `name`+`caseType`+`order`+`isFinal` while caseType carries
			// `title`; tests/e2e/helpers/fixtures.ts documents the same split.
			// A fixture with `title` here passes against a component that only
			// reads `title`, and the badge is silently missing in the browser.
			statusType: { 'st-1': { id: 'st-1', name: 'Under review' } },
		}

		const wrapper = await mountCard({ objectId: 'task-1' })

		expect(wrapper.find('[data-testid="task-case-card-identifier"]').text())
			.toBe('ZAAK-2026-0114')
		// Resolved, not the raw uuid. A "Case type: ct-1" row is the failure
		// this card exists to prevent.
		expect(wrapper.find('[data-testid="task-case-card-type"]').text())
			.toBe('Objection')
		expect(wrapper.find('[data-testid="task-case-card-status"]').text())
			.toBe('Under review')
		expect(wrapper.find('[data-testid="task-case-card-handler"]').text())
			.toBe('k.dijkstra')
	})

	it('omits a fact it cannot read instead of showing a blank row', async () => {
		rows = {
			caseTask: { 'task-1': { id: 'task-1', case: 'case-9' } },
			// No caseType, no status, no assignee, no deadline on the case, and
			// no rows for them in the store either.
			case: { 'case-9': { id: 'case-9', title: 'Bare case' } },
		}

		const wrapper = await mountCard({ objectId: 'task-1' })

		// The link still renders. The facts do not, and neither does the
		// definition list that would otherwise be empty.
		expect(wrapper.find('[data-testid="task-case-link-link"]').text())
			.toBe('Bare case')
		expect(wrapper.find('[data-testid="task-case-card-type"]').exists())
			.toBe(false)
		expect(wrapper.find('[data-testid="task-case-card-handler"]').exists())
			.toBe(false)
		expect(wrapper.find('.task-case-card__facts').exists()).toBe(false)
	})

	it('falls back to the planned end date when the case has no deadline', async () => {
		rows = {
			caseTask: { 'task-1': { id: 'task-1', case: 'case-9' } },
			case: {
				'case-9': { id: 'case-9', title: 'Planned only', plannedEndDate: '2026-12-24' },
			},
		}

		const wrapper = await mountCard({ objectId: 'task-1' })

		expect(wrapper.find('[data-testid="task-case-card-deadline"]').text())
			.toBe(new Date('2026-12-24').toLocaleDateString())
	})

	it('reads an expanded case reference and never re-reads a task the page handed over', async () => {
		rows = { case: { 'case-9': { id: 'case-9', title: 'Objection 2026-114' } } }

		const wrapper = await mountCard({
			objectId: 'task-1',
			objectData: { id: 'task-1', case: { id: 'case-9' } },
		})

		expect(wrapper.find('[data-testid="task-case-link-link"]').text()).toBe(
			'Objection 2026-114',
		)
		// One read, for the case. The task was already on the page, and the
		// case carries neither a caseType nor a status to resolve.
		expect(calls).toEqual([{ type: 'case', id: 'case-9' }])
	})

	it('still links when the case itself cannot be read', async () => {
		rows = { caseTask: { 'task-1': { id: 'task-1', case: 'case-9' } } }

		const wrapper = await mountCard({ objectId: 'task-1' })

		// The relationship is a fact of the task; the facts are only a fuller
		// label. Dropping the link because a second read failed would hide a
		// working way back.
		const link = wrapper.find('[data-testid="task-case-link-link"]')
		expect(link.attributes('data-to')).toBe('/cases/case-9')
		expect(link.text()).toBe('Open the case')
	})

	it('renders nothing at all for a task without a case', async () => {
		rows = { caseTask: { 'task-1': { id: 'task-1', case: '' } } }

		const wrapper = await mountCard({ objectId: 'task-1' })

		expect(wrapper.find('[data-testid="task-case-card"]').exists()).toBe(false)
		expect(wrapper.html()).toBe('<!--v-if-->')
	})

	it('renders nothing when the task itself cannot be read', async () => {
		const wrapper = await mountCard({ objectId: 'task-missing' })

		expect(wrapper.find('[data-testid="task-case-card"]').exists()).toBe(false)
	})
})
