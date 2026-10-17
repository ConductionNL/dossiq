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
import { mount } from '@vue/test-utils'
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
