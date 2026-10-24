// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The team workload on My work is a strip of name chips (board DqAanMijToegewezen):
 * the reader's own chip first and active, each with its open-case count, and a
 * chosen name hands its user id to the page.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const barProps = {}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnQuickFilterBar: defineComponent({
		name: 'CnQuickFilterBar',
		props: { tabs: Array, activeIndex: { type: Number, default: null }, counts: Array, inline: Boolean },
		emits: ['update:active-index'],
		setup(props) {
			barProps.current = props
		},
		render() {
			return h('div', { class: 'chips-stub' })
		},
	}),
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'pieter' }),
}))

const getWorkload = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => getWorkload(...args) },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

const { default: WorkloadSummaryBar } = await import('../../src/views/WorkloadSummaryBar.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? vars[key] : m))

const handlers = [
	{ handler: 'anouk', openCaseCount: 9, displayName: 'Anouk Bakker' },
	{ handler: 'pieter', openCaseCount: 7, displayName: 'Pieter Jansen' },
	{ handler: 'lars', openCaseCount: 6 },
]

function mountBar(rows) {
	return mount(WorkloadSummaryBar, {
		props: { handlers: rows },
		global: { mocks: { t } },
	})
}

describe('WorkloadSummaryBar', () => {
	it('puts the reader first and active, with a count per name', () => {
		mountBar(handlers)

		expect(barProps.current.tabs.map((tab) => tab.label)).toEqual(['Pieter Jansen (you)', 'Anouk Bakker', 'lars'])
		expect(barProps.current.counts).toEqual([7, 9, 6])
		expect(barProps.current.activeIndex).toBe(0)
	})

	it('has no active chip when the reader holds no open cases', () => {
		mountBar(handlers.filter((row) => row.handler !== 'pieter'))

		expect(barProps.current.activeIndex).toBeNull()
	})

	it('emits the chosen handler', async () => {
		const wrapper = mountBar(handlers)

		await wrapper.findComponent({ name: 'CnQuickFilterBar' }).vm.$emit('update:active-index', 1)
		await wrapper.findComponent({ name: 'CnQuickFilterBar' }).vm.$emit('update:active-index', null)

		expect(wrapper.emitted('select')).toEqual([['anouk']])
	})

	it('renders nothing without workload', () => {
		const wrapper = mountBar([])

		expect(wrapper.find('[data-testid="workload-summary"]').exists()).toBe(false)
	})
})

describe('WorkloadSummaryBar in parts', () => {
	it('draws only the heading for part heading', () => {
		const wrapper = mount(WorkloadSummaryBar, { props: { handlers, part: 'heading' }, global: { mocks: { t } } })
		expect(wrapper.find('.workload-summary__title').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'CnQuickFilterBar' }).exists()).toBe(false)
	})

	it('draws only the name chips for part chips', () => {
		const wrapper = mount(WorkloadSummaryBar, { props: { handlers, part: 'chips' }, global: { mocks: { t } } })
		expect(wrapper.find('.workload-summary__title').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'CnQuickFilterBar' }).exists()).toBe(true)
	})

	it('reads the workload once for the heading and the chips together', async () => {
		getWorkload.mockReset()
		getWorkload.mockResolvedValue({ data: { handlers } })
		const mocks = { t, $router: { push: vi.fn() } }
		const heading = mount(WorkloadSummaryBar, { props: { part: 'heading' }, global: { mocks } })
		const chips = mount(WorkloadSummaryBar, { props: { part: 'chips' }, global: { mocks } })
		await flushPromises()
		expect(getWorkload).toHaveBeenCalledTimes(1)
		expect(heading.find('.workload-summary__title').exists()).toBe(true)
		expect(chips.findComponent({ name: 'CnQuickFilterBar' }).exists()).toBe(true)
	})
})

describe('WorkloadSummaryBar on My Work', () => {
	it('reads the workload itself when no rows are handed in', async () => {
		getWorkload.mockResolvedValue({ data: { handlers } })
		const wrapper = mount(WorkloadSummaryBar, { global: { mocks: { t, $router: { push: vi.fn() } } } })
		await flushPromises()
		expect(getWorkload).toHaveBeenCalledWith('/index.php/apps/dossiq/api/work-queue/workload')
		expect(wrapper.find('[data-testid="workload-summary"]').exists()).toBe(true)
	})

	it('renders nothing for a reader the endpoint refuses', async () => {
		getWorkload.mockRejectedValue(new Error('403'))
		const wrapper = mount(WorkloadSummaryBar, { global: { mocks: { t, $router: { push: vi.fn() } } } })
		await flushPromises()
		expect(wrapper.find('[data-testid="workload-summary"]').exists()).toBe(false)
	})

	it('opens a colleague\'s open cases and stays put on the reader\'s own chip', async () => {
		const push = vi.fn()
		const wrapper = mount(WorkloadSummaryBar, { props: { handlers }, global: { mocks: { t, $router: { push } } } })
		const bar = wrapper.findComponent({ name: 'CnQuickFilterBar' })
		await bar.vm.$emit('update:active-index', 0)
		expect(push).not.toHaveBeenCalled()
		await bar.vm.$emit('update:active-index', 1)
		expect(push).toHaveBeenCalledWith({
			name: 'Cases',
			query: { assignee: 'anouk', isFinalStatus: 'false', statusHiddenInLists: 'false', isDraft: 'false' },
		})
	})
})
