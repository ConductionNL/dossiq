import { flushPromises, mount } from '@vue/test-utils'
/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The New case form's Aanvrager is one search field (board DqNieuweZaak):
 * persons and companies come back as rows with a second line, and the field
 * writes the chosen row's uuid.
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

const ROWS = {
	brpPerson: [{ id: 'p-1', displayName: 'Henk Visser', residence: { street: 'Beukenlaan', houseNumber: 3 } }],
	kvkCompany: [{ id: 'c-1', tradeName: 'Visser Bouw B.V.', kvkNumber: '24157431' }],
}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchCollection: async (schema) => ROWS[schema],
		fetchObject: async (schema, id) => ROWS[schema].find((row) => row.id === id) || null,
	}),
}))

const { default: RequesterSearchField, requesterRow } = await import('../../src/components/initiator/RequesterSearchField.vue')

/**
 * Mount the field with CnSearchSelect stubbed.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountField(props = {}) {
	return mount(RequesterSearchField, {
		props: { field: { label: 'Requester' }, ...props },
		global: {
			stubs: {
				CnSearchSelect: {
					name: 'CnSearchSelect',
					props: ['modelValue', 'options'],
					emits: ['update:modelValue', 'search'],
					template: '<div />',
				},
			},
		},
	})
}

describe('RequesterSearchField', () => {
	it('shapes a person and a company as the board draws them', () => {
		expect(requesterRow('brpPerson', ROWS.brpPerson[0])).toEqual({
			id: 'p-1', label: 'Henk Visser', secondary: 'Resident · Beukenlaan 3', avatar: true,
		})
		expect(requesterRow('kvkCompany', ROWS.kvkCompany[0])).toEqual({
			id: 'c-1', label: 'Visser Bouw B.V.', secondary: 'Organisation · CoC 24157431', avatar: true,
		})
	})

	it('searches persons and companies together and writes the chosen uuid', async () => {
		vi.useFakeTimers()
		const wrapper = mountField()
		const select = wrapper.findComponent({ name: 'CnSearchSelect' })

		select.vm.$emit('search', 'Visser')
		await vi.runAllTimersAsync()
		await flushPromises()
		expect(wrapper.findComponent({ name: 'CnSearchSelect' }).props('options').map((row) => row.id)).toEqual(['p-1', 'c-1'])

		select.vm.$emit('update:modelValue', 'c-1')
		expect(wrapper.emitted('update:modelValue')).toEqual([['c-1']])
		vi.useRealTimers()
	})

	it('names the requester already on the form', async () => {
		const wrapper = mountField({ modelValue: 'c-1' })
		await flushPromises()

		expect(wrapper.findComponent({ name: 'CnSearchSelect' }).props('options')[0].label).toBe('Visser Bouw B.V.')
	})

	it('is the Aanvrager of the New case form on All cases', () => {
		const layout = fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8')
		const form = layout.slice(layout.indexOf('"formEyebrow": "All cases"'))

		expect(form.slice(0, form.indexOf('"widget": "', form.indexOf('"requester": {')) + 40)).toContain('"widget": "RequesterSearchField"')
	})
})
