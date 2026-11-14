// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The field rights widget's edges: without a case type it reads nothing, a
 * case type that cannot be read shows no rules, a role filter is set from its
 * checkbox and cleared by unticking it, and the widget stops listening for the
 * page refresh once it is gone.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const calls = vi.hoisted(() => ({ fetchObject: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: { get: (url) => Promise.resolve({ data: url.endsWith('/blueprint') ? { propertyDefinitions: [{ name: 'bouwkosten', title: 'Bouwkosten' }, { name: 'welstand', title: 'Welstandsgebied' }], statusTypes: [{ id: 's1', name: 'Ontvangen' }] } : { results: [] } }) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: calls.subscribe, unsubscribe: calls.unsubscribe }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ fetchObject: calls.fetchObject }) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnStatusBadge: defineComponent({ props: ['label'], render() { return h('span', this.label) } }) }))
vi.mock('@nextcloud/vue/components/NcActions', () => ({ default: defineComponent({ name: 'NcActions', render() { return h('div', [this.$slots.default?.()]) } }) }))
vi.mock('@nextcloud/vue/components/NcActionCheckbox', () => ({
	default: defineComponent({
		name: 'NcActionCheckbox',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() { return h('label', this.$slots.default?.()) },
	}),
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: defineComponent({ name: 'NcLoadingIcon', render: () => h('span') }) }))

const RULES = [
	{ field: 'bouwkosten', rule: 'readOnly', groups: ['g'] },
	{ field: 'welstand', rule: 'hidden', groups: ['g'] },
]

/**
 * Mount the widget on a case type page.
 *
 * @param {string} id The route's case type id.
 * @return {Promise<object>} The wrapper.
 */
async function mountOn(id) {
	const { default: Widget } = await import('../../src/components/caseType/CaseTypeFieldRightsWidget.vue')
	const w = mount(Widget, { global: { mocks: { $route: { params: { id } }, t: (_a, s) => s } } })
	await flushPromises()
	return w
}

describe('the field rights widget edges', () => {
	beforeEach(() => {
		calls.fetchObject.mockReset().mockResolvedValue({ id: 'ct', fieldRoleRules: RULES })
		calls.subscribe.mockReset()
		calls.unsubscribe.mockReset()
	})

	it('reads nothing and stops loading without a case type', async () => {
		const w = await mountOn('')

		expect(calls.fetchObject).not.toHaveBeenCalled()
		expect(w.vm.loading).toBe(false)
		expect(w.vm.shownRows).toEqual([])
	})

	it('shows no rules when the case type cannot be read', async () => {
		calls.fetchObject.mockRejectedValue(new Error('gone'))
		const w = await mountOn('ct')

		expect(calls.fetchObject).toHaveBeenCalledWith('caseType', 'ct')
		expect(w.vm.rules).toEqual([])
		expect(w.vm.loading).toBe(false)
		expect(w.vm.shownRows).toEqual([])
	})

	it('sets a role filter from its checkbox and clears it by unticking', async () => {
		const w = await mountOn('ct')
		const labels = () => w.vm.shownRows.map((row) => row.label)
		expect(labels()).toEqual(['Bouwkosten', 'Welstandsgebied'])

		// One role column; its checkboxes follow the states it holds.
		const hidden = w.findAllComponents({ name: 'NcActionCheckbox' })[w.vm.statesIn(0).indexOf('hidden')]
		await hidden.vm.$emit('update:modelValue', true)
		expect(w.vm.filters).toEqual({ 0: ['hidden'] })
		expect(labels()).toEqual(['Welstandsgebied'])

		await hidden.vm.$emit('update:modelValue', false)
		expect(w.vm.filters).toEqual({ 0: [] })
		expect(labels()).toEqual(['Bouwkosten', 'Welstandsgebied'])
	})

	it('listens for the page refresh while shown and stops once unmounted', async () => {
		const w = await mountOn('ct')
		expect(calls.subscribe).toHaveBeenCalledTimes(1)
		const [event, handler] = calls.subscribe.mock.calls[0]

		w.unmount()

		expect(calls.unsubscribe).toHaveBeenCalledWith(event, handler)
	})
})
