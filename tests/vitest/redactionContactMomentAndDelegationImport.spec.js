// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The redaction assist dialog (detect, pick spans, approve or reject), the
 * dialog that files a contact moment on another case, and the delegation
 * import panel, mounted with axios and their services doubled.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const stub = (name) => ({ name, render: () => null })
const bus = []
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcDialog: stub('NcDialog'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard') }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: stub('NcButton') }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: stub('NcCheckboxRadioSwitch') }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: stub('NcDialog') }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: stub('NcTextField') }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name) => bus.push(name) }))
vi.mock('../../src/services/pipelinqCaseApi.js', () => ({
	fileContactMoment: vi.fn(),
	refusalOf: (error, fallback) => {
		const said = error?.response?.data?.error
		return typeof said === 'string' && said !== '' ? said : fallback
	},
}))

const pipelinq = await import('../../src/services/pipelinqCaseApi.js')
const { default: RedactionAssistDialog } = await import('../../src/dialogs/RedactionAssistDialog.vue')
const { default: FileContactMomentDialog } = await import('../../src/dialogs/FileContactMomentDialog.vue')
const { default: DelegationImportPanel } = await import('../../src/views/settings/components/DelegationImportPanel.vue')

const D = '/index.php/apps/dossiq/api'

/**
 * Mount a component.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props })
	await flushPromises()
	return w
}

beforeEach(() => {
	bus.length = 0
	vi.clearAllMocks()
	for (const verb of ['get', 'post']) {
		axios[verb].mockReset()
	}
})

describe('RedactionAssistDialog', () => {
	const props = { caseId: 'c 1', documentRef: 'd/1', initialText: 'Jan Jansen lives at Dorpsstraat 1.' }

	it('detects only within the text limit', async () => {
		const w = await mountIt(RedactionAssistDialog, props)
		expect(w.vm.canDetect).toBe(true)
		w.vm.text = '   '
		expect(w.vm.canDetect).toBe(false)
		w.vm.text = 'x'.repeat(12001)
		expect(w.vm.canDetect).toBe(false)
	})

	it('asks for a proposal and selects every span to start with', async () => {
		axios.post.mockResolvedValueOnce({ data: { spans: [{ start: 0, end: 10, source: 'ner' }, { start: 20, end: 33, source: 'rule' }] } })
		const w = await mountIt(RedactionAssistDialog, props)
		await w.vm.detect()
		expect(axios.post).toHaveBeenCalledWith(`${D}/cases/c%201/woo/documents/d%2F1/redaction-proposal`, { text: props.initialText })
		expect(w.vm.selections).toEqual({ 0: true, 1: true })
		expect([w.vm.isToggleable(w.vm.proposal.spans[0]), w.vm.isToggleable(w.vm.proposal.spans[1])]).toEqual([true, false])
		expect(w.vm.previewFor({ start: 0, end: 10 })).toMatch(/^…Jan Jansen lives at\s…$/)
	})

	it('sends only the selected spans with the decision', async () => {
		axios.post.mockResolvedValueOnce({ data: { spans: [{ start: 0 }, { start: 5 }] } })
		const w = await mountIt(RedactionAssistDialog, props)
		await w.vm.detect()
		w.vm.toggleSpan(1, false)
		axios.post.mockResolvedValueOnce({ data: { applied: 1 } })
		await w.vm.approve()
		expect(axios.post.mock.calls[1]).toEqual([`${D}/cases/c%201/woo/documents/d%2F1/redaction-proposal/review`, { decision: 'approve', spans: [{ start: 0 }] }])
		expect(w.emitted('reviewed')).toEqual([[{ applied: 1 }]])
		expect(w.emitted('close')).toHaveLength(1)
		axios.post.mockResolvedValueOnce({ data: {} })
		await w.vm.reject()
		expect(axios.post.mock.calls[2][1].decision).toBe('reject')
	})

	it('shows a failed detection or review', async () => {
		const w = await mountIt(RedactionAssistDialog, props)
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Text too long' } } })
		await w.vm.detect()
		expect([w.vm.errorMessage, w.vm.detecting]).toEqual(['Text too long', false])
		w.vm.proposal = { spans: [] }
		axios.post.mockRejectedValueOnce(new Error('offline'))
		await w.vm.review('approve')
		expect([w.vm.errorMessage, w.vm.submitting]).toEqual(['offline', false])
		expect(w.emitted('close')).toBeUndefined()
	})
})

describe('FileContactMomentDialog', () => {
	it('searches cases from two characters, leaving out the current case', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 'c1', identifier: 'Z-1', title: 'This one' }, { id: 'c2', identifier: 'Z-2', title: 'Permit' }, { id: 'c3', title: 'Untitled' }] } })
		const w = await mountIt(FileContactMomentDialog, { caseId: 'c1', moment: { id: 'm1', channel: 'phone', summary: 'Called back' } })
		expect(w.vm.momentLabel).toBe('phone: Called back')
		w.vm.query = ' a '
		await w.vm.search()
		expect([axios.get.mock.calls.length, w.vm.searched]).toEqual([0, false])
		w.vm.query = 'permit'
		await w.vm.search()
		expect(axios.get.mock.calls[0][1]).toEqual({ params: { _search: 'permit', _limit: 10 } })
		expect(w.vm.results).toEqual([{ id: 'c2', label: 'Z-2 · Permit' }, { id: 'c3', label: 'Untitled' }])
		expect(w.vm.searched).toBe(true)
	})

	it('shows a failed search', async () => {
		axios.get.mockRejectedValueOnce({})
		const w = await mountIt(FileContactMomentDialog, { caseId: 'c1', row: { id: 'm1', subject: 'Mail' } })
		expect(w.vm.momentLabel).toBe('Mail')
		w.vm.query = 'permit'
		await w.vm.search()
		expect([w.vm.results, w.vm.error]).toEqual([[], 'Those words could not be searched for.'])
	})

	it('files the moment on the chosen case and refreshes the page, or shows the refusal', async () => {
		const w = await mountIt(FileContactMomentDialog, { caseId: 'c1', moment: { id: 'm1' } })
		w.vm.target = 'c2'
		await w.vm.confirm()
		expect(pipelinq.fileContactMoment).toHaveBeenCalledWith('c1', 'm1', 'c2')
		expect(w.emitted('filed')).toHaveLength(1)
		expect(bus).toEqual(['cn:page:refresh'])
		pipelinq.fileContactMoment.mockRejectedValueOnce({ response: { data: { error: 'Case closed' } } })
		await w.vm.confirm()
		pipelinq.fileContactMoment.mockRejectedValueOnce({})
		await w.vm.confirm()
		expect([w.vm.error, w.vm.busy]).toEqual(['The contact moment could not be filed on that case.', false])
		expect(w.emitted('close')).toHaveLength(1)
	})
})

describe('DelegationImportPanel', () => {
	it('previews the pasted import and approves it by its id', async () => {
		axios.post.mockResolvedValueOnce({ data: { importId: 'imp 1', rows: 3 } }).mockResolvedValueOnce({})
		const w = await mountIt(DelegationImportPanel)
		w.vm.raw = 'role;decision'
		await w.vm.preview()
		expect(axios.post.mock.calls[0]).toEqual([`${D}/mandate/import`, { payload: 'role;decision' }])
		expect([w.vm.previewResult, w.vm.importId]).toEqual([{ importId: 'imp 1', rows: 3 }, 'imp 1'])
		await w.vm.approve()
		expect(axios.post.mock.calls[1][0]).toBe(`${D}/mandate/import/imp%201/approve`)
		expect(w.emitted('imported')).toHaveLength(1)
		expect([w.vm.raw, w.vm.previewResult, w.vm.importId]).toEqual(['', null, null])
		await w.vm.approve()
		expect(axios.post).toHaveBeenCalledTimes(2)
	})

	it('reads the import id from an id field, and shows failures', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 'x' } })
		const w = await mountIt(DelegationImportPanel)
		await w.vm.preview()
		expect(w.vm.importId).toBe('x')
		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Bad column' } } })
		await w.vm.approve()
		expect([w.vm.error, w.vm.approving]).toEqual(['Bad column', false])
		axios.post.mockRejectedValueOnce({})
		await w.vm.preview()
		expect([w.vm.error, w.vm.running]).toEqual(['Preview failed', false])
	})

	it('reads a chosen file into the import text', async () => {
		const w = await mountIt(DelegationImportPanel)
		w.vm.onFileChange({ target: { files: [] } })
		w.vm.onFileChange({ target: { files: [new File(['a;b'], 'm.csv')] } })
		await new Promise((resolve) => setTimeout(resolve, 20))
		expect(w.vm.raw).toBe('a;b')
	})
})
