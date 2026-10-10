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
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
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

describe('a result template on the lifecycle menu', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('offers result templates on an act that carries a result, scoped to the case type', async () => {
		const wrapper = await formFor('finish')

		const picker = wrapper.findComponent({ name: 'TemplatePicker' })
		expect(picker.exists()).toBe(true)
		expect(picker.props('kind')).toBe('result')
		expect(picker.props('caseType')).toBe('ct-bezwaar')
		expect(picker.props('caseId')).toBe('case-1')
	})

	it('offers no template on an act without a result', async () => {
		const wrapper = await formFor('suspend')

		expect(wrapper.findComponent({ name: 'TemplatePicker' }).exists()).toBe(
			false,
		)
	})

	it('presets the outcome text from the template body on an empty form', async () => {
		const wrapper = await formFor('finish')

		wrapper.vm.applyTemplate({
			id: 'tpl-1',
			body: 'Uw bezwaar is niet-ontvankelijk verklaard.',
			presets: {},
		})

		expect(wrapper.vm.reason).toBe('Uw bezwaar is niet-ontvankelijk verklaard.')
	})

	it('reads the body out of the presets when the template carries it there', async () => {
		const wrapper = await formFor('finish')

		wrapper.vm.applyTemplate({ id: 'tpl-1', presets: { body: 'Toegewezen.' } })

		expect(wrapper.vm.reason).toBe('Toegewezen.')
	})

	it('does not overwrite what the handler already typed', async () => {
		const wrapper = await formFor('finish')
		wrapper.vm.reason = "Twee alinea's die de behandelaar zelf schreef."

		wrapper.vm.applyTemplate({
			id: 'tpl-1',
			body: 'Uw bezwaar is niet-ontvankelijk verklaard.',
		})

		expect(wrapper.vm.reason).toBe(
			"Twee alinea's die de behandelaar zelf schreef.",
		)
	})

	it('does nothing when the template has no text at all', async () => {
		const wrapper = await formFor('finish')

		wrapper.vm.applyTemplate({ id: 'tpl-1', body: '', presets: {} })
		wrapper.vm.applyTemplate(null)
		wrapper.vm.applyTemplate(undefined)

		expect(wrapper.vm.reason).toBe('')
	})

	it('posts the preset text as the reason when the act is confirmed', async () => {
		const wrapper = await formFor('finish')
		axios.post.mockResolvedValue({ data: {} })
		wrapper.vm.applyTemplate({ id: 'tpl-1', body: 'Niet-ontvankelijk.' })
		wrapper.vm.resultTypeId = 'rt-nok'

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
