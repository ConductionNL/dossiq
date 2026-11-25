// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The grant actions of a case page: record an interim report, assess one as
 * sufficient and settle the grant. Each dialog reads the case's grant chain,
 * picks the only row for the handler, posts to the existing grant route and
 * keeps a refusal in the dialog. Also checks that the case page offers the
 * three actions on a grant case type only, and that the registry knows them.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GrantSettleDialog from '../../src/dialogs/GrantSettleDialog.vue'
import InterimReportAssessDialog from '../../src/dialogs/InterimReportAssessDialog.vue'
import InterimReportRecordDialog from '../../src/dialogs/InterimReportRecordDialog.vue'

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
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcTextArea: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<textarea v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	FileChartOutline: true,
	CheckDecagramOutline: true,
	ScaleBalance: true,
}

const EXECUTION = {
	id: 'execution-1',
	applicationId: 'application-1',
	applicationNumber: 'SUB-2026-1',
	status: 'in_execution',
	grantedAmount: 50000,
	advancesPaid: 40000,
	interimReports: [
		{ id: 'report-1', number: 'TR-1', periodStart: '2026-01-01', periodEnd: '2026-06-30', status: 'expected', opinion: '', assessed: false },
		{ id: 'report-0', number: 'TR-0', periodStart: '', periodEnd: '', status: 'approved', opinion: 'voldoende', assessed: true },
	],
	settlements: [
		{ id: 'settlement-1', actualCostTotal: 35000, determinedAmount: null, status: 'draft' },
		{ id: 'settlement-0', actualCostTotal: 1000, determinedAmount: 1000, status: 'determined' },
	],
}

