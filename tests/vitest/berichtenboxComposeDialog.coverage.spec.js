// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BerichtenboxComposeDialog driven through its rendered controls, the paths
 * `berichtenboxComposeDialog.spec.js` reaches only through `vm` calls or not
 * at all: closing through the dialog and Cancel; focusing into and out of the
 * BSN field; picking the kind, ticking besluit and typing subject, body and
 * BSN before pressing Send; the required-field sentences under an empty
 * form; a dialog with no case reading no recipient; and a recipient read that
 * fails leaving the BSN empty.
 *
 * `@conduction/nextcloud-vue`'s CnDialog is replaced by a stub that renders
 * both slots (the shared alias stub exports no CnDialog, so the actions slot
 * was never drawn), and the `@nextcloud/vue` controls by stubs that render
 * real elements and emit `update:modelValue`.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))

vi.mock('@conduction/nextcloud-vue', async (importOriginal) => ({
	...(await importOriginal()),
	CnDialog: defineComponent({
		name: 'CnDialog',
		inheritAttrs: false,
		emits: ['closing'],
		render() {
			return h('div', { class: 'CnDialog' }, [
				h('button', {
					'data-testid': 'stub-dialog-closing',
					onClick: () => this.$emit('closing'),
				}),
				this.$slots.default?.(),
				h('footer', { 'data-testid': 'stub-actions' }, this.$slots.actions?.()),
			])
		},
	}),
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: defineComponent({
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{
					class: `NcButton NcButton--${this.variant || 'secondary'}`,
					disabled: this.disabled,
					onClick: () => this.$emit('click'),
				},
				[this.$slots.icon?.(), this.$slots.default?.()],
			)
		},
	}),
	NcCheckboxRadioSwitch: defineComponent({
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		render() {
			return h('label', { class: 'NcCheckboxRadioSwitch' }, [
				h('input', {
					type: 'checkbox',
					checked: this.modelValue,
					onChange: (event) =>
						this.$emit('update:modelValue', event.target.checked),
				}),
				this.$slots.default?.(),
			])
		},
	}),
	NcNoteCard: defineComponent({
		name: 'NcNoteCard',
		props: ['type', 'heading'],
		render() {
			return h('div', { class: 'NcNoteCard' }, this.$slots.default?.())
		},
	}),
	NcSelect: defineComponent({
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputId'],
		emits: ['update:modelValue'],
		render() {
			return h(
				'div',
				{ class: 'NcSelect', 'data-input-id': this.inputId },
				(this.options || []).map((option, index) =>
					h('button', {
						class: 'stub-option',
						'data-index': index,
						onClick: () => this.$emit('update:modelValue', option),
					}),
				),
			)
		},
	}),
	NcTextField: defineComponent({
		name: 'NcTextField',
		props: ['modelValue', 'label', 'error', 'placeholder'],
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				class: 'NcTextField',
				value: this.modelValue ?? '',
				placeholder: this.placeholder,
				onInput: (event) =>
					this.$emit('update:modelValue', event.target.value),
			})
		},
	}),
}))

const { default: BerichtenboxComposeDialog } =
	await import('../../src/dialogs/BerichtenboxComposeDialog.vue')

/**
 * Mount the open dialog.
 *
 * @param {object} [props] Extra props.
 * @param {object} [mocks] Global mocks.
 * @return {object} The wrapper.
 */
function mountOpen(props = {}, mocks = {}) {
	return mount(BerichtenboxComposeDialog, {
		props: { open: true, caseId: 'case-1', ...props },
		global: { mocks },
	})
}

/**
 * The buttons in the actions slot.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {{cancel: object, send: object}} Cancel and Send.
 */
function actions(wrapper) {
	const buttons = wrapper.find('[data-testid="stub-actions"]').findAll('button')
	return { cancel: buttons[0], send: buttons[1] }
}

/**
 * The BSN input, the first text field in the form.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {object} The input wrapper.
 */
function bsnInput(wrapper) {
	return wrapper.findAll('input.NcTextField')[0]
}

