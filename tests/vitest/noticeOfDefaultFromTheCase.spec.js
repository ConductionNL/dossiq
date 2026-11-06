// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A notice of default is registered from the case, and a definitive penalty
 * is prepared for payout from its notice row.
 *
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-definitive-penalty-is-prepared-for-payout-from-its-notice-req-ing-case-02
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NoticeOfDefaultDialog from '../../src/dialogs/NoticeOfDefaultDialog.vue'
import PayoutPrepareDialog from '../../src/dialogs/PayoutPrepareDialog.vue'
import { noticeRowOf } from '../../src/utils/noticePayout.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const nl = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8'),
).translations
const CASE_ID = '11111111-1111-4111-8111-111111111111'

const stubs = {
	CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcDateTimePicker: true,
	NcSelect: true,
	NcTextField: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template:
			'<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

/**
 * The axios double.
 *
 * @return {Promise<object>} The mocked axios.
 */
async function axiosDouble() {
	return (await import('@nextcloud/axios')).default
}

describe('the case page registers a notice of default', () => {
	beforeEach(async () => {
		const axios = await axiosDouble()
		axios.get.mockReset()
		axios.post.mockReset()
		axios.get.mockResolvedValue({
			data: {
				results: [
					{ displayName: 'J. de Vries' },
					{ name: 'J. de Vries' },
					{ name: 'Bouwbedrijf Zuid' },
				],
			},
		})
	})

	it('offers the action only on a case whose case type carries a penalty', () => {
		const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
		const action = caseDetail.config.headerActions.find(
			(entry) => entry.id === 'notice-of-default-register',
		)
		expect(action.target).toBe('NoticeOfDefaultDialog')
		expect(action.props.caseId).toBe('@objectId')
		const gate = action.visibleWhen
		expect(gate).toEqual({
			endpoint: '/apps/dossiq/api/case-types/@object.caseType/terms',
			field: 'penaltyApplies',
			op: 'eq',
			value: true,
		})
		expect(registrySource).toContain('NoticeOfDefaultDialog: {')
		expect(nl[action.label]).toBe('Ingebrekestelling registreren')
	})

	it('offers the case parties as the sender and posts the notice with the case id', async () => {
		const axios = await axiosDouble()
		axios.post.mockResolvedValue({
			data: {
				id: 'nod-1',
				penaltyPaymentCalculation: { startDate: '2026-03-29' },
			},
		})
		const wrapper = mount(NoticeOfDefaultDialog, {
			props: { caseId: CASE_ID },
			global: { stubs },
		})
		await flushPromises()

		expect(wrapper.vm.parties).toEqual(['J. de Vries', 'Bouwbedrijf Zuid'])
		wrapper.vm.receiptDate = new Date(2026, 2, 15)
		wrapper.vm.sender = ' J. de Vries '
		await wrapper
			.find('[data-testid="notice-of-default-confirm"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/apps/dossiq/api/notices-of-default'),
			{
				caseId: CASE_ID,
				receiptDate: '2026-03-15',
				notificationChannel: 'post',
				sender: 'J. de Vries',
			},
		)
		expect(
			wrapper.find('[data-testid="notice-of-default-saved"]').text(),
		).toContain('2026-03-29')
	})

	it('says the term has not passed when the server refuses, and stays open', async () => {
		const axios = await axiosDouble()
		axios.post.mockRejectedValue({
			response: {
				status: 409,
				data: {
					refusal: 'term-running',
					termEnds: '2026-03-25',
					message: 'x',
				},
			},
		})
		const wrapper = mount(NoticeOfDefaultDialog, {
			props: { caseId: CASE_ID },
			global: { stubs },
		})
		await flushPromises()

		await wrapper
			.find('[data-testid="notice-of-default-confirm"]')
			.trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="notice-of-default-error"]').text()).toBe(
			'The decision term runs until 2026-03-25. A notice of default only counts once the term has passed.',
		)
		expect(
			wrapper.find('[data-testid="notice-of-default-saved"]').exists(),
		).toBe(false)
	})
})

