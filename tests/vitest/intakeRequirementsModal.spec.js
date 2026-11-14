// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * IntakeRequirementsModal: what a case type asks before a case exists. Every
 * required field is asked once, as a pick list when the declaration offers
 * values and as text otherwise, and the case can only be opened once every
 * answer is in and the classification scheme resolves.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

function control(name) {
	return defineComponent({
		name,
		props: [
			'modelValue',
			'inputLabel',
			'label',
			'options',
			'disabled',
			'name',
			'variant',
			'size',
		],
		emits: ['update:modelValue', 'close', 'click'],
		render() {
			return h(
				'div',
				{ class: name, 'data-label': this.inputLabel ?? this.label ?? '' },
				this.$slots.default?.() ?? [],
			)
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcModal: control('NcModal'),
	NcSelect: control('NcSelect'),
	NcTextField: control('NcTextField'),
}))

const { default: IntakeRequirementsModal } =
	await import('../../src/modals/IntakeRequirementsModal.vue')

const declaration = {
	intakeRequirements: {
		requiredBeforeCreation: [
			'confidentiality',
			'communicationChannel',
			'confidentiality',
			'customField',
		],
	},
	classification: { classificationIsAccessRule: true, scheme: 'bsi' },
	classificationValues: ['public', 'restricted'],
}

function confirmButton(wrapper) {
	return wrapper.findAllComponents({ name: 'NcButton' })[1]
}

describe('IntakeRequirementsModal', () => {
	it('asks each required field once, plus the classification when it is an access rule', () => {
		const wrapper = mount(IntakeRequirementsModal, { props: { declaration } })

		const asked = wrapper
			.findAll('[data-testid^="intake-field-"]')
			.map((f) => f.attributes('data-testid'))
		expect(asked).toEqual([
			'intake-field-confidentiality',
			'intake-field-communicationChannel',
			'intake-field-customField',
			'intake-field-classification',
		])
		expect(
			wrapper
				.findAllComponents({ name: 'NcSelect' })
				.map((s) => s.props('inputLabel')),
		).toEqual(['Confidentiality', 'Classification'])
		expect(
			wrapper
				.findAllComponents({ name: 'NcTextField' })
				.map((s) => s.props('label')),
		).toEqual(['Communication channel', 'customField'])
		expect(
			wrapper.findAllComponents({ name: 'NcSelect' })[1].props('options'),
		).toEqual(['public', 'restricted'])
	})

	it('opens the case only once every answer is in, and confirms a copy of the answers', async () => {
		const wrapper = mount(IntakeRequirementsModal, { props: { declaration } })
		expect(confirmButton(wrapper).props('disabled')).toBe(true)

		const [confidentiality, classification] = wrapper.findAllComponents({
			name: 'NcSelect',
		})
		const [channel, custom] = wrapper.findAllComponents({ name: 'NcTextField' })
		confidentiality.vm.$emit('update:modelValue', 'intern')
		classification.vm.$emit('update:modelValue', {
			id: 'restricted',
			label: 'Restricted',
		})
		channel.vm.$emit('update:modelValue', 'email')
		await wrapper.vm.$nextTick()
		expect(confirmButton(wrapper).props('disabled')).toBe(true)

		custom.vm.$emit('update:modelValue', '   ')
		await wrapper.vm.$nextTick()
		expect(confirmButton(wrapper).props('disabled')).toBe(true)

		custom.vm.$emit('update:modelValue', 'yes')
		await wrapper.vm.$nextTick()
		expect(confirmButton(wrapper).props('disabled')).toBe(false)

		confirmButton(wrapper).vm.$emit('click')
		expect(wrapper.emitted('confirm')[0][0]).toEqual({
			confidentiality: 'intern',
			classification: 'restricted',
			communicationChannel: 'email',
			customField: 'yes',
		})
	})

	it('reads an option without an id by its label, and a cleared value as empty', async () => {
		const wrapper = mount(IntakeRequirementsModal, {
			props: {
				declaration: {
					intakeRequirements: { requiredBeforeCreation: ['assignee'] },
				},
			},
		})

		wrapper.vm.setValue('assignee', { label: 'Ann' })
		expect(wrapper.vm.values).toEqual({ assignee: 'Ann' })
		wrapper.vm.setValue('assignee', null)
		expect(wrapper.vm.values).toEqual({ assignee: '' })
		expect(wrapper.vm.canOpen).toBe(false)
	})

	it('cannot open a case whose classification scheme does not resolve', () => {
		const wrapper = mount(IntakeRequirementsModal, {
			props: {
				declaration: {
					classification: { classificationIsAccessRule: true },
					classificationValues: ['a'],
					schemeResolves: false,
				},
			},
		})
		wrapper.vm.setValue('classification', 'a')

		expect(
			wrapper.find('[data-testid="intake-scheme-unresolved"]').exists(),
		).toBe(true)
		expect(wrapper.vm.canOpen).toBe(false)
	})

	it('closes from the modal and from Cancel', () => {
		const wrapper = mount(IntakeRequirementsModal, {
			props: { declaration: {} },
		})

		wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		wrapper.findAllComponents({ name: 'NcButton' })[0].vm.$emit('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.vm.canOpen).toBe(true)
	})
})
