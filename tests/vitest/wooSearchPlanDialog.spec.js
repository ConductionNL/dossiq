// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The search plan of a Woo request.
 *
 * Record stays off until whose files, which systems, the period and the terms
 * are all given; the plan is sent to dossiq's PUT /woo/plan; and the history
 * is the plan's OpenRegister audit trail.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import WooSearchPlanDialog from '../../src/dialogs/WooSearchPlanDialog.vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const CASE_ID = '11111111-1111-4111-8111-111111111111'
const TEMPLATE = '77777777-7777-4777-8777-777777777777'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

/**
 * Mount the dialog over a case with the given plan.
 *
 * @param {object|null} plan What GET /woo/plan answers as `plan`.
 * @return {Promise<{wrapper: object, axios: object}>} The dialog and the axios double.
 */
async function mountDialog(plan = null) {
	const axios = (await import('@nextcloud/axios')).default
	axios.get.mockImplementation((url) => {
		if (String(url).endsWith('/woo/sources')) {
			return Promise.resolve({
				data: {
					sources: [
						{ id: 'files', label: 'Files', available: true },
						{
							id: 'microsoft365',
							label: 'SharePoint, Teams and mail',
							available: false,
						},
					],
				},
			})
		}
		if (String(url).endsWith('/woo/plan')) {
			return Promise.resolve({
				data: {
					plan,
					recorded: Boolean(plan?.recordedAt),
					templateSchema: '42',
				},
			})
		}
		if (String(url).endsWith('/api/record-templates')) {
			return Promise.resolve({
				data: {
					results: [
						{
							uuid: TEMPLATE,
							name: 'Ruimtelijke verzoeken',
							schema: '42',
							values: {
								custodians: [
									{
										name: 'Wethouder Ruimte',
										function: 'College',
									},
								],
								systems: ['files'],
								terms: 'Stationsweg',
								triageRules: ['kept by the server'],
							},
						},
					],
					total: 1,
				},
			})
		}
		if (String(url).includes('/audit-trails')) {
			return Promise.resolve({
				data: {
					results: [
						{
							id: 1,
							action: 'create',
							userName: 'P. Jansen',
							created: '2026-10-01T10:00:00',
						},
						{
							id: 2,
							action: 'update',
							userName: 'A. Bakker',
							created: '2026-10-02T09:30:00',
						},
					],
				},
			})
		}
		return Promise.reject(new Error(`unexpected GET ${url}`))
	})
	axios.put.mockImplementation((url, body) =>
		Promise.resolve({
			data: {
				plan: {
					id: 'plan-1',
					...body,
					recordedBy: 'pjansen',
					recordedAt: '2026-10-10T12:00:00Z',
				},
			},
		}),
	)

	const wrapper = mount(WooSearchPlanDialog, {
		props: { caseId: CASE_ID },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcButton: {
					props: ['disabled'],
					emits: ['click'],
					template:
						'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
				},
				NcTextField: {
					props: ['modelValue', 'label'],
					emits: ['update:modelValue'],
					template:
						'<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
				},
				NcSelect: {
					props: ['options'],
					emits: ['update:modelValue'],
					template:
						'<div data-testid="record-template-select"><button v-for="option in options" :key="option.uuid" class="template" @click="$emit(\'update:modelValue\', option)">{{ option.name }}</button></div>',
				},
				NcCheckboxRadioSwitch: {
					props: ['modelValue'],
					emits: ['update:modelValue'],
					template:
						'<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
				},
			},
		},
	})
	await flushPromises()

	return { wrapper, axios }
}

