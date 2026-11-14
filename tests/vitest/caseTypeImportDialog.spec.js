// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Importing a case type bundle: the strategy sent with the file, and the refusals shown.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const post = vi.fn()
const emitted = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => emitted(...args) }))
function plain (name) {
  return defineComponent({ name, render() {
	return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
} })
}
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: plain('NcButton') }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: plain('NcDialog') }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: plain('NcSelect') }))

const { default: CaseTypeImportDialog } = await import('../../src/dialogs/CaseTypeImportDialog.vue')

describe('CaseTypeImportDialog', () => {
	beforeEach(() => {
		post.mockReset()
		emitted.mockReset()
	})

	it('starts on the strategy that keeps what is already here', () => {
		const w = mount(CaseTypeImportDialog)
		expect(w.vm.strategies.map((s) => s.id)).toEqual(['skip', 'merge', 'overwrite'])
		expect(w.vm.strategy).toEqual({ id: 'skip', label: 'Keep what is already here' })
	})

	it('takes the picked file and clears an earlier error', () => {
		const w = mount(CaseTypeImportDialog)
		const file = new File(['{}'], 'bundle.json')
		w.vm.error = 'earlier'
		w.vm.onPick({ target: { files: [file] } })
		expect(w.vm.file).toBe(file)
		expect(w.vm.error).toBe('')
		w.vm.onPick({ target: { files: [] } })
		expect(w.vm.file).toBeNull()
	})

	it('sends nothing without a file', async () => {
		await mount(CaseTypeImportDialog).vm.confirm()
		expect(post).not.toHaveBeenCalled()
	})

	it('posts the file with the chosen strategy, refreshes and closes', async () => {
		post.mockResolvedValue({ data: {} })
		const w = mount(CaseTypeImportDialog)
		const file = new File(['{}'], 'bundle.json')
		w.vm.onPick({ target: { files: [file] } })
		w.vm.strategy = w.vm.strategies[2]

		await w.vm.confirm()

		expect(post).toHaveBeenCalledTimes(1)
		const [url, body] = post.mock.calls[0]
		expect(url).toBe('/apps/dossiq/api/case-definitions/import')
		expect(body.get('strategy')).toBe('overwrite')
		expect(body.get('package').name).toBe('bundle.json')
		expect(emitted).toHaveBeenCalledWith('cn:page:refresh')
		expect(w.emitted('close')).toHaveLength(1)
		expect(w.vm.busy).toBe(false)
	})

	it('shows a validation refusal verbatim, and other failures in its own words', async () => {
		const w = mount(CaseTypeImportDialog)
		w.vm.onPick({ target: { files: [new File(['{}'], 'bundle.json')] } })

		post.mockImplementationOnce(() => Promise.reject({ response: { status: 422, data: { error: 'The bundle has no case type' } } }))
		await w.vm.confirm()
		expect(w.vm.error).toBe('The bundle has no case type')

		post.mockImplementationOnce(() => Promise.reject({ response: { status: 500, data: { error: '/var/www/secret path' } } }))
		await w.vm.confirm()
		expect(w.vm.error).toBe('That did not work. Try again, or ask an administrator.')

		post.mockImplementationOnce(() => Promise.reject({ response: { status: 403, data: {} } }))
		await w.vm.confirm()
		expect(w.vm.error).toBe('Your account may not do this.')
		expect(w.emitted('close')).toBeUndefined()
		expect(w.vm.busy).toBe(false)
	})
})
