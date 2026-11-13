// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Document review panel actions: declaring the stopping rule, judging a
 * sampled document, applying, switching and deleting triage rules, the
 * dialogs, and the sentence a rule's conditions read as.
 *
 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

const { default: DocumentReviewPanel } =
	await import('../../src/components/case/DocumentReviewPanel.vue')

const BASE = '/index.php/apps/dossiq/api/cases/case%201/review'

/**
 * Answer the panel's reads with review switched off, so mounting stays quiet.
 *
 * @return {void}
 */
function answerOff() {
	axios.get.mockImplementation((url) => {
		if (url.endsWith('/review/summary')) {
			return Promise.resolve({ data: { enabled: false } })
		}
		return Promise.resolve({ data: {} })
	})
}

/**
 * Mount the panel on "case 1" and let its first read settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel() {
	const wrapper = mount(DocumentReviewPanel, {
		props: { objectId: 'case 1' },
		global: {
			stubs: {
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				NcEmptyContent: true,
				NcLoadingIcon: true,
				AlertCircleOutline: true,
				RecallSampleDialog: true,
				ReviewBatchDialog: true,
				TriageRuleDialog: true,
				NcActions: { template: '<div><slot /></div>' },
				NcActionButton: { template: '<button v-bind="$attrs"><slot /></button>' },
			},
		},
	})
	await flushPromises()
	axios.get.mockClear()
	return wrapper
}

describe('DocumentReviewPanel actions', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.put.mockReset()
		axios.delete.mockReset()
		answerOff()
	})

	it('declares the stopping rule as a share and keeps the answer', async () => {
		const wrapper = await mountPanel()
		wrapper.vm.ruleForm = { target: 85, confidence: '0.95' }
		axios.post.mockResolvedValue({ data: { targetRecall: 0.85, confidence: 0.95 } })

		await wrapper.vm.declareRule()

		expect(axios.post).toHaveBeenCalledWith(BASE + '/stopping-rule', { targetRecall: 0.85, confidence: 0.95 })
		expect(wrapper.vm.rule).toEqual({ targetRecall: 0.85, confidence: 0.95 })
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.vm.actionError).toBe('')
	})

	it('shows a refused stopping rule with the server message or a plain sentence', async () => {
		const wrapper = await mountPanel()
		axios.post.mockRejectedValueOnce({ response: { data: { message: 'The rule is fixed.' } } })
		await wrapper.vm.declareRule()
		expect(wrapper.vm.actionError).toBe('The rule is fixed.')

		axios.post.mockRejectedValueOnce(new Error('offline'))
		await wrapper.vm.declareRule()
		expect(wrapper.vm.actionError).toBe('The stopping rule cannot be stored, so nothing was declared.')
		expect(wrapper.vm.saving).toBe(false)
	})

	it('lists the sampled documents not judged yet', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.vm.unjudged({ documents: ['d1', 2, 'd3'], judgements: { d1: true } })).toEqual(['2', 'd3'])
		expect(wrapper.vm.unjudged({ documents: ['d1'], judgements: 'broken' })).toEqual(['d1'])
		expect(wrapper.vm.unjudged({})).toEqual([])
	})

	it('judges a sampled document and replaces only that sample', async () => {
		const wrapper = await mountPanel()
		wrapper.vm.samples = [{ id: 's/1', judgements: {} }, { id: 's-2', judgements: {} }]
		axios.post.mockResolvedValue({ data: { id: 's/1', judgements: { d1: false } } })

		await wrapper.vm.judge({ id: 's/1' }, 'd1', false)

		expect(axios.post).toHaveBeenCalledWith(BASE + '/recall/samples/s%2F1/judgements', { documentRef: 'd1', inScope: false })
		expect(wrapper.vm.samples).toEqual([{ id: 's/1', judgements: { d1: false } }, { id: 's-2', judgements: {} }])
	})

	it('shows a refused judgement and leaves the samples as they were', async () => {
		const wrapper = await mountPanel()
		wrapper.vm.samples = [{ id: 's-1' }]
		axios.post.mockRejectedValueOnce(new Error('offline'))

		await wrapper.vm.judge({ id: 's-1' }, 'd1', true)

		expect(wrapper.vm.actionError).toBe('The Recall sample cannot be stored, so nothing was drawn or judged.')
		expect(wrapper.vm.samples).toEqual([{ id: 's-1' }])
		expect(wrapper.vm.saving).toBe(false)
	})

	it('closes every dialog and reads the review again after a change', async () => {
		const wrapper = await mountPanel()
		wrapper.vm.showSampleDialog = true
		wrapper.vm.showBatchDialog = true
		wrapper.vm.showRuleDialog = true

		await wrapper.vm.onChanged()

		expect([wrapper.vm.showSampleDialog, wrapper.vm.showBatchDialog, wrapper.vm.showRuleDialog]).toEqual([false, false, false])
		expect(axios.get).toHaveBeenCalledWith(BASE + '/summary')
	})

	it('opens the rule dialog for a new rule or an existing one', async () => {
		const wrapper = await mountPanel()

		wrapper.vm.editRule(null)
		expect(wrapper.vm.editingRule).toBeNull()
		expect(wrapper.vm.showRuleDialog).toBe(true)

		wrapper.vm.editRule({ id: 'r-1' })
		expect(wrapper.vm.editingRule).toEqual({ id: 'r-1' })
	})

	it('reads every condition as one capitalised sentence joined by and', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.vm.conditionLine({
			custodian: 'anouk',
			sourceSystem: 'mail',
			senderDomain: 'example.nl',
			mimeType: 'application/pdf',
			textContains: 'subsidie',
			textNotContains: 'concept',
		})).toBe(
			'Custodian is anouk and source system is mail and sender domain is example.nl and file type is '
			+ 'application/pdf and the text contains subsidie and the text does not contain concept',
		)
	})

	it('writes a condition date as a long day, and an unreadable date as given', async () => {
		const wrapper = await mountPanel()
		const day = new Date('2025-03-04').toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' })

		expect(wrapper.vm.conditionLine({ dateFrom: '2025-03-04', dateTo: 'someday' }))
			.toBe('Dated from ' + day + ' and dated up to someday')
		expect(wrapper.vm.longDay('not a day')).toBe('not a day')
	})

	it('leaves out unknown and empty conditions, and reads none as an empty line', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.vm.conditionLine({ colour: 'red', custodian: '', mimeType: null, sourceSystem: 'dms' }))
			.toBe('Source system is dms')
		expect(wrapper.vm.conditionLine({})).toBe('')
		expect(wrapper.vm.conditionLine(null)).toBe('')
	})

	it('applies the rules, reads the review again and keeps what was applied', async () => {
		const wrapper = await mountPanel()
		axios.post.mockResolvedValue({ data: { marked: 4 } })

		await wrapper.vm.applyRules()

		expect(axios.post).toHaveBeenCalledWith(BASE + '/triage/apply')
		expect(axios.get).toHaveBeenCalledWith(BASE + '/summary')
		expect(wrapper.vm.applied).toEqual({ marked: 4 })
		expect(wrapper.vm.saving).toBe(false)
	})

	it('shows refused rule applications with the server message or a plain sentence', async () => {
		const wrapper = await mountPanel()
		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Review closed.' } } })
		await wrapper.vm.applyRules()
		expect(wrapper.vm.actionError).toBe('Review closed.')

		axios.post.mockRejectedValueOnce(new Error('offline'))
		await wrapper.vm.applyRules()
		expect(wrapper.vm.actionError).toBe('The triage rules cannot be stored or applied, so nothing was marked.')
	})

	it('switches an active rule off and an inactive rule on, then reads again', async () => {
		const wrapper = await mountPanel()
		axios.put.mockResolvedValue({ data: {} })

		await wrapper.vm.toggleRule({ id: 'r 1', active: true })
		await wrapper.vm.toggleRule({ id: 'r-2', active: false })
		await wrapper.vm.toggleRule({ id: 'r-3' })

		expect(axios.put.mock.calls).toEqual([
			[BASE + '/triage/rules/r%201', { active: false }],
			[BASE + '/triage/rules/r-2', { active: true }],
			[BASE + '/triage/rules/r-3', { active: false }],
		])
		expect(axios.get).toHaveBeenCalledWith(BASE + '/summary')
	})

	it('deletes a rule and shows a refused delete', async () => {
		const wrapper = await mountPanel()
		axios.delete.mockResolvedValueOnce({ data: {} })
		await wrapper.vm.deleteRule({ id: 'r-1' })
		expect(axios.delete).toHaveBeenCalledWith(BASE + '/triage/rules/r-1')
		expect(wrapper.vm.actionError).toBe('')

		axios.delete.mockRejectedValueOnce({ response: { data: { message: 'In use.' } } })
		await wrapper.vm.deleteRule({ id: 'r-1' })
		expect(wrapper.vm.actionError).toBe('In use.')

		axios.delete.mockRejectedValueOnce(new Error('offline'))
		await wrapper.vm.deleteRule({ id: 'r-1' })
		expect(wrapper.vm.actionError).toBe('The triage rules cannot be stored or applied, so nothing was marked.')
		expect(wrapper.vm.saving).toBe(false)
	})
})
