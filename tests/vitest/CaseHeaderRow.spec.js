// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case identity row (case-header, task 2.2).
 *
 * Five behaviours, each of which this row is the only place to observe:
 * the badge reads the RESOLVED status type rather than the uuid the case
 * carries; a case with no status record says Unknown rather than rendering
 * no badge at all; an overdue deadline reads in days and paints in the
 * danger band; a deadline comfortably ahead reads plain; and a case with no
 * deadline renders no countdown element, rather than "0 days left".
 *
 * The spec file lives here rather than beside the component: vitest.config
 * collects `tests/vitest/**` only, so a spec under `src/` would never run.
 *
 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: defineComponent({
		name: 'CnStatusBadge',
		props: ['label', 'variant', 'size'],
		render() {
			return h('span', { class: `badge badge--${this.variant}` }, this.label)
		},
	}),
	CnBreadcrumbs: defineComponent({
		name: 'CnBreadcrumbs',
		props: ['crumbs', 'ariaLabel'],
		render() {
			return h(
				'nav',
				this.crumbs.map((crumb, index) =>
					h(
						'a',
						{
							class: 'crumb',
							'aria-current':
								index === this.crumbs.length - 1
									? 'page'
									: undefined,
						},
						crumb.label,
					),
				),
			)
		},
	}),
}))

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => ({}),
}))

/** The rows the stub object store answers with, keyed `<schema>:<id>`. */
let rows = {}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchObject: async (schema, id) => rows[`${schema}:${id}`] ?? null,
	}),
}))

const { default: CaseHeaderRow } =
	await import('../../src/components/case/CaseHeaderRow.vue')

const WIDGET = {
	id: 'case-header',
	type: 'custom',
	props: {
		breadcrumbs: [
			{ label: 'Cases', route: 'Cases', icon: 'FolderAccountOutline' },
			{ field: 'title' },
		],
		thresholds: { warn: 14, danger: 5 },
	},
}

/**
 * A date `days` from today, as the date-only string OpenRegister stores.
 *
 * @param {number} days Positive for the future, negative for the past.
 * @return {string} A `YYYY-MM-DD` date.
 */
function dateOffset(days) {
	const d = new Date()
	d.setDate(d.getDate() + days)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(
		d.getDate(),
	).padStart(2, '0')}`
}

/**
 * Mount the row over one case.
 *
 * @param {object} caseObject The case the page hands the slot.
 * @return {Promise<object>} The mounted wrapper, after its lookups settle.
 */
async function mountRow(caseObject) {
	const wrapper = mount(CaseHeaderRow, {
		props: { widget: WIDGET, object: caseObject, objectId: 'case-1' },
		global: {
			mocks: { $route: { params: { id: 'case-1' } } },
		},
	})
	await flushPromises()
	await flushPromises()
	return wrapper
}

describe('CaseHeaderRow', () => {
	beforeEach(() => {
		rows = {
			'statusType:status-1': { id: 'status-1', name: 'In behandeling' },
			'statusType:status-final': {
				id: 'status-final',
				name: 'Afgehandeld',
				isFinal: true,
			},
			'caseType:type-1': { id: 'type-1', title: 'Omgevingsvergunning' },
		}
	})

	it('badges the resolved status type, not the uuid the case carries', async () => {
		const wrapper = await mountRow({
			identifier: '2026-0015',
			title: 'Aanbouw Beethovenlaan 8',
			status: 'status-1',
			caseType: 'type-1',
			assignee: 'jdvries',
			deadline: dateOffset(56),
		})

		const badge = wrapper.find('[data-testid="case-header-status"]')
		expect(badge.text()).toBe('In behandeling')
		expect(badge.classes()).toContain('badge--info')
		expect(wrapper.find('[data-testid="case-header-identifier"]').text()).toBe(
			'2026-0015',
		)
		expect(wrapper.find('[data-testid="case-header-casetype"]').text()).toBe(
			'Omgevingsvergunning',
		)
		expect(wrapper.find('[data-testid="case-header-assignee"]').text()).toBe(
			'jdvries',
		)
	})

	it('badges a final status in the success variant', async () => {
		const wrapper = await mountRow({ status: 'status-final' })
		const badge = wrapper.find('[data-testid="case-header-status"]')
		expect(badge.text()).toBe('Afgehandeld')
		expect(badge.classes()).toContain('badge--success')
	})

	it('reads Unknown when the case has no status record', async () => {
		// An absent badge and an unset status look identical on screen, and
		// only one of the two is a data problem.
		const wrapper = await mountRow({ identifier: '2026-0016' })
		expect(wrapper.find('[data-testid="case-header-status"]').text()).toBe(
			'Unknown',
		)
	})

	it('counts 26 days overdue in the danger band', async () => {
		const wrapper = await mountRow({
			status: 'status-1',
			deadline: dateOffset(-26),
		})
		const countdown = wrapper.find('[data-testid="case-header-countdown"]')
		expect(countdown.text()).toBe('26 days overdue')
		expect(countdown.classes()).toContain('is-danger')
	})

	it('counts 56 days left plain, with no band', async () => {
		const wrapper = await mountRow({
			status: 'status-1',
			deadline: dateOffset(56),
		})
		const countdown = wrapper.find('[data-testid="case-header-countdown"]')
		expect(countdown.text()).toBe('56 days left')
		expect(countdown.classes()).not.toContain('is-danger')
		expect(countdown.classes()).not.toContain('is-warning')
	})

	it('warns inside the 14-day band', async () => {
		const wrapper = await mountRow({
			status: 'status-1',
			deadline: dateOffset(10),
		})
		expect(
			wrapper.find('[data-testid="case-header-countdown"]').classes(),
		).toContain('is-warning')
	})

	it('renders no countdown element at all on a case with no deadline', async () => {
		// Not "0 days left": zero is a claim about a deadline, and a case
		// without one has made no such claim.
		const wrapper = await mountRow({ status: 'status-1' })
		expect(wrapper.find('[data-testid="case-header-countdown"]').exists()).toBe(
			false,
		)
	})

	it('trails Cases then the case title, the last crumb unlinked', async () => {
		const wrapper = await mountRow({
			title: 'Aanbouw Beethovenlaan 8',
			status: 'status-1',
		})
		const crumbs = wrapper.findAll('.crumb')
		expect(crumbs).toHaveLength(2)
		expect(crumbs[0].text()).toBe('Cases')
		expect(crumbs[1].text()).toBe('Aanbouw Beethovenlaan 8')
		expect(crumbs[1].attributes('aria-current')).toBe('page')
		expect(wrapper.vm.crumbs[0].to).toEqual({ name: 'Cases' })
		expect(wrapper.vm.crumbs[1].to).toBeUndefined()
	})
})
