// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Advice from the decision table a case type names, on the case page
 * (REQ-LHS-CASE-01): the header action shows only when the case type names a
 * table, the dialog asks the inputs the table declares, shows the
 * recommendation, and records acceptance or a deviation with a reason, and
 * the Decisions tab lists every stored advice of the case.
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DecisionTableAdviceDialog from '../../src/dialogs/DecisionTableAdviceDialog.vue'
const panels = require('./helpers/casePanels.js')

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

const ROOT = path.resolve(__dirname, '../..')
const CASE_ID = '22222222-2222-4222-8222-222222222222'
const FORM_URL = `/index.php/apps/dossiq/api/decision-advice/case/${CASE_ID}/form`
const FORM = {
	available: true,
	decisionTable: 'dossiq.lhs-intervention',
	inputs: [
		{ name: 'behaviour', type: 'string', options: ['A', 'B', 'C'] },
		{ name: 'consequence', type: 'string', options: ['1', '2'] },
	],
	outcomes: ['Aanspreken / informeren', 'Waarschuwen', 'Bestuurlijke sanctie'],
}

const stubs = {
	CnDialog: {
		emits: ['closing'],
		template:
			'<div><button data-testid="stub-dialog-closing" @click="$emit(\'closing\')" /><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		props: ['modelValue', 'options', 'inputLabel', 'taggable'],
		emits: ['update:modelValue'],
		template:
			'<div class="stub-select" :data-label="inputLabel" :data-taggable="String(!!taggable)"><button v-for="(o, i) in options" :key="i" class="stub-option" @click="$emit(\'update:modelValue\', o)">{{ o }}</button></div>',
	},
	NcTextArea: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<textarea class="stub-reason" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
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

/**
 * Mount the dialog and let the form load.
 *
 * @param {object} props The dialog props.
 * @param {object} [mocks] Global mocks, e.g. `$route`.
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog(props, mocks = {}) {
	const wrapper = mount(DecisionTableAdviceDialog, { props, global: { stubs, mocks } })
	await flushPromises()
	return wrapper
}

/**
 * Pick an option of the n-th select.
 *
 * @param {object} wrapper The dialog.
 * @param {number} select Which select.
 * @param {string} value The option text.
 * @return {Promise<void>}
 */
async function pick(wrapper, select, value) {
	const option = wrapper
		.findAll('.stub-select')[select]
		.findAll('.stub-option')
		.find((button) => button.text() === value)
	await option.trigger('click')
}

/**
 * Fill both inputs and ask for advice.
 *
 * @param {object} wrapper The dialog.
 * @return {Promise<void>}
 */
async function askFor(wrapper) {
	await pick(wrapper, 0, 'C')
	await pick(wrapper, 1, '2')
	await wrapper.find('[data-testid="decision-advice-ask"]').trigger('click')
	await flushPromises()
}

describe('DecisionTableAdviceDialog', () => {
	beforeEach(async () => {
		const axios = await axiosDouble()
		axios.get.mockReset()
		axios.post.mockReset()
		axios.get.mockResolvedValue({ data: FORM })
	})

	it('asks one labelled select per declared input, offering the values the rules use', async () => {
		const axios = await axiosDouble()
		const wrapper = await mountDialog({ caseId: CASE_ID })

		expect(axios.get).toHaveBeenCalledWith(FORM_URL)
		const selects = wrapper.findAll('.stub-select')
		expect(selects.map((s) => s.attributes('data-label'))).toEqual(['behaviour', 'consequence'])
		expect(selects[0].findAll('.stub-option').map((o) => o.text())).toEqual(['A', 'B', 'C'])
		expect(selects[0].attributes('data-taggable')).toBe('false')
		expect(wrapper.find('[data-testid="decision-advice-ask"]').attributes('disabled')).toBeDefined()
	})

	it('accepts the recommendation: the outcome posted equals it, with no reason', async () => {
		const axios = await axiosDouble()
		axios.post
			.mockResolvedValueOnce({ data: { id: 'advice-1', recommendation: 'Waarschuwen' } })
			.mockResolvedValueOnce({ data: { id: 'advice-1', outcome: 'Waarschuwen', accepted: true } })
		const wrapper = await mountDialog({ caseId: CASE_ID })

		await askFor(wrapper)

		expect(axios.post).toHaveBeenNthCalledWith(
			1,
			'/index.php/apps/dossiq/api/decision-advice/recommend',
			{ caseId: CASE_ID, inputs: { behaviour: 'C', consequence: '2' } },
		)
		expect(wrapper.find('[data-testid="decision-advice-recommendation"]').text())
			.toBe('The decision table recommends: Waarschuwen')
		// The recommendation leads the outcomes and is preselected.
		expect(wrapper.findAll('.stub-select')[0].findAll('.stub-option').map((o) => o.text()))
			.toEqual(['Waarschuwen', 'Aanspreken / informeren', 'Bestuurlijke sanctie'])
		expect(wrapper.find('[data-testid="decision-advice-reason"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="decision-advice-save"]').text()).toBe('Accept the advice')

		await wrapper.find('[data-testid="decision-advice-save"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenNthCalledWith(
			2,
			'/index.php/apps/dossiq/api/decision-advice/override',
			{ adviceId: 'advice-1', outcome: 'Waarschuwen', reason: '' },
		)
		expect(wrapper.find('[data-testid="decision-advice-saved"]').text())
			.toBe('The advice is accepted and recorded on this case.')
		await wrapper.find('[data-testid="decision-advice-close"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('a deviation without a reason shows the server refusal and records nothing', async () => {
		const axios = await axiosDouble()
		axios.post
			.mockResolvedValueOnce({ data: { id: 'advice-2', recommendation: 'Waarschuwen' } })
			.mockRejectedValueOnce({ response: { status: 400, data: { error: 'Geef een reden voor de afwijking' } } })
		const wrapper = await mountDialog({ caseId: CASE_ID })
		await askFor(wrapper)

		await pick(wrapper, 0, 'Bestuurlijke sanctie')
		expect(wrapper.find('[data-testid="decision-advice-reason"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="decision-advice-save"]').text()).toBe('Record the deviation')
		await wrapper.find('[data-testid="decision-advice-save"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenLastCalledWith(
			'/index.php/apps/dossiq/api/decision-advice/override',
			{ adviceId: 'advice-2', outcome: 'Bestuurlijke sanctie', reason: '' },
		)
		expect(wrapper.find('[data-testid="decision-advice-error"]').text()).toBe('Geef een reden voor de afwijking')
		expect(wrapper.find('[data-testid="decision-advice-saved"]').exists()).toBe(false)
	})

	it('a deviation with a reason is recorded and said', async () => {
		const axios = await axiosDouble()
		axios.post
			.mockResolvedValueOnce({ data: { id: 'advice-3', recommendation: 'Waarschuwen' } })
			.mockResolvedValueOnce({ data: { id: 'advice-3', outcome: 'Bestuurlijke sanctie', accepted: false } })
		const wrapper = await mountDialog({ caseId: CASE_ID })
		await askFor(wrapper)

		await pick(wrapper, 0, 'Bestuurlijke sanctie')
		await wrapper.find('.stub-reason').setValue('Herhaalde overtreding')
		await wrapper.find('[data-testid="decision-advice-save"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenLastCalledWith(
			'/index.php/apps/dossiq/api/decision-advice/override',
			{ adviceId: 'advice-3', outcome: 'Bestuurlijke sanctie', reason: 'Herhaalde overtreding' },
		)
		expect(wrapper.find('[data-testid="decision-advice-saved"]').text())
			.toBe('The deviation is recorded on this case: Bestuurlijke sanctie.')
	})

	it('says so when the case type names no table, and offers no ask', async () => {
		const axios = await axiosDouble()
		axios.get.mockResolvedValue({ data: { available: false, decisionTable: '', inputs: [], outcomes: [] } })
		const wrapper = await mountDialog({ caseId: '@objectId' }, { $route: { params: { id: CASE_ID } } })

		expect(axios.get).toHaveBeenCalledWith(FORM_URL)
		expect(wrapper.find('[data-testid="decision-advice-unavailable"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="decision-advice-ask"]').exists()).toBe(false)
	})

	it('shows the server refusal of the recommendation and stays on the inputs', async () => {
		const axios = await axiosDouble()
		axios.post.mockRejectedValueOnce({ response: { status: 422, data: { error: 'De beslistabel geeft geen advies voor deze invoer' } } })
		const wrapper = await mountDialog({ caseId: CASE_ID })

		await askFor(wrapper)

		expect(wrapper.find('[data-testid="decision-advice-error"]').text()).toBe('De beslistabel geeft geen advies voor deze invoer')
		expect(wrapper.find('[data-testid="decision-advice-ask"]').exists()).toBe(true)
	})

	it('falls back to its own sentence when the form cannot be read', async () => {
		const axios = await axiosDouble()
		axios.get.mockRejectedValue(new Error('network'))
		const wrapper = await mountDialog({ caseId: CASE_ID })

		expect(wrapper.find('[data-testid="decision-advice-error"]').text()).toBe('The decision table could not be read.')
		expect(wrapper.find('[data-testid="decision-advice-unavailable"]').exists()).toBe(true)
	})
})

describe('the case page wiring', () => {
	const headerActions = panels.pageConfig('CaseDetail').headerActions

	it('offers the advice action only when the case type names a decision table', () => {
		const action = headerActions.find((entry) => entry.id === 'advice-from-decision-table')
		expect(action).toMatchObject({
			type: 'open-modal',
			target: 'DecisionTableAdviceDialog',
			props: { caseId: '@objectId' },
		})
		expect(action.visibleWhen.all).toContainEqual({
			field: '@context.caseType.adviceDecisionTable',
			op: 'notEmpty',
		})
	})

	it('registers the dialog under the name the action targets', () => {
		const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		expect(registry).toContain("import DecisionTableAdviceDialog from './dialogs/DecisionTableAdviceDialog.vue'")
		expect(registry).toMatch(/^\tDecisionTableAdviceDialog: \{\n\t\tkind: 'modal',\n\t\tcomponent: DecisionTableAdviceDialog,/m)
	})

	it('lists the stored advice of the case on the Decisions tab, without a create', () => {
		const sections = panels.caseWidget('case-decisions-panel').content.sections
		const list = sections.map((entry) => entry.widget).find((child) => child.id === 'case-decision-advice')
		expect(list).toMatchObject({
			type: 'object-list',
			content: {
				register: 'dossiq',
				schema: 'decisionAdvice',
				allowCreate: false,
				filter: { case: '@objectId' },
			},
		})
		expect(list.content.columns.map((column) => column.key))
			.toEqual(['recommendedAt', 'recommendation', 'outcome', 'reason', 'decidedBy'])
	})
})