beforeEach(() => {
	mockGet.mockReset()
	mockPost.mockReset()
	mockGet.mockResolvedValue({ data: {} })
})

describe('BerichtenboxComposeDialog — through its controls', () => {
	it('emits close from the dialog and from Cancel', async () => {
		const wrapper = mountOpen()
		await flushPromises()

		await wrapper.find('[data-testid="stub-dialog-closing"]').trigger('click')
		const { cancel, send } = actions(wrapper)
		expect(cancel.text()).toBe('Cancel')
		expect(send.text()).toBe('Send the message')
		expect(send.find('.send-icon, svg').exists()).toBe(true)
		await cancel.trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('names every missing field under the form and posts nothing', async () => {
		const wrapper = mountOpen()
		await flushPromises()

		await actions(wrapper).send.trigger('click')

		expect(wrapper.findAll('.form-error').map((node) => node.text())).toEqual([
			'BSN is required for Mijn Overheid messages',
			'Subject is required',
			'Message body is required',
		])
		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.emitted('sent')).toBeUndefined()
	})

	it('empties the masked BSN on focus and puts it back when left empty', async () => {
		mockGet.mockResolvedValue({
			data: {
				initiatorType: 'person',
				initiatorSourceId: '999997609',
				initiatorDisplayName: 'J. de Vries',
			},
		})
		const wrapper = mountOpen()
		await flushPromises()
		expect(wrapper.text()).toContain(
			'J. de Vries, from the case. Not shown to anyone.',
		)
		const bsnField = wrapper.find('.compose-dialog__field')
		expect(bsnInput(wrapper).element.value).toBe('*****7609')

		await bsnField.trigger('focusin')
		expect(bsnInput(wrapper).element.value).toBe('')
		expect(bsnInput(wrapper).attributes('placeholder')).toBe('*****7609')

		await bsnField.trigger('focusout')
		expect(bsnInput(wrapper).element.value).toBe('*****7609')
		expect(
			wrapper.find('[data-testid="compose-bsn-optional"]').text(),
		).toBe('(optional)')
	})

	it('sends what was picked, ticked and typed, and reports it sent', async () => {
		mockPost.mockResolvedValue({ data: { success: true } })
		const wrapper = mountOpen()
		await flushPromises()

		await bsnInput(wrapper).setValue('123456782')
		await wrapper.findAll('.NcSelect .stub-option')[0].trigger('click')
		await wrapper.find('.NcCheckboxRadioSwitch input').setValue(true)
		await wrapper.findAll('input.NcTextField')[1].setValue('Uw besluit')
		await wrapper.find('textarea#berichtenbox-compose-body').setValue('De tekst')
		expect(wrapper.find('.compose-dialog__char-count').text()).toBe(
			'8 of 4000 characters',
		)

		await actions(wrapper).send.trigger('click')
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/berichtenbox/send', {
			caseId: 'case-1',
			bsn: '123456782',
			subject: 'Uw besluit',
			body: 'De tekst',
			berichtTypeCode: 'decision',
			category: 'besluit',
		})
		expect(wrapper.emitted('sent')).toHaveLength(1)
		expect(wrapper.findAll('.form-error')).toHaveLength(0)
	})

	it('reads no recipient when there is no case to read', async () => {
		const wrapper = mountOpen({ caseId: '@objectId' })
		await flushPromises()

		expect(mockGet).not.toHaveBeenCalled()
		expect(bsnInput(wrapper).element.value).toBe('')
	})

	it('leaves the BSN empty when the case cannot be read', async () => {
		mockGet.mockRejectedValue(new Error('offline'))
		const wrapper = mountOpen()
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/dossiq/case/case-1',
		)
		expect(bsnInput(wrapper).element.value).toBe('')
		expect(wrapper.text()).not.toContain('from the case')
		await actions(wrapper).send.trigger('click')
		expect(wrapper.findAll('.form-error')[0].text()).toBe(
			'BSN is required for Mijn Overheid messages',
		)
	})
})