describe('WooSearchPlanDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('is a registered modal opened from a header action on the Woo case type only', () => {
		const caseDetail = manifest.pages.find((p) => p.id === 'CaseDetail')
		const action = caseDetail.config.headerActions.find(
			(a) => a.id === 'woo-search-plan',
		)
		expect(action.target).toBe('WooSearchPlanDialog')
		expect(action.visibleWhen.all).toContainEqual({
			field: 'caseTypeIdentifier',
			op: 'eq',
			value: 'woo-verzoek',
		})
		expect(registrySource).toMatch(/WooSearchPlanDialog: \{\s*kind: 'modal'/)
	})

	it('testCustodiansSystemsPeriodAndTermsAreRequired', async () => {
		const { wrapper, axios } = await mountDialog()
		const save = () => wrapper.find('[data-testid="woo-plan-save"]')

		expect(save().attributes('disabled')).toBeDefined()
		await wrapper
			.find('[data-testid="woo-plan-custodian-name-0"]')
			.setValue('Wethouder Ruimte')
		expect(save().attributes('disabled')).toBeDefined()
		await wrapper
			.find('[data-testid="woo-plan-system-files"] input')
			.setValue(true)
		expect(save().attributes('disabled')).toBeDefined()
		await wrapper.find('[data-testid="woo-plan-from"]').setValue('2025-12-31')
		await wrapper.find('[data-testid="woo-plan-to"]').setValue('2025-01-01')
		await wrapper.find('[data-testid="woo-plan-terms"]').setValue('Stationsweg')
		expect(
			save().attributes('disabled'),
			'a period that ends before it starts',
		).toBeDefined()
		await wrapper.find('[data-testid="woo-plan-from"]').setValue('2025-01-01')
		await wrapper.find('[data-testid="woo-plan-to"]').setValue('2025-12-31')
		expect(save().attributes('disabled')).toBeUndefined()

		await save().trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(
			expect.stringContaining(`/apps/dossiq/api/cases/${CASE_ID}/woo/plan`),
			{
				custodians: [{ name: 'Wethouder Ruimte', function: '' }],
				systems: ['files'],
				periodFrom: '2025-01-01',
				periodTo: '2025-12-31',
				terms: 'Stationsweg',
			},
		)
		expect(wrapper.find('[data-testid="woo-plan-recorded"]').text()).toBe(
			'Recorded by pjansen on 2026-10-10',
		)
	})

	it('testANewRequestStartsFromANamedTemplate', async () => {
		const { wrapper, axios } = await mountDialog()

		// The select and its read of /api/record-templates are the library's
		// CnTemplateSelect; the dialog hands it the schema and takes what it applies.
		const select = wrapper.findComponent({ name: 'CnTemplateSelect' })
		expect(select.props('schema')).toBe('42')
		expect(select.props('inputLabel')).toBe('Start from a template')
		select.vm.$emit('apply', {
			id: TEMPLATE,
			name: 'Ruimtelijke verzoeken',
			values: {
				custodians: [{ name: 'Wethouder Ruimte', function: 'College' }],
				systems: ['files'],
				terms: 'Stationsweg',
				triageRules: ['kept by the server'],
			},
		})
		await flushPromises()

		expect(
			wrapper.find('[data-testid="woo-plan-custodian-name-0"]').element.value,
		).toBe('Wethouder Ruimte')
		expect(
			wrapper.find('[data-testid="woo-plan-system-files"] input').element
				.checked,
		).toBe(true)
		expect(wrapper.find('[data-testid="woo-plan-terms"]').element.value).toBe(
			'Stationsweg',
		)
		expect(
			wrapper.find('[data-testid="woo-plan-from"]').element.value,
			'a template never carries the period',
		).toBe('')
		expect(
			wrapper.find('[data-testid="woo-plan-save"]').attributes('disabled'),
			'the handler still completes the plan',
		).toBeDefined()

		await wrapper.find('[data-testid="woo-plan-from"]').setValue('2026-01-01')
		await wrapper.find('[data-testid="woo-plan-to"]').setValue('2026-06-30')
		await wrapper.find('[data-testid="woo-plan-save"]').trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(
			expect.stringContaining(`/apps/dossiq/api/cases/${CASE_ID}/woo/plan`),
			{
				custodians: [{ name: 'Wethouder Ruimte', function: 'College' }],
				systems: ['files'],
				periodFrom: '2026-01-01',
				periodTo: '2026-06-30',
				terms: 'Stationsweg',
				startedFrom: TEMPLATE,
			},
		)
		expect(
			wrapper.find('[data-testid="record-template-select"]').exists(),
			'a recorded plan no longer offers a template',
		).toBe(false)
	})

	it('shows a recorded plan and its history from the audit trail', async () => {
		const { wrapper, axios } = await mountDialog({
			id: 'plan-1',
			custodians: [{ name: 'A', function: 'B' }],
			systems: ['files'],
			periodFrom: '2025-01-01',
			periodTo: '2025-06-30',
			terms: 'x',
			recordedBy: 'pjansen',
			recordedAt: '2026-10-01T10:00:00Z',
		})

		expect(
			wrapper.find('[data-testid="woo-plan-custodian-name-0"]').element.value,
		).toBe('A')
		const history = wrapper.find('[data-testid="woo-plan-history"]').text()
		expect(history).toContain('P. Jansen')
		expect(history).toContain('A. Bakker')
		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining(
				'/apps/openregister/api/objects/dossiq/wooSearchPlan/plan-1/audit-trails',
			),
		)
	})
})
