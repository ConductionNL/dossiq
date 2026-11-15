// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publishing a decision: which case is meant, the groups shown, the publish call and its refusals.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const http = { get: vi.fn(), post: vi.fn() }
const emitted = vi.fn()
const summary = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => http.get(...a), post: (...a) => http.post(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => emitted(...args) }))
vi.mock('../../src/services/caseSummary.js', () => ({
	fetchCaseSummary: (...args) => summary(...args),
	caseEyebrow: (act, kase) => `${act} · ${kase?.title ?? ''}`,
}))
function plain (name) {
  return defineComponent({ name, render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} })
}
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: plain('NcButton') }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: plain('NcNoteCard') }))
vi.mock('vue-material-design-icons/Gavel.vue', () => ({ default: plain('Gavel') }))
vi.mock('vue-material-design-icons/Publish.vue', () => ({ default: plain('PublishIcon') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: plain('CnDialog') }))

const { default: Dialog } = await import('../../src/dialogs/CasePublishDialog.vue')

/**
 * Mount and let the summary settle.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function dialog(props) {
	const w = mount(Dialog, { props, global: { mocks: { $route: { params: { id: 'c-route' } } } } })
	await flushPromises()
	return w
}

describe('CasePublishDialog publish', () => {
	beforeEach(() => {
		http.get.mockReset()
		http.post.mockReset()
		emitted.mockReset()
		summary.mockReset()
		summary.mockResolvedValue({ title: 'Dakkapel Dorpsstraat' })
	})

	it('uses the given case, but the route\'s when the prop is a template placeholder', async () => {
		expect((await dialog({ caseId: 'c-1' })).vm.targetCaseId).toBe('c-1')
		expect((await dialog({ caseId: '@self.id' })).vm.targetCaseId).toBe('c-route')
		expect((await dialog({})).vm.targetCaseId).toBe('c-route')
		expect(summary).toHaveBeenLastCalledWith('c-route')
	})

	it('names the act over the case summary it read', async () => {
		const w = await dialog({ caseId: 'c-1', act: 'Besluit' })
		expect(w.vm.eyebrow).toBe('Besluit · Dakkapel Dorpsstraat')
	})

	it('shapes every classification as a group with defaults and its tally', async () => {
		const w = await dialog({
			caseId: 'c-1',
			classifications: [
				{ value: 'public', label: 'Openbaar', note: 'Wordt gepubliceerd', tone: 'success' },
				{ value: 'withheld', excluded: true },
				{},
			],
		})
		w.vm.tally = { public: 3, withheld: 1 }

		expect(w.vm.groups).toEqual([
			{ value: 'public', label: 'Openbaar', note: 'Wordt gepubliceerd', tone: 'success', excluded: false, count: 3 },
			{ value: 'withheld', label: 'withheld', note: '', tone: 'neutral', excluded: true, count: 1 },
			{ value: '', label: '', note: '', tone: 'neutral', excluded: false, count: 0 },
		])
	})

	it('posts to the publish url with the case filled in, then marks it published and refreshes', async () => {
		http.post.mockResolvedValue({ data: {} })
		const w = await dialog({ caseId: 'c 1', publishUrl: '/apps/dossiq/api/cases/{caseId}/publish' })

		await w.vm.publish()

		expect(http.post).toHaveBeenCalledWith('/apps/dossiq/api/cases/c%201/publish', {})
		expect(w.vm.published).toBe(true)
		expect(emitted).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(w.vm.busy).toBe(false)
	})

	it('shows the server\'s message, else its error, else a fallback', async () => {
		const w = await dialog({ caseId: 'c-1', publishUrl: '/p/{caseId}' })

		http.post.mockImplementationOnce(() => Promise.reject({ response: { data: { message: 'Nog niet ondertekend', error: 'ignored' } } }))
		await w.vm.publish()
		expect(w.vm.error).toBe('Nog niet ondertekend')

		http.post.mockImplementationOnce(() => Promise.reject({ response: { data: { error: 'Geen rechten' } } }))
		await w.vm.publish()
		expect(w.vm.error).toBe('Geen rechten')

		http.post.mockImplementationOnce(() => Promise.reject(new Error('network')))
		await w.vm.publish()
		expect(w.vm.error).toBe('The decision could not be published.')
		expect(w.vm.published).toBe(false)
		expect(emitted).not.toHaveBeenCalled()
		expect(w.vm.busy).toBe(false)
	})
})
