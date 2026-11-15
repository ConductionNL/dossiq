// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The template paths four small dialogs leave untested elsewhere: closing
 * through the dialog itself and through Cancel, the date and confirm of
 * EndAssignmentDialog, DeleteRolDialog's refusal reason, the title and
 * documents fields of CaseCopyDialog, and picking a result in
 * FileContactMomentDialog. The `@nextcloud/vue` components are stubbed so
 * each one renders its slots and hands back what the real one would emit;
 * only HTTP and the Nextcloud globals are mocked.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (match, key) =>
			vars && key in vars ? String(vars[key]) : match,
		),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

const stubs = vi.hoisted(() => {
	const { h: render } = require('vue')
	const dialog = (name) => ({
		name,
		inheritAttrs: false,
		props: { open: { type: Boolean, default: true }, name: { type: String, default: '' } },
		emits: ['closing', 'update:open'],
		render() {
			return render('div', { class: name, 'data-open': String(this.open) }, [
				render('h2', { class: 'stub-title' }, this.name),
				render('button', { class: 'stub-closing', onClick: () => this.$emit('closing') }),
				render('button', { class: 'stub-open-false', onClick: () => this.$emit('update:open', false) }),
				render('button', { class: 'stub-open-true', onClick: () => this.$emit('update:open', true) }),
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
	const button = {
		name: 'NcButton',
		props: { disabled: { type: Boolean, default: false }, type: { type: String, default: '' }, variant: { type: String, default: '' } },
		emits: ['click'],
		render() {
			return render(
				'button',
				{ class: 'NcButton', 'data-kind': this.variant || this.type, disabled: this.disabled, onClick: (event) => this.$emit('click', event) },
				this.$slots.default?.(),
			)
		},
	}
	const textField = {
		name: 'NcTextField',
		props: { modelValue: { type: String, default: '' }, label: { type: String, default: '' } },
		emits: ['update:modelValue'],
		render() {
			return render('input', {
				class: 'NcTextField',
				'aria-label': this.label,
				value: this.modelValue,
				onInput: (event) => this.$emit('update:modelValue', event.target.value),
			})
		},
	}
	const checkbox = {
		name: 'NcCheckboxRadioSwitch',
		props: { modelValue: { type: [Boolean, String], default: false }, type: { type: String, default: 'checkbox' }, value: { type: String, default: '' } },
		emits: ['update:modelValue'],
		render() {
			return render(
				'button',
				{
					class: 'NcCheckboxRadioSwitch',
					'data-checked': String(this.type === 'radio' ? this.modelValue === this.value : this.modelValue),
					onClick: () => this.$emit('update:modelValue', this.type === 'radio' ? this.value : !this.modelValue),
				},
				this.$slots.default?.(),
			)
		},
	}
	return { dialog, button, textField, checkbox }
})

vi.mock('@nextcloud/vue', () => ({ NcButton: stubs.button, NcDialog: stubs.dialog('NcDialog') }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: stubs.button }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: stubs.dialog('NcDialog') }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: stubs.textField }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: stubs.checkbox }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stubs.dialog('CnDialog') }))

const { default: axios } = await import('@nextcloud/axios')
const { default: EndAssignmentDialog } = await import('../../src/dialogs/EndAssignmentDialog.vue')
const { default: DeleteRolDialog } = await import('../../src/dialogs/DeleteRolDialog.vue')
const { default: CaseCopyDialog } = await import('../../src/dialogs/CaseCopyDialog.vue')
const { default: FileContactMomentDialog } = await import('../../src/dialogs/FileContactMomentDialog.vue')

/**
 * The action buttons of a mounted dialog, by their text.
 *
 * @param {object} wrapper The mounted dialog.
 * @param {string} text The button's words.
 * @return {object} The button.
 */
