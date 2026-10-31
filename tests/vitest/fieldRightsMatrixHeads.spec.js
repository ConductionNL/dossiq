// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The field rights matrix sorts by field from its Field head and filters each
 * role column on the states it holds, as DqZaaktypeVelden draws a chevron
 * and a funnel on its heads (r31 gapBelowTitle 41 vs 43.8 came from the
 * board's 22px head controls the live heads lacked).
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: (url) => Promise.resolve({ data: url.endsWith('/blueprint') ? { propertyDefinitions: [{ name: 'bouwkosten', title: 'Bouwkosten' }, { name: 'aanvrager', title: 'Aanvrager' }, { name: 'welstand', title: 'Welstandsgebied' }], statusTypes: [{ id: 's1', name: 'Ontvangen' }] } : { results: [] } }) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchObject: () => Promise.resolve({ id: 'ct', fieldRoleRules: [
			{ field: 'bouwkosten', rule: 'readOnly', groups: ['g'] },
			{ field: 'welstand', rule: 'hidden', groups: ['g'] },
			{ field: 'aanvrager', rule: 'readOnly', groups: ['g'] },
		] }),
	}),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnStatusBadge: defineComponent({ props: ['label'], render() { return h('span', this.label) } }) }))
function stub(name) {
	return defineComponent({ name, render() {
		return h('div', { class: name }, [this.$slots.default?.(), this.$slots.icon?.()])
	} })
}
vi.mock('@nextcloud/vue/components/NcActions', () => ({ default: stub('NcActions') }))
vi.mock('@nextcloud/vue/components/NcActionCheckbox', () => ({ default: stub('NcActionCheckbox') }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: stub('NcLoadingIcon') }))

describe('the field rights matrix heads', () => {
	it('sorts by field and filters a role column on its states', async () => {
		const { default: Widget } = await import('../../src/components/caseType/CaseTypeFieldRightsWidget.vue')
		const w = mount(Widget, { global: { mocks: { $route: { params: { id: 'ct' } }, t: (_a, s) => s } } })
		await flushPromises()
		const labels = () => w.vm.shownRows.map((row) => row.label)
		expect(labels()).toEqual(['Bouwkosten', 'Aanvrager', 'Welstandsgebied'])
		await w.find('[data-testid="case-type-field-rights-sort"]').trigger('click')
		expect(labels()).toEqual(['Aanvrager', 'Bouwkosten', 'Welstandsgebied'])
		expect(w.vm.statesIn(0)).toEqual(['readOnly', 'hidden'])
		w.vm.toggleFilter(0, 'hidden')
		await flushPromises()
		expect(labels()).toEqual(['Welstandsgebied'])
	}, 30000)
})
