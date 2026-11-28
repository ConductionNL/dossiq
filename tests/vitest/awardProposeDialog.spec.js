// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Propose the award from a tender case: the dialog lists the admissible bids
 * of the scoring sheet highest total first, preselects the best scored one
 * (or the one proposed now), walks the bids' lifecycle (release the lot's
 * earlier proposal, then propose the chosen bid) and keeps a refusal as the
 * server words it. Also checks the header action is offered on a tender case
 * type only and that the registry knows the dialog.
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AwardProposeDialog from '../../src/dialogs/AwardProposeDialog.vue'

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
	HandshakeOutline: true,
}

const SHEET = {
	formula: { key: 'price-quality', title: 'Best price-quality ratio' },
	submissions: [
		{ id: 'bid-a', label: 'INS-A', price: 80000, total: 74, state: 'evaluated', lot: 'lot-1' },
		{ id: 'bid-b', label: 'INS-B', price: 100000, total: 81.5, state: 'evaluated', lot: 'lot-1' },
		{ id: 'bid-c', label: 'INS-C', price: 90000, total: null, state: 'admissible', lot: 'lot-1' },
		{ id: 'bid-d', label: 'INS-D', price: 70000, total: 60, state: 'proposed-award', lot: 'lot-2' },
	],
	refusal: null,
}

/**
 * Mount the dialog for case-1 and wait for its read.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const wrapper = mount(AwardProposeDialog, { props: { caseId: 'case-1' }, global: { stubs: STUBS } })
	await flushPromises()
	return wrapper
}

describe('propose the award', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockGet.mockResolvedValue({ data: SHEET })
		mockPost.mockResolvedValue({ data: {} })
	})

	it('lists the bids highest total first and proposes the best scored one', async () => {
		const wrapper = await mountDialog()

		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/scoring')
		expect(wrapper.vm.bidOptions.map((option) => option.id)).toEqual(['bid-b', 'bid-a', 'bid-d', 'bid-c'])
		expect(wrapper.vm.bidOptions[0].label).toContain('INS-B')
		expect(wrapper.vm.bidId).toBe('bid-b')
		expect(wrapper.find('[data-testid="award-propose-current"]').exists()).toBe(false)

		await wrapper.find('[data-testid="award-propose-submit"]').trigger('click')
		await flushPromises()

		// Lot 2's proposal is another lot's: it is not released.
		expect(mockPost).toHaveBeenCalledTimes(1)
		expect(mockPost).toHaveBeenCalledWith('/apps/openregister/api/objects/bid-b/transition', { action: 'proposeAward' })
		expect(mockSuccess).toHaveBeenCalledWith('Award proposed.')
		expect(wrapper.emitted('done')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('starts from the bid proposed now and releases it when another bid of its lot is proposed', async () => {
		mockGet.mockResolvedValue({ data: { ...SHEET, submissions: SHEET.submissions.filter((row) => row.id !== 'bid-d').map((row) => (row.id === 'bid-a' ? { ...row, state: 'proposed-award' } : row)) } })
		const wrapper = await mountDialog()

		expect(wrapper.vm.bidId).toBe('bid-a')
		expect(wrapper.find('[data-testid="award-propose-current"]').text()).toContain('INS-A')
		expect(wrapper.vm.canPropose).toBe(false)

		wrapper.vm.bidId = 'bid-b'
		await flushPromises()
		expect(wrapper.vm.canPropose).toBe(true)
		await wrapper.find('[data-testid="award-propose-submit"]').trigger('click')
		await flushPromises()

		expect(mockPost.mock.calls).toEqual([
			['/apps/openregister/api/objects/bid-a/transition', { action: 'releaseAward' }],
			['/apps/openregister/api/objects/bid-b/transition', { action: 'proposeAward' }],
		])
	})

	it('shows the sheet refusal when nothing can be proposed', async () => {
		mockGet.mockResolvedValue({ data: { formula: null, submissions: [], refusal: 'This case names no scoring formula.' } })
		const wrapper = await mountDialog()

		expect(wrapper.find('[data-testid="award-propose-refusal"]').text()).toBe('This case names no scoring formula.')
		expect(wrapper.vm.canPropose).toBe(false)
	})

	it('keeps a refused proposal in the dialog as the server words it', async () => {
		mockPost.mockRejectedValue({ response: { data: { message: "Transition 'proposeAward' is not allowed from 'received'." } } })
		const wrapper = await mountDialog()
		await wrapper.find('[data-testid="award-propose-submit"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="award-propose-error"]').text()).toBe("Transition 'proposeAward' is not allowed from 'received'.")
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})

describe('the award header action', () => {
	const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
	const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
	const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')

	it('opens AwardProposeDialog for the case on a tender case type that is not archived', () => {
		const action = caseDetail.config.headerActions.find((row) => row.id === 'award-propose')

		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('AwardProposeDialog')
		expect(action.props).toEqual({ caseId: '@objectId' })
		expect(action.visibleWhen.all).toEqual([
			{ field: '@context.caseType.tenderChain', op: 'eq', value: true },
			{ field: '@self.archived', op: 'empty' },
		])
		expect(registry).toContain('\tAwardProposeDialog: {')
	})
})