function buttonNamed(wrapper, text) {
	const found = wrapper.findAll('button.NcButton').find((b) => b.text() === text)
	if (!found) {
		throw new Error(`no button "${text}"`)
	}
	return found
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('EndAssignmentDialog', () => {
	it('starts on today, hands back the chosen date and is refused without one', async () => {
		const wrapper = mount(EndAssignmentDialog, { props: { assignment: { id: 'a1' } } })
		const date = wrapper.find('input.end-assignment__date')
		expect(date.element.value).toBe(new Date().toISOString().slice(0, 10))
		expect(wrapper.find('.stub-title').text()).toBe('End role assignment')

		await date.setValue('2026-12-31')
		await buttonNamed(wrapper, 'End assignment').trigger('click')
		expect(wrapper.emitted('save')).toEqual([['2026-12-31']])

		await date.setValue('')
		expect(buttonNamed(wrapper, 'End assignment').attributes('disabled')).toBeDefined()
	})

	it('closes through Cancel and through the dialog, and stays open when the dialog opens', async () => {
		const wrapper = mount(EndAssignmentDialog, { props: { assignment: { id: 'a1' } } })
		await wrapper.find('.stub-open-true').trigger('click')
		expect(wrapper.emitted('close')).toBeUndefined()
		await wrapper.find('.stub-open-false').trigger('click')
		await buttonNamed(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('save')).toBeUndefined()
	})
})

describe('DeleteRolDialog', () => {
	it('names the role, and confirms when nothing blocks the delete', async () => {
		const wrapper = mount(DeleteRolDialog, { props: { role: { id: 'r1', name: 'Behandelaar' } } })
		expect(wrapper.text()).toContain('Delete role Behandelaar?')
		expect(wrapper.find('.rol-manager__warning').exists()).toBe(false)
		expect(wrapper.find('.NcDialog').attributes('data-open')).toBe('true')

		const remove = buttonNamed(wrapper, 'Delete')
		expect(remove.attributes('data-kind')).toBe('error')
		await remove.trigger('click')
		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})

	it('falls back on the id, shows why a delete is blocked and refuses it', async () => {
		const wrapper = mount(DeleteRolDialog, { props: { role: { id: 'r2' }, blockedReason: 'Still assigned to 3 cases.' } })
		expect(wrapper.text()).toContain('Delete role r2?')
		expect(wrapper.find('.rol-manager__warning').text()).toBe('Still assigned to 3 cases.')
		expect(buttonNamed(wrapper, 'Delete').attributes('disabled')).toBeDefined()
	})

	it('closes through Cancel and through the dialog, never on open', async () => {
		const wrapper = mount(DeleteRolDialog, { props: { role: { id: 'r1' } } })
		await wrapper.find('.stub-open-true').trigger('click')
		expect(wrapper.emitted('close')).toBeUndefined()
		await wrapper.find('.stub-open-false').trigger('click')
		await buttonNamed(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})
})

describe('CaseCopyDialog', () => {
	it('sends the typed title and the documents choice', async () => {
		axios.get.mockResolvedValue({ data: { title: 'Bouwvergunning' } })
		axios.post.mockResolvedValue({ data: { id: '' } })
		const wrapper = mount(CaseCopyDialog, { props: { caseId: 'c-1' } })
		await flushPromises()

		const title = wrapper.find('input.NcTextField')
		expect(title.attributes('aria-label')).toBe('Title of the copy')
		await title.setValue('  Tweede aanvraag  ')
		const documents = wrapper.find('button.NcCheckboxRadioSwitch')
		expect(documents.attributes('data-checked')).toBe('false')
		await documents.trigger('click')
		expect(documents.attributes('data-checked')).toBe('true')

		await buttonNamed(wrapper, 'Copy case').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith('/apps/dossiq/api/case/c-1/copy', { title: 'Tweede aanvraag', documents: true })
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('refuses an empty title, and shows a refusal from the server as an alert', async () => {
		axios.get.mockResolvedValue({ data: { title: 'Bouwvergunning' } })
		axios.post.mockRejectedValue({ response: { data: { message: 'Not allowed.' } } })
		const wrapper = mount(CaseCopyDialog, { props: { caseId: 'c-1' } })
		await flushPromises()

		await wrapper.find('input.NcTextField').setValue('   ')
		expect(buttonNamed(wrapper, 'Copy case').attributes('disabled')).toBeDefined()

		await wrapper.find('input.NcTextField').setValue('Kopie')
		await buttonNamed(wrapper, 'Copy case').trigger('click')
		await flushPromises()
		const alert = wrapper.find('[data-testid="case-copy-error"]')
		expect(alert.exists()).toBe(true)
		expect(alert.attributes('role')).toBe('alert')
		expect(alert.text()).not.toBe('')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('closes through Cancel and through the dialog without posting', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = mount(CaseCopyDialog, { props: { caseId: 'c-1' } })
		await flushPromises()
		await wrapper.find('.stub-closing').trigger('click')
		await buttonNamed(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(axios.post).not.toHaveBeenCalled()
	})
})

describe('FileContactMomentDialog', () => {
	it('lists the found cases as choices, and the File button waits for one', async () => {
		axios.get.mockResolvedValue({
			data: { results: [{ id: 'c-1', identifier: 'Z-1' }, { id: 'c-2', identifier: 'Z-2', title: 'Kapvergunning' }] },
		})
		const wrapper = mount(FileContactMomentDialog, {
			props: { caseId: 'c-1', moment: { id: 'm1', channel: 'Telefoon', subject: 'Vraag' } },
		})
		expect(wrapper.find('.file-contact-moment__moment').text()).toBe('Telefoon: Vraag')
		expect(buttonNamed(wrapper, 'File').attributes('disabled')).toBeDefined()

		await wrapper.find('input.NcTextField').setValue('kap')
		await flushPromises()
		const choices = wrapper.findAll('[data-testid="file-contact-moment-result"]')
		expect(choices.map((c) => c.text())).toEqual(['Z-2 · Kapvergunning'])
		expect(choices[0].attributes('data-checked')).toBe('false')

		await choices[0].trigger('click')
		expect(wrapper.find('[data-testid="file-contact-moment-result"]').attributes('data-checked')).toBe('true')
		expect(buttonNamed(wrapper, 'File').attributes('disabled')).toBeUndefined()
	})

	it('says so when nothing is found', async () => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = mount(FileContactMomentDialog, { props: { caseId: 'c-1', row: { id: 'm1', summary: 'Mail' } } })
		expect(wrapper.find('.file-contact-moment__moment').text()).toBe('Mail')
		await wrapper.find('input.NcTextField').setValue('niets')
		await flushPromises()
		expect(wrapper.text()).toContain('No case found with those words.')
	})

	it('closes through Cancel and through the dialog', async () => {
		const wrapper = mount(FileContactMomentDialog, { props: { caseId: 'c-1', moment: { id: 'm1' } } })
		await wrapper.find('.stub-closing').trigger('click')
		await buttonNamed(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('filed')).toBeUndefined()
	})
})
