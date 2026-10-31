// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A rule names its status and message by their names in the reader's
 * language. A status name is a language map, and handed to the translation
 * as an object it left the placeholder standing: r31 DqZaaktypeOverzicht read
 * "Als status {status} wordt".
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const RULE = { id: 'r1', when: { event: 'statusEntered', status: 's-weigering' }, actions: [{ action: 'sendMessage', emailTemplate: 't-weigering' }], enabled: true }

vi.mock('@nextcloud/axios', () => ({ default: { get: (url) => Promise.resolve({ data: url.endsWith('/blueprint') ? { propertyDefinitions: [], statusTypes: [{ id: 's-weigering', name: { nl: 'Geweigerd', en: 'Refused' } }] } : {} }), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: {} }) }))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchObject: () => Promise.resolve({ id: 'type-1', rules: [RULE] }),
		fetchCollection: (type) => Promise.resolve(type === 'emailTemplate' ? [{ id: 't-weigering', name: { nl: 'Weigering met bezwaarclausule' } }] : []),
		saveObject: vi.fn(),
	}),
}))
function stub(name) {
	return defineComponent({ name, render() {
		return h('div', { class: name }, this.$slots.default?.() ?? [])
	} })
}
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: stub('NcActionButton'),
	NcActions: stub('NcActions'),
	NcButton: stub('NcButton'),
	NcDialog: stub('NcDialog'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))

describe('the Rules tab names a rule\'s status and message', () => {
	it('reads a language-map name as text', async () => {
		const { default: RulesTab } = await import('../../src/views/settings/tabs/RulesTab.vue')
		const w = mount(RulesTab, { props: { caseTypeId: 'type-1', summary: true }, global: { mocks: { t: (_app, text) => text } } })
		await flushPromises()
		const [row] = w.vm.caseRuleRows
		expect(row.title).toContain('Geweigerd')
		expect(row.title).not.toContain('{status}')
		expect(row.line).toContain('Weigering met bezwaarclausule')
	}, 30000)
})
