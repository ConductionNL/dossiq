// @vitest-environment jsdom
/**
 * Golden test for the Rules tab's load: what it keeps for an answer, a refusal
 * and a failure of the rules read, and for the summary, recorded before load was split.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const answer = { rules: { data: { rules: [{ id: 'r1' }] } } }
const asked = []

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url) => {
			asked.push(url)
			if (url.endsWith('/rules')) {
				if (answer.rules instanceof Error) {
					throw answer.rules
				}
				return answer.rules
			}
			return { data: {} }
		},
		post: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: {} }) }))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchObject: async () => ({ id: 'type-1', rules: [] }),
		fetchCollection: async () => [],
		saveObject: vi.fn(),
	}),
}))

/**
 * A stub that renders its default slot.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return defineComponent({
		name,
		render() {
			return h('div', { class: name }, this.$slots.default?.() ?? [])
		},
	})
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

/**
 * Mount the tab with this rules answer and read what it kept.
 *
 * @param {object} rules What the rules read answers, or the error it fails with.
 * @param {object} props The tab's props.
 * @return {Promise<object>} What the tab kept.
 */
async function loaded(rules, props = { caseTypeId: 'type-1' }) {
	answer.rules = rules
	asked.length = 0
	const { default: RulesTab } = await import('../../src/views/settings/tabs/RulesTab.vue')
	const w = mount(RulesTab, { props, global: { mocks: { t: (_app, text) => text } } })
	await flushPromises()
	return { rules: w.vm.rules, error: w.vm.error, loading: w.vm.loading, askedRules: asked.filter((url) => url.endsWith('/rules')).length }
}

describe('the Rules tab load', () => {
	it('keeps what each read answers, as before', async () => {
		const refused = (status) => Object.assign(new Error('refused'), { response: { status } })
		expect({
			answered: await loaded({ data: { rules: [{ id: 'r1' }] } }),
			forbidden: await loaded(refused(403)),
			forbiddenAsText: await loaded(refused('403')),
			failed: await loaded(refused(500)),
			unanswered: await loaded(new Error('offline')),
			summary: await loaded({ data: { rules: [{ id: 'r1' }] } }, { caseTypeId: 'type-1', summary: true }),
		}).toMatchSnapshot()
	}, 30000)
})