/**
 * Mount a dialog for case-1 and wait for its read.
 *
 * @param {object} component The dialog.
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog(component) {
	const wrapper = mount(component, { props: { caseId: 'case-1' }, global: { stubs: STUBS } })
	await flushPromises()
	return wrapper
}

describe('grant dialogs', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockGet.mockResolvedValue({ data: { empty: false, executions: [EXECUTION] } })
		mockPost.mockResolvedValue({ data: { id: 'new' } })
	})

	it('records an interim report on the only grant of the case', async () => {
		const wrapper = await mountDialog(InterimReportRecordDialog)

		expect(mockGet).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/grant-chain')
		expect(wrapper.vm.executionId).toBe('execution-1')
		expect(wrapper.find('[data-testid="interim-report-record-execution"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="interim-report-record-submit"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="interim-report-record-number"]').setValue(' TR-2 ')
		await wrapper.find('[data-testid="interim-report-record-start"]').setValue('2026-07-01')
		await wrapper.find('[data-testid="interim-report-record-end"]').setValue('2026-12-31')
		await wrapper.find('[data-testid="interim-report-record-progress"]').setValue('On plan.')
		await wrapper.find('[data-testid="interim-report-record-submit"]').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/subsidies/uitvoeringen/execution-1/tussenrapportages', {
			rapportagenummer: 'TR-2',
			rapportagePeriodStart: '2026-07-01',
			rapportagePeriodEnd: '2026-12-31',
			substantiveProgress: 'On plan.',
		})
		expect(mockSuccess).toHaveBeenCalledWith('Interim report recorded.')
		expect(wrapper.emitted('done')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('does not record a period that ends before it starts', async () => {
		const wrapper = await mountDialog(InterimReportRecordDialog)
		await wrapper.find('[data-testid="interim-report-record-number"]').setValue('TR-2')
		await wrapper.find('[data-testid="interim-report-record-start"]').setValue('2026-07-01')
		await wrapper.find('[data-testid="interim-report-record-end"]').setValue('2026-06-30')

		expect(wrapper.vm.canRecord).toBe(false)
	})

	it('keeps a refused record in the dialog as the server words it', async () => {
		mockPost.mockRejectedValue({ response: { data: { error: 'Kon tussenrapportage niet aanmaken' } } })
		const wrapper = await mountDialog(InterimReportRecordDialog)
		await wrapper.find('[data-testid="interim-report-record-number"]').setValue('TR-2')
		await wrapper.find('[data-testid="interim-report-record-submit"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="interim-report-record-error"]').text()).toBe('Kon tussenrapportage niet aanmaken')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('says so when the case has no grant in execution', async () => {
		mockGet.mockResolvedValue({ data: { empty: true, executions: [] } })
		const wrapper = await mountDialog(InterimReportRecordDialog)

		expect(wrapper.find('[data-testid="interim-report-record-empty"]').exists()).toBe(true)
	})

	it('offers a picker when the case has more than one grant', async () => {
		mockGet.mockResolvedValue({ data: { executions: [EXECUTION, { ...EXECUTION, id: 'execution-2', applicationNumber: '' }] } })
		const wrapper = await mountDialog(InterimReportRecordDialog)

		expect(wrapper.vm.executionId).toBeNull()
		expect(wrapper.vm.executionOptions).toEqual([
			{ id: 'execution-1', label: 'Grant SUB-2026-1' },
			{ id: 'execution-2', label: 'Grant execution-2' },
		])
	})

	it('assesses the only open interim report as voldoende', async () => {
		const wrapper = await mountDialog(InterimReportAssessDialog)

		expect(wrapper.vm.reportOptions).toEqual([{ id: 'report-1', label: 'TR-1 · 2026-01-01 – 2026-06-30 · Grant SUB-2026-1' }])
		expect(wrapper.vm.reportId).toBe('report-1')

		await wrapper.find('[data-testid="interim-report-assess-amount"]').setValue('12000')
		await wrapper.find('[data-testid="interim-report-assess-submit"]').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/subsidies/tussenrapportages/report-1/beoordelen', {
			beoordelingsoordeel: 'voldoende',
			approvedAmount: 12000,
		})
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('sends no amount when none is named and refuses a negative one', async () => {
		const wrapper = await mountDialog(InterimReportAssessDialog)
		await wrapper.find('[data-testid="interim-report-assess-amount"]').setValue('-1')
		expect(wrapper.vm.canAssess).toBe(false)

		await wrapper.find('[data-testid="interim-report-assess-amount"]').setValue('')
		await wrapper.find('[data-testid="interim-report-assess-submit"]').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/subsidies/tussenrapportages/report-1/beoordelen', { beoordelingsoordeel: 'voldoende' })
	})

	it('says so when no report waits for an assessment, and when the read fails', async () => {
		mockGet.mockResolvedValue({ data: { executions: [{ ...EXECUTION, interimReports: [EXECUTION.interimReports[1]] }] } })
		let wrapper = await mountDialog(InterimReportAssessDialog)
		expect(wrapper.find('[data-testid="interim-report-assess-empty"]').exists()).toBe(true)

		mockGet.mockRejectedValue({ response: { data: { message: 'You may not read this case.' } } })
		wrapper = await mountDialog(InterimReportAssessDialog)
		expect(wrapper.find('[data-testid="interim-report-assess-error"]').text()).toBe('You may not read this case.')
		expect(wrapper.find('[data-testid="interim-report-assess-empty"]').exists()).toBe(false)
	})

	it('settles the open settlement with the figures its grant holds and reports the proposed recovery', async () => {
		mockPost.mockResolvedValue({
			data: { determination: { determinedAmount: 35000, recoveryTrigger: true }, terugvordering: { amount: 5000, status: 'draft' } },
		})
		const wrapper = await mountDialog(GrantSettleDialog)

		expect(wrapper.vm.settlementId).toBe('settlement-1')
		expect(wrapper.vm.figures).toEqual({ granted: '50000', actual: '35000', advances: '40000' })

		await wrapper.find('[data-testid="grant-settle-submit"]').trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/subsidies/vaststellingen/settlement-1/vast', {
			grantedAmount: 50000,
			werkelijkeKosten: 35000,
			totaalVoorschotten: 40000,
		})
		const message = mockSuccess.mock.calls[0][0]
		expect(message).toMatch(/^Grant settled at .*35.000.*\. A recovery of .*5.000.* is proposed to the manager\.$/)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('does not settle while a figure is empty', async () => {
		mockGet.mockResolvedValue({ data: { executions: [{ ...EXECUTION, advancesPaid: null }] } })
		const wrapper = await mountDialog(GrantSettleDialog)

		expect(wrapper.vm.figures.advances).toBe('')
		expect(wrapper.vm.canSettle).toBe(false)
		await wrapper.find('[data-testid="grant-settle-advances"]').setValue('0')
		expect(wrapper.vm.canSettle).toBe(true)
	})

	it('says a settlement without a recovery in one sentence', async () => {
		const wrapper = await mountDialog(GrantSettleDialog)

		expect(wrapper.vm.outcomeOf({})).toBe('Grant settled.')
		expect(wrapper.vm.outcomeOf({ determination: { determinedAmount: 50000 } })).toMatch(/^Grant settled at .*50.000.*\.$/)
	})
})

describe('grant header actions', () => {
	const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
	const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
	const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')
	const ACTIONS = {
		'interim-report-record': 'InterimReportRecordDialog',
		'interim-report-assess': 'InterimReportAssessDialog',
		'grant-settle': 'GrantSettleDialog',
	}

	it.each(Object.entries(ACTIONS))('%s opens %s for the case on a grant case type that is not archived', (id, target) => {
		const action = caseDetail.config.headerActions.find((row) => row.id === id)

		expect(action.type).toBe('open-modal')
		expect(action.target).toBe(target)
		expect(action.props).toEqual({ caseId: '@objectId' })
		expect(action.visibleWhen.all).toEqual([
			{ field: '@context.caseType.grantChain', op: 'eq', value: true },
			{ field: '@self.archived', op: 'empty' },
		])
		expect(registry).toContain(`\t${target}: {`)
	})
})
