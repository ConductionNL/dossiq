// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A result template presets the outcome text on the close form a handler can
 * reach: the lifecycle menu (REQ-TPL-06, REQ-TPL-07).
 *
 * 🔑 THE ASSERTION THAT MATTERS IS THE ONE ABOUT NOT OVERWRITING. Picking a
 * template after writing two paragraphs and losing them is worse than having
 * no templates at all: the handler cannot get the text back, and the gesture
 * that destroyed it looked like a convenience. A test that only checked "the
 * text arrives" would pass on a version that clobbers.
 *
 * The picker is asserted to be scoped to the case type the server named. The
 * server does the filtering (TemplateStartController::contentTemplates); what
 * the menu owes is to ask with the right case type, because asking with ''
 * returns only the templates meant for every case type.
 *
 * @spec openspec/changes/the-close-form-keeps-its-template/specs/template-library/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { showWarning, emit } = vi.hoisted(() => ({
	showWarning: vi.fn(),
	emit: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({ showWarning, showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit }))

const CaseLifecycleMenuDialog = (
	await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')
).default

/**
 * NcDialog, rendering its slots. `shallowMount` stubs it to an empty element,
 * and everything the menu shows lives in its slot, so without this the picker
 * is absent whether the code works or not.
 */
const DIALOG_RENDERS_ITS_SLOT = {
	CnChoiceCards: {
		props: ['options', 'modelValue'],
		template:
			'<div><div v-for="option in options" :key="option.value"><slot v-if="option.value === modelValue" name="option-details" :option="option" /></div></div>',
	},
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
}

/**
 * The three server answers the menu reads, for an open bezwaar case.
 *
 * @param {string} url The requested URL.
 * @return {Promise<object>} The answer.
 */
function answers(url) {
	if (url.endsWith('/available-transitions')) {
		return Promise.resolve({
			data: {
				transitions: [{ id: 'lc-start', label: 'Start', toStatus: 'st-2' }],
			},
		})
	}
	if (url.endsWith('/lifecycle')) {
		return Promise.resolve({
			data: {
				suspended: false,
				canSuspend: true,
				canResume: false,
				canExtend: false,
				canReopen: false,
				isFinalStatus: false,
				caseType: 'ct-bezwaar',
			},
		})
	}
	return Promise.resolve({
		data: {
			held: false,
			draft: false,
			acts: [{ act: 'finish', allowed: true, role: '', reason: '' }],
			resultTypes: [{ id: 'rt-nok', name: 'Niet-ontvankelijk' }],
		},
	})
}

/**
 * The menu, loaded, with one act's form open.
 *
 * @param {string} actId The act to open.
 * @return {Promise<object>} The mounted wrapper.
 */
async function formFor(actId) {
	axios.get.mockImplementation(answers)
	const wrapper = shallowMount(CaseLifecycleMenuDialog, {
		props: { caseId: 'case-1' },
		global: { stubs: DIALOG_RENDERS_ITS_SLOT },
	})
	await flushPromises()
	const entry = wrapper.vm.entries.find((row) => row.id === actId)
	wrapper.vm.choose(entry)
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * The template input the open form declares.
 *
 * @param {object} wrapper The dialog wrapper.
 * @return {object|undefined} The declaration.
 */
function templateInput(wrapper) {
	return wrapper.findComponent({ name: 'CnTransitionInputFields' }).props('inputs').find((input) => input.widget === 'template')
}

describe('a result template on the lifecycle menu', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers result templates on an act that carries a result, scoped to the case type', async () => {
		const wrapper = await formFor('finish')

		expect(templateInput(wrapper)).toMatchObject({
			url: '/apps/dossiq/api/content-templates/result',
			params: { caseType: 'ct-bezwaar', case: 'case-1' },
			label: 'Result template',
			placeholder: 'Start from a template',
			testid: 'template-picker',
		})
	})

	it('offers no template on an act without a result', async () => {
		const wrapper = await formFor('suspend')

		expect(templateInput(wrapper)).toBeUndefined()
	})

	// The library puts the template's body (else presets.body) into the input
	// a template input names, and never over typed text
	// (nextcloud-vue tests/utils/transitionInputs.spec.js). What the menu owes
	// is to name the outcome text, which is what it posts as the reason.
	it('presets the outcome text, the reason, and reads the content templates as options', async () => {
		const wrapper = await formFor('finish')
		const input = templateInput(wrapper)

		expect(input.applyTo).toBe('reason')
		expect(input.mapOption({ id: 'tpl-1', name: 'Niet-ontvankelijk', body: 'Uw bezwaar is niet-ontvankelijk verklaard.' })).toEqual({
			id: 'tpl-1',
			name: 'Niet-ontvankelijk',
			body: 'Uw bezwaar is niet-ontvankelijk verklaard.',
			presets: {},
		})
	})

	it('does not send the template itself', async () => {
		const wrapper = await formFor('finish')
		axios.post.mockResolvedValue({ data: {} })
		wrapper.vm.values = { ...wrapper.vm.values, resultTemplate: 'tpl-1', reason: 'Klaar.', resultTypeId: 'rt-nok' }

		await wrapper.vm.confirm()

		expect(axios.post.mock.calls[0][1]).not.toHaveProperty('resultTemplate')
	})

	it('posts the preset text as the reason when the act is confirmed', async () => {
		const wrapper = await formFor('finish')
		axios.post.mockResolvedValue({ data: {} })
		const fields = wrapper.findComponent({ name: 'CnTransitionInputFields' })
		fields.vm.$emit('update:modelValue', { ...fields.props('modelValue'), reason: 'Niet-ontvankelijk.' })
		wrapper.vm.values = { ...wrapper.vm.values, resultTypeId: 'rt-nok' }

		await wrapper.vm.confirm()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toBe(
			'/index.php/apps/dossiq/api/case/case-1/finish',
		)
		expect(axios.post.mock.calls[0][1]).toMatchObject({
			reason: 'Niet-ontvankelijk.',
			resultTypeId: 'rt-nok',
		})
	})
})
