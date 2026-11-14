// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type flow page opened directly: it draws the workflow only after
 * the object stores are registered, so the versions request is not refused
 * as "not registered" and read as "No workflow defined" (DqZaaktypeFlow).
 *
 * @spec openspec/specs/case-types/spec.md#requirement-the-case-type-flow-page-loads-its-workflow-when-opened-directly
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

let release
const started = vi.fn(() => new Promise((resolve) => { release = resolve }))

vi.mock('../../src/store/store.js', () => ({ initializeStores: () => started() }))
vi.mock('../../src/views/settings/tabs/WorkflowTab.vue', () => ({
	default: {
		name: 'WorkflowTab',
		props: ['caseTypeId'],
		render() {
			return h('div', { class: 'workflow-tab-stub', 'data-case-type': this.caseTypeId })
		},
	},
}))

const CaseTypeFlowView = (await import('../../src/views/settings/CaseTypeFlowView.vue')).default

/**
 * @return {object} The mounted page for case type ct-1.
 */
function mountPage() {
	return mount(CaseTypeFlowView, {
		global: {
			mocks: { t: (app, text) => text, $route: { params: { id: 'ct-1' } } },
			stubs: { NcAppContent: { template: '<div><slot /></div>' }, NcLoadingIcon: { template: '<span class="loading" />' }, 'router-link': { template: '<a><slot /></a>' } },
		},
	})
}

describe('case type flow page, opened directly', () => {
	it('starts the stores and waits for them before it draws the workflow', async () => {
		const w = mountPage()
		await flushPromises()
		expect(started).toHaveBeenCalled()
		expect(w.find('.workflow-tab-stub').exists()).toBe(false)
		expect(w.find('[data-testid="case-type-flow-loading"]').exists()).toBe(true)

		release()
		await flushPromises()
		expect(w.find('.workflow-tab-stub').attributes('data-case-type')).toBe('ct-1')
		expect(w.find('[data-testid="case-type-flow-loading"]').exists()).toBe(false)
	})
})
