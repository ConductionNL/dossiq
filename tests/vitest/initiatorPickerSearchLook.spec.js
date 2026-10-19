/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * InitiatorPicker as a form field (board DqNieuweZaak, Aanvrager): one search
 * over persons and companies, rows with a second line, and the chosen row's
 * uuid written back to the form. It stays the one picker for case.requester.
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

const ROWS = {
	brpPerson: [{ id: 'p-1', displayName: 'Henk Visser', residence: { street: 'Beukenlaan', houseNumber: 3 } }],
	kvkCompany: [{ id: 'c-1', tradeName: 'Visser Bouw B.V.', kvkNumber: '24157431' }],
}

const storeErrors = {}
const fetchObjectCalls = []

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchCollection: async (schema) => (storeErrors[schema] ? [] : ROWS[schema]),
		fetchObject: async (schema, id) => {
			fetchObjectCalls.push([schema, id])
			return ROWS[schema].find((row) => row.id === id) || null
		},
		errors: storeErrors,
	}),
}))

const { default: InitiatorPicker, searchLookRow } = await import('../../src/components/initiator/InitiatorPicker.vue')

/**
 * Mount the picker as CnFormDialog mounts a field widget.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountField(props = {}) {
	return mount(InitiatorPicker, {
		props: { field: { key: 'requester', label: 'Requester' }, ...props },
		global: {
			mocks: { t: (app, text) => text },
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

describe('InitiatorPicker as a form field', () => {
	it('shapes a person and a company as the board draws them', () => {
		expect(searchLookRow('brpPerson', ROWS.brpPerson[0])).toEqual({
			id: 'p-1', label: 'Henk Visser', secondary: 'Resident · Beukenlaan 3', avatar: true,
		})
		expect(searchLookRow('kvkCompany', ROWS.kvkCompany[0])).toEqual({
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

		const select = wrapper.findComponent({ name: 'CnSearchSelect' })
		expect(select.props('modelValue')).toBe('c-1')
		expect(select.props('options')[0].label).toBe('Visser Bouw B.V.')
	})

	it('says a refused term instead of showing no rows', async () => {
		vi.useFakeTimers()
		storeErrors.brpPerson = { status: 400, message: 'Unbalanced bracket' }
		const wrapper = mountField()

		wrapper.findComponent({ name: 'CnSearchSelect' }).vm.$emit('search', 'Visser (')
		await vi.runAllTimersAsync()
		await flushPromises()

		expect(wrapper.find('[data-testid="initiator-picker-refusal"]').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'CnSearchSelect' }).props('options')).toEqual([])
		delete storeErrors.brpPerson
		vi.useRealTimers()
	})

	it('lets only the latest query write its rows', async () => {
		const wrapper = mountField()
		const slow = wrapper.vm.runLookSearch('Henk')
		const fast = wrapper.vm.runLookSearch('Visser')
		await Promise.all([slow, fast])

		expect(wrapper.vm.lookSeq).toBe(2)
		expect(wrapper.vm.searching).toBe(false)
	})

	it('does not read the picked row again', async () => {
		vi.useFakeTimers()
		fetchObjectCalls.length = 0
		const wrapper = mountField()
		wrapper.findComponent({ name: 'CnSearchSelect' }).vm.$emit('search', 'Visser')
		await vi.runAllTimersAsync()
		await flushPromises()

		wrapper.findComponent({ name: 'CnSearchSelect' }).vm.$emit('update:modelValue', 'c-1')
		await wrapper.setProps({ modelValue: 'c-1' })
		await flushPromises()

		expect(fetchObjectCalls).toEqual([])
		vi.useRealTimers()
	})

	it('keeps the tabbed picker when it is not a form field', () => {
		const wrapper = mountField({ field: null })

		expect(wrapper.find('[data-testid="initiator-picker"]').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'CnSearchSelect' }).exists()).toBe(false)
	})

	it('is the only form field the registry gives case.requester', () => {
		const registry = fs.readFileSync(path.resolve(__dirname, '../../src/registry.js'), 'utf8')
		const owners = [...registry.matchAll(/appliesTo: \[([^\]]*)\]/g)].filter((match) => match[1].includes("'case.requester'"))

		expect(owners).toHaveLength(1)
	})
})
