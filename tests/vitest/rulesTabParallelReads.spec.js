// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Rules tab (DqZaaktypeRegels) asks for everything it shows at once. One
 * read after the other took about 17 seconds on :8080, so the tab showed a
 * spinner for longer than anyone waits.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const pending = []
const started = []
/**
 * A read that answers only when the test says so.
 *
 * @param {string} name What was asked.
 * @param {*} answer The answer.
 * @return {Promise<*>} The read.
 */
function held(name, answer) {
	started.push(name)
	return new Promise((resolve) => pending.push(() => resolve(answer)))
}

vi.mock('@nextcloud/axios', () => ({ default: { get: (url) => held(url, { data: url.endsWith('/rules') ? { rules: [] } : {} }), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: {} }) }))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchObject: (type) => held(`object:${type}`, { id: 'type-1', rules: [] }),
		fetchCollection: (type) => held(`list:${type}`, []),
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

describe('the Rules tab reads side by side', () => {
	it('asks the vocabulary, the schema, the rules, the case type and its catalogue before any answer', async () => {
		const { default: RulesTab } = await import('../../src/views/settings/tabs/RulesTab.vue')
		const w = mount(RulesTab, { props: { caseTypeId: 'type-1' }, global: { mocks: { t: (_app, text) => text } } })
		await flushPromises()
		expect(started).toEqual(expect.arrayContaining([
			'/apps/openregister/api/rules/vocabulary',
			'/apps/openregister/api/schemas/case',
			'/apps/openregister/api/schemas/case/rules',
			'object:caseType',
			'/apps/dossiq/api/case-types/type-1/blueprint',
			'list:documentType',
			'list:emailTemplate',
			'list:roleType',
		]))
		while (pending.length > 0) {
			pending.splice(0).forEach((answer) => answer())
			await flushPromises()
		}
		expect(w.find('.NcLoadingIcon').exists()).toBe(false)
	}, 30000)
})
