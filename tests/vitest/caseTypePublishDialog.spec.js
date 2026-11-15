// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publishing a case type: the validation read first, the change note required, the findings shown.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const http = { get: vi.fn(), post: vi.fn() }
const emitted = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => http.get(...a), post: (...a) => http.post(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => emitted(...args) }))
function plain (name) {
  return defineComponent({ name, render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} })
}
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: plain('NcButton') }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: plain('NcDialog') }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: plain('NcLoadingIcon') }))
vi.mock('@nextcloud/vue/components/NcTextArea', () => ({ default: plain('NcTextArea') }))

const { default: CaseTypePublishDialog } = await import('../../src/dialogs/CaseTypePublishDialog.vue')

/**
 * Mount and let the validation settle.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function dialog(props = { caseTypeId: 'ct 1' }) {
	const w = mount(CaseTypePublishDialog, { props, global: { mocks: { $route: { params: { id: 'ct-route' } } } } })
	await flushPromises()
	return w
}

describe('CaseTypePublishDialog', () => {
	beforeEach(() => {
		http.get.mockReset()
		http.post.mockReset()
		emitted.mockReset()
	})

	it('validates the case type first, encoding its id, and keeps findings and warnings', async () => {
		http.get.mockResolvedValue({ data: { findings: [{ code: 'no-status' }], warnings: [{ code: 'no-sla' }] } })
		const w = await dialog()

		expect(http.get).toHaveBeenCalledWith('/apps/dossiq/api/case-types/ct%201/publish/validate')
		expect(w.vm.findings).toEqual([{ code: 'no-status' }])
		expect(w.vm.warnings).toEqual([{ code: 'no-sla' }])
		expect(w.vm.loading).toBe(false)
	})

	it('falls back to the route id, and to empty lists for a malformed answer', async () => {
		http.get.mockResolvedValue({ data: { findings: 'none' } })
		const w = await dialog({})

		expect(http.get).toHaveBeenCalledWith('/apps/dossiq/api/case-types/ct-route/publish/validate')
		expect(w.vm.findings).toEqual([])
		expect(w.vm.warnings).toEqual([])
	})

	it('publishes only without findings and with a change note', async () => {
		http.get.mockResolvedValue({ data: { findings: [{ code: 'x' }] } })
		const w = await dialog()
		w.vm.changeNote = 'Nieuwe termijn'
		expect(w.vm.canPublish).toBe(false)
		w.vm.findings = []
		expect(w.vm.canPublish).toBe(true)
		w.vm.changeNote = '  '
		expect(w.vm.canPublish).toBe(false)
	})

	it('a refused validation empties the lists and shows its own words', async () => {
		http.get.mockImplementationOnce(() => Promise.reject({ response: { status: 404 } }))
		const w = await dialog()

		expect(w.vm.error).toBe('This case type no longer exists.')
		expect(w.vm.findings).toEqual([])
		expect(w.vm.loading).toBe(false)
	})

	it('publishes with the change note, refreshes and closes', async () => {
		http.get.mockResolvedValue({ data: {} })
		http.post.mockResolvedValue({ data: {} })
		const w = await dialog()
		w.vm.changeNote = 'Nieuwe termijn'

		await w.vm.publish()

		expect(http.post).toHaveBeenCalledWith('/apps/dossiq/api/case-types/ct%201/publish', { changeNote: 'Nieuwe termijn' })
		expect(emitted).toHaveBeenCalledWith('cn:page:refresh')
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('a refusal carrying findings shows them; any other refusal shows a message', async () => {
		http.get.mockResolvedValue({ data: {} })
		const w = await dialog()

		http.post.mockImplementationOnce(() => Promise.reject({ response: { status: 422, data: { findings: [{ code: 'late' }] } } }))
		await w.vm.publish()
		expect(w.vm.findings).toEqual([{ code: 'late' }])
		expect(w.vm.error).toBe('')

		http.post.mockImplementationOnce(() => Promise.reject({ response: { status: 401, data: { findings: [] } } }))
		await w.vm.publish()
		expect(w.vm.error).toBe('Sign in again and retry.')
		expect(w.emitted('close')).toBeUndefined()
		expect(emitted).not.toHaveBeenCalled()
	})
})
