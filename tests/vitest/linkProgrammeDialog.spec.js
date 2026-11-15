// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Linking a case to a pipelinq programme: what is read, what is sent, what a refusal says.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const emitted = vi.fn()
const api = { fetchProgrammes: vi.fn(), linkCaseProgramme: vi.fn() }

vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => emitted(...args) }))
vi.mock('../../src/services/pipelinqCaseApi.js', async (importOriginal) => ({
	refusalOf: (await importOriginal()).refusalOf,
	fetchProgrammes: (...args) => api.fetchProgrammes(...args),
	linkCaseProgramme: (...args) => api.linkCaseProgramme(...args),
}))
function plain (name) {
  return defineComponent({ name, render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} })
}
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: plain('NcButton') }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: plain('NcCheckboxRadioSwitch') }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: plain('NcDialog') }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: plain('NcLoadingIcon') }))

const { default: LinkProgrammeDialog } = await import('../../src/dialogs/LinkProgrammeDialog.vue')

/**
 * Mount and let the programme read settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function dialog() {
	const w = mount(LinkProgrammeDialog, { props: { caseId: 'c-1', caseTitle: 'Dakkapel' } })
	await flushPromises()
	return w
}

describe('LinkProgrammeDialog', () => {
	beforeEach(() => {
		emitted.mockReset()
		api.fetchProgrammes.mockReset()
		api.linkCaseProgramme.mockReset()
	})

	it('lists the programmes pipelinq answers, and stops loading', async () => {
		api.fetchProgrammes.mockResolvedValue({ programmes: [{ id: 'p-1' }, { id: 'p-2' }] })
		const w = await dialog()

		expect(w.vm.programmes).toEqual([{ id: 'p-1' }, { id: 'p-2' }])
		expect(w.vm.loading).toBe(false)
		expect(w.vm.error).toBe('')
	})

	it('treats an answer without a programme list as none', async () => {
		api.fetchProgrammes.mockResolvedValue({ programmes: 'none' })
		expect((await dialog()).vm.programmes).toEqual([])
	})

	it('shows the server\'s refusal of the read, or a fallback', async () => {
		api.fetchProgrammes.mockImplementationOnce(() => Promise.reject({ response: { data: { error: 'pipelinq is not installed' } } }))
		const refused = await dialog()
		expect(refused.vm.error).toBe('pipelinq is not installed')
		expect(refused.vm.loading).toBe(false)

		api.fetchProgrammes.mockImplementationOnce(() => Promise.reject(new Error('network')))
		expect((await dialog()).vm.error).toBe('The programmes could not be read.')
	})

	it('links the chosen programme with the case title, then refreshes and closes', async () => {
		api.fetchProgrammes.mockResolvedValue({ programmes: [] })
		api.linkCaseProgramme.mockResolvedValue({})
		const w = await dialog()
		w.vm.chosen = 'p-2'

		await w.vm.confirm()

		expect(api.linkCaseProgramme).toHaveBeenCalledWith('c-1', 'p-2', 'Dakkapel')
		expect(w.emitted('linked')).toHaveLength(1)
		expect(w.emitted('close')).toHaveLength(1)
		expect(emitted).toHaveBeenCalledWith('cn:page:refresh')
		expect(w.vm.busy).toBe(false)
	})

	it('stays open with the refusal when the link is refused', async () => {
		api.fetchProgrammes.mockResolvedValue({ programmes: [] })
		api.linkCaseProgramme.mockImplementationOnce(() => Promise.reject(new Error('boom')))
		const w = await dialog()
		w.vm.chosen = 'p-2'

		await w.vm.confirm()

		expect(w.vm.error).toBe('The case could not be linked to that programme.')
		expect(w.emitted('close')).toBeUndefined()
		expect(w.emitted('linked')).toBeUndefined()
		expect(emitted).not.toHaveBeenCalled()
		expect(w.vm.busy).toBe(false)
	})
})