describe('a definitive penalty is prepared for payout from its notice', () => {
	const ROW = {
		id: 'nod-1',
		caseLabel: '2026-4863 Kapvergunning',
		accruingAmountId: 'calc-1',
		penaltyState: 'definitive',
		penaltyEuros: 1442,
		receiptDate: '2026-08-07',
		financeState: 'payout-due',
	}

	it('puts the payout action on the notices card, on rows ready for payout', () => {
		const page = manifest.pages.find((entry) =>
			(entry.config?.widgets || []).some(
				(w) =>
					w.content?.endpointSource?.url
					=== '/apps/dossiq/api/deadlines/notices',
			),
		)
		const widget = page.config.widgets.find(
			(w) =>
				w.content?.endpointSource?.url
				=== '/apps/dossiq/api/deadlines/notices',
		)
		const action = widget.content.actions.find(
			(entry) => entry.id === 'payout-prepare',
		)
		expect(action).toMatchObject({
			type: 'handler',
			handler: 'prepareNoticePayout',
		})
		expect(action.visibleWhen).toEqual({
			field: 'financeState',
			op: 'eq',
			value: 'payout-due',
		})
		const finance = widget.content.columns.find((c) => c.key === 'financeState')
		expect(nl[finance.enumLabels['signal-sent']]).toBe('Betaalsignaal verstuurd')
		expect(nl[finance.enumLabels['payout-due']]).toBe('Klaar voor uitbetaling')
		expect(nl[action.label]).toBe('Uitbetaling voorbereiden')
		expect(registrySource).toContain('prepareNoticePayout: {')
	})

	it('reads the row from either dispatch shape', () => {
		expect(noticeRowOf([{ actionId: 'payout-prepare', item: ROW }])).toBe(ROW)
		expect(noticeRowOf(['x', ROW])).toBe(ROW)
		expect(noticeRowOf([])).toBeNull()
	})

	it('raises the payout through the payout route and reads the signal', async () => {
		const axios = await axiosDouble()
		axios.post.mockReset()
		axios.post.mockResolvedValue({ data: { payableId: 'pay-1' } })
		const wrapper = mount(PayoutPrepareDialog, {
			props: { notice: ROW },
			global: { stubs },
		})

		expect(
			wrapper
				.find('[data-testid="payout-prepare-confirm"]')
				.attributes('disabled'),
		).toBeDefined()
		await wrapper
			.find('[data-testid="payout-prepare-holder"]')
			.setValue('J. de Vries')
		await wrapper
			.find('[data-testid="payout-prepare-iban"]')
			.setValue('NL91ABNA0417164300')
		await wrapper.find('[data-testid="payout-prepare-confirm"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining(
				'/apps/dossiq/api/accruing-amounts/calc-1/payout',
			),
			{
				accountHolderName: 'J. de Vries',
				iban: 'NL91ABNA0417164300',
				receiptDate: '2026-08-07',
			},
		)
		expect(
			wrapper.find('[data-testid="payout-prepare-saved"]').text(),
		).toContain('Payment signal sent')
	})

	it('shows the refusal and stays open when the payout is refused', async () => {
		const axios = await axiosDouble()
		axios.post.mockReset()
		axios.post.mockRejectedValue({
			response: {
				status: 400,
				data: { message: 'Invalid IBAN provided for dwangsom uitbetaling' },
			},
		})
		const wrapper = mount(PayoutPrepareDialog, {
			props: { notice: ROW },
			global: { stubs },
		})

		await wrapper
			.find('[data-testid="payout-prepare-holder"]')
			.setValue('J. de Vries')
		await wrapper.find('[data-testid="payout-prepare-iban"]').setValue('NL00')
		await wrapper.find('[data-testid="payout-prepare-confirm"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="payout-prepare-error"]').text()).toBe(
			'Invalid IBAN provided for dwangsom uitbetaling',
		)
	})
})
