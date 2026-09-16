// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A result template presets the outcome text on the close form.
 *
 * 🔑 THE ASSERTION THAT MATTERS IS THE ONE ABOUT NOT OVERWRITING. Picking a
 * template after writing two paragraphs and losing them is worse than having no
 * templates at all: the handler cannot get the text back, and the gesture that
 * destroyed it looked like a convenience. A test that only checked "the text
 * arrives" would pass on a version that clobbers.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const CaseTransitionConfirmDialog = (
	await import('../../src/dialogs/CaseTransitionConfirmDialog.vue')
).default

/**
 * NcDialog, rendering its default slot.
 *
 * `shallowMount` stubs NcDialog to an empty element, and everything this
 * dialog shows lives in that slot. Without this the picker is absent from the
 * rendered tree whether the guard works or not, so the assertion below would
 * pass on a version that never renders it at all.
 */
const DIALOG_RENDERS_ITS_SLOT = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
}

/**
 * The dialog, mounted on a transition that closes the case.
 *
 * @param {object} data Fields to set after mounting.
 */
function closeForm(data = {}) {
	const wrapper = shallowMount(CaseTransitionConfirmDialog, {
		props: {
			caseId: 'case-1',
			transition: { id: 't1', label: 'Afhandelen', toStatus: 's-final' },
			closing: true,
			resultTypes: [{ id: 'rt-1', label: 'Niet-ontvankelijk' }],
			caseType: 'ct-bezwaar',
		},
		global: { stubs: DIALOG_RENDERS_ITS_SLOT },
	})
	wrapper.setData(data)
	return wrapper
}

describe('a result template on the close form', () => {
	it('presets the outcome text from the template body', () => {
		const wrapper = closeForm()

		wrapper.vm.applyTemplate({
			id: 'tpl-1',
			body: 'Uw bezwaar is niet-ontvankelijk verklaard.',
			presets: {},
		})

		expect(wrapper.vm.comment).toBe('Uw bezwaar is niet-ontvankelijk verklaard.')
	})

	it('reads the body out of the presets when the template carries it there', () => {
		const wrapper = closeForm()

		wrapper.vm.applyTemplate({ id: 'tpl-1', presets: { body: 'Toegewezen.' } })

		expect(wrapper.vm.comment).toBe('Toegewezen.')
	})

	it('does not overwrite what the handler already typed', async () => {
		const wrapper = closeForm()
		await wrapper.setData({ comment: 'Twee alinea\'s die de behandelaar zelf schreef.' })

		wrapper.vm.applyTemplate({ id: 'tpl-1', body: 'Uw bezwaar is niet-ontvankelijk verklaard.' })

		expect(wrapper.vm.comment).toBe('Twee alinea\'s die de behandelaar zelf schreef.')
	})

	it('does nothing when the template has no text at all', () => {
		const wrapper = closeForm()

		wrapper.vm.applyTemplate({ id: 'tpl-1', body: '', presets: {} })
		wrapper.vm.applyTemplate(null)
		wrapper.vm.applyTemplate(undefined)

		expect(wrapper.vm.comment).toBe('')
	})

	it('offers the picker only where a case is being closed', () => {
		const closingForm = closeForm()
		expect(closingForm.findComponent({ name: 'TemplatePicker' }).exists()).toBe(true)

		const ordinary = shallowMount(CaseTransitionConfirmDialog, {
			props: {
				caseId: 'case-1',
				transition: { id: 't1', label: 'In behandeling', toStatus: 's-2' },
				closing: false,
			},
			global: { stubs: DIALOG_RENDERS_ITS_SLOT },
		})
		expect(ordinary.findComponent({ name: 'TemplatePicker' }).exists()).toBe(false)
	})
})
