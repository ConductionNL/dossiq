// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The upload dialog carries the keywords and the direction with the file.
 *
 * The dialog is the only place a keyword or a direction is ever typed, and
 * what it emits is what the upload endpoint stores. A field that renders but
 * is left out of the emitted metadata looks exactly like a field that works:
 * the upload succeeds and the tag is simply gone.
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A stub for one @nextcloud/vue form control that keeps its v-model.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'inputLabel',
			'label',
			'options',
			'reduce',
			'clearable',
			'required',
			'placeholder',
			'multiple',
			'taggable',
			'pushTags',
			'size',
			'value',
			'error',
			'disabled',
			'type',
		],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(
				'div',
				{
					class: name,
					'data-label': this.inputLabel || this.label,
					onClick: () => this.$emit('click'),
				},
				this.$slots.default ? this.$slots.default() : [],
			)
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcModal: control('NcModal'),
	NcProgressBar: control('NcProgressBar'),
	NcSelect: control('NcSelect'),
	NcTextArea: control('NcTextArea'),
	NcTextField: control('NcTextField'),
}))

// Imported AFTER the mock so the dialog sees the stubbed package.
const { default: DocumentMetadataDialog } =
	await import('../../src/modals/DocumentMetadataDialog.vue')

/**
 * Mount the dialog with a type catalogue and one pending file.
 *
 * @return {object} The mounted wrapper.
 */
function mountDialog() {
	return mount(DocumentMetadataDialog, {
		props: {
			open: true,
			files: [{ name: 'bezwaarschrift.pdf' }],
			types: [{ id: 'iot-1', description: 'Advies' }],
		},
	})
}

/**
 * The metadata one submit emitted.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {object} The emitted metadata.
 */
function submitted(wrapper) {
	const events = wrapper.emitted('submit')
	expect(events, 'the dialog must emit its metadata').toBeTruthy()
	return events[events.length - 1][0]
}

describe('DocumentMetadataDialog', () => {
	it('carries the typed keywords with the upload', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
			keywords: ['bezwaar', 'bouwtekening'],
		})

		wrapper.vm.submit()
		expect(submitted(wrapper).keywords).toEqual(['bezwaar', 'bouwtekening'])
	})

	it('sends keywords as plain strings, not as picker options', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
			keywords: [{ label: 'bezwaar' }, ' bouwtekening ', 'bezwaar', ''],
		})

		wrapper.vm.submit()
		expect(submitted(wrapper).keywords).toEqual(['bezwaar', 'bouwtekening'])
	})

	it('truncates a keyword to the 64 characters the schema allows', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
			keywords: ['a'.repeat(80)],
		})

		wrapper.vm.submit()
		expect(submitted(wrapper).keywords).toEqual(['a'.repeat(64)])
	})

	it('carries the chosen direction with the upload', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
			selectedDirection: 'incoming',
		})

		wrapper.vm.submit()
		expect(submitted(wrapper).direction).toBe('incoming')
	})

	it('defaults the direction to internal', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({
			selectedType: 'iot-1',
			selectedClassification: 'openbaar',
		})

		expect(wrapper.vm.selectedDirection).toBe('internal')
		wrapper.vm.submit()
		const metadata = submitted(wrapper)
		expect(metadata.direction).toBe('internal')
		expect(metadata.keywords).toEqual([])
	})

	it('offers the three directions the schema enumerates', () => {
		const wrapper = mountDialog()

		expect(wrapper.vm.directionOptions.map((option) => option.id)).toEqual([
			'incoming',
			'outgoing',
			'internal',
		])
		expect(wrapper.vm.directionOptions.map((option) => option.label)).toEqual([
			'Incoming',
			'Outgoing',
			'Internal',
		])
	})

	it('labels both new controls, so a screen reader can name them', () => {
		const wrapper = mountDialog()
		const labels = wrapper
			.findAll('.NcSelect')
			.map((select) => select.attributes('data-label'))

		expect(labels).toContain('Direction')
		expect(labels).toContain('Keywords')
	})

	it('keeps the required type and confidentiality gate', async () => {
		const wrapper = mountDialog()

		expect(wrapper.vm.canSubmit).toBe(false)
		wrapper.vm.submit()
		expect(wrapper.emitted('submit')).toBeUndefined()
	})
})
