// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dialog that scores a bid with the formula the case names: it reads the
 * case's scoring sheet, asks only for the values the formula takes per bid,
 * posts them, and shows the server's total or its refusal. It computes
 * nothing itself. Also checks that the tender case page offers it as a header
 * action.
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BidScoreDialog from '../../src/dialogs/BidScoreDialog.vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => mockGet(...a),
		post: (...a) => mockPost(...a),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
const mockSuccess = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (...a) => mockSuccess(...a) }))

const STUBS = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: { props: ['modelValue', 'options'], template: '<select />' },
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	Scale: true,
}

const SHEET = {
	formula: { key: 'price-quality', title: 'Best price-quality ratio' },
	inputs: ['quality'],
	fixed: { qualityWeight: 0.6, priceWeight: 0.4, maxPoints: 100 },
	submissions: [
		{ id: 'bid-a', label: 'INS-A', price: 80000, total: null, scores: {} },
		{ id: 'bid-b', label: 'INS-B', price: 100000, total: 74, scores: { quality: 70 } },
	],
	refusal: null,
}

/**
 * Mount the dialog and wait for its load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const wrapper = mount(BidScoreDialog, {
		props: { caseId: 'case-1' },
		global: { stubs: STUBS },
	})
	await flushPromises()
	return wrapper
}

describe('BidScoreDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockGet.mockResolvedValue({ data: structuredClone(SHEET) })
		mockPost.mockResolvedValue({ data: { evaluationId: 'evaluation-1', total: 70 } })
	})

	it('reads the case\'s scoring sheet and offers its bids with their price and score so far', async () => {
		const wrapper = await mountDialog()

		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/scoring')
		expect(wrapper.vm.bidOptions).toEqual([
			{ id: 'bid-a', label: 'INS-A · price 80,000' },
			{ id: 'bid-b', label: 'INS-B · price 100,000 · scored 74' },
		])
		expect(wrapper.find('[data-testid="bid-score-formula"]').text()).toBe('Scored with: Best price-quality ratio')
		expect(wrapper.find('[data-testid="bid-score-fixed"]').text())
			.toBe('From the case: Quality weight 0.6, Price weight 0.4, Maximum points 100')
	})

	it('asks only for the values the formula takes per bid', async () => {
		const wrapper = await mountDialog()

		expect(wrapper.findAll('[data-testid^="bid-score-input-"]').map((w) => w.attributes('data-testid')))
			.toEqual(['bid-score-input-quality'])
		expect(wrapper.vm.labelOfInput('quality')).toBe('Quality score')
		expect(wrapper.vm.labelOfInput('effort')).toBe('effort')
	})

	it('starts from the scores the evaluator gave the bid before', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.bidId = 'bid-b'
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.scores).toEqual({ quality: 70 })
		expect(wrapper.vm.canScore).toBe(true)
	})

	it('posts the entered values and shows the server\'s total', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.bidId = 'bid-a'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canScore).toBe(false)
		await wrapper.find('[data-testid="bid-score-input-quality"]').setValue('50')
		expect(wrapper.vm.canScore).toBe(true)

		await wrapper.vm.score()
		await wrapper.vm.$nextTick()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/scoring', { bid: 'bid-a', scores: { quality: 50 } })
		expect(wrapper.find('[data-testid="bid-score-total"]').text()).toBe('Total: 70')
		expect(wrapper.vm.bidOptions[0].label).toBe('INS-A · price 80,000 · scored 70')
		expect(mockSuccess).toHaveBeenCalledWith('Bid scored.')
		expect(wrapper.emitted('done')).toEqual([[{ evaluationId: 'evaluation-1', total: 70 }]])
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('keeps the server\'s refusal in the dialog', async () => {
		mockPost.mockRejectedValue({ response: { data: { message: 'This bid names no price, and the scoring formula scores the price.' } } })
		const wrapper = await mountDialog()
		wrapper.vm.bidId = 'bid-a'
		await wrapper.vm.$nextTick()
		wrapper.vm.setScore('quality', '50')

		await wrapper.vm.score()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="bid-score-error"]').text())
			.toBe('This bid names no price, and the scoring formula scores the price.')
		expect(wrapper.vm.total).toBe(null)
		expect(wrapper.vm.saving).toBe(false)
	})

	it('shows why nothing can be scored and offers no score button', async () => {
		mockGet.mockResolvedValue({ data: { ...SHEET, formula: null, inputs: [], submissions: [], refusal: 'This case names no scoring formula. Set the scoring formula on the case first.' } })
		const wrapper = await mountDialog()

		expect(wrapper.find('[data-testid="bid-score-refusal"]').text())
			.toBe('This case names no scoring formula. Set the scoring formula on the case first.')
		expect(wrapper.find('[data-testid="bid-score-submit"]').attributes('disabled')).toBeDefined()
	})

	it('falls back to its own sentence when the read fails without one', async () => {
		mockGet.mockRejectedValue(new Error('network'))
		const wrapper = await mountDialog()

		expect(wrapper.vm.error).toBe('The bids of this case could not be read.')
	})
})

describe('bid-score header action', () => {
	const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))

	/**
	 * Find the header action by id anywhere in the manifest.
	 *
	 * @param {*} node A manifest node.
	 * @return {object|null} The action.
	 */
	function findAction(node) {
		if (Array.isArray(node)) {
			for (const item of node) {
				const hit = findAction(item)
				if (hit) return hit
			}
			return null
		}
		if (node && typeof node === 'object') {
			if (node.id === 'bid-score') return node
			for (const value of Object.values(node)) {
				const hit = findAction(value)
				if (hit) return hit
			}
		}
		return null
	}

	it('opens the dialog for the case on a tender case type that is not archived', () => {
		const action = findAction(manifest)

		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('BidScoreDialog')
		expect(action.props).toEqual({ caseId: '@objectId' })
		expect(action.visibleWhen.all).toEqual([
			{ field: '@context.caseType.tenderChain', op: 'eq', value: true },
			{ field: '@self.archived', op: 'empty' },
		])
	})
})
