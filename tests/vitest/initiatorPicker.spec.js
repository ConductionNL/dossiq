// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The initiator picker writes the requester once (requester-on-the-case).
 *
 * What is asserted here is the seam the form depends on: picking a row
 * emits the four fields the case carries, with `requester` the uuid of the
 * chosen register row, and a contact leaving it empty because a contact has
 * no register row. The picker also has to recognise a requester already on
 * the case, including the bare uuid `case.requester` holds when the
 * projection came from the semantic handoff.
 *
 * The spec file lives here rather than beside the component: vitest.config
 * collects `tests/vitest/**` only, so a spec under `src/` would never run.
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/vue', () => {
	const passthrough = (name, tag = 'div') =>
		defineComponent({
			name,
			props: ['label', 'placeholder', 'modelValue', 'value', 'name', 'type'],
			render() {
				return h(tag, { class: `${name}-stub` }, this.$slots.default?.())
			},
		})
	return {
		NcCheckboxRadioSwitch: passthrough('NcCheckboxRadioSwitch', 'label'),
		NcEmptyContent: passthrough('NcEmptyContent'),
		NcLoadingIcon: passthrough('NcLoadingIcon'),
		NcTextField: passthrough('NcTextField', 'input'),
	}
})

/**
 * The object store the component sees. Replaced per test.
 *
 * Mocking the store MODULE rather than overriding a computed: Vue Test
 * Utils 2 has no `computed` mount option, and passing one replaces the
 * component's whole computed block, which silently deletes every other
 * computed on the component.
 */
let storeStub = {}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeStub,
}))

const { default: InitiatorPicker } = await import(
	'../../src/components/initiator/InitiatorPicker.vue'
)

const PERSON_ROW = {
	id: 'uuid-person-1',
	citizenServiceNumber: '999990627',
	displayName: 'Stephan Janssen',
	birth: { date: '1975-04-06' },
}

const COMPANY_ROW = {
	id: 'uuid-company-1',
	kvkNumber: '69599084',
	tradeName: 'Test EMZ Dagobert',
	legalForm: 'Eenmanszaak',
}

/**
 * Mount the picker with a stub object store.
 *
 * @param {object} [options] Mount options.
 * @param {object|string|null} [options.value] The `value` prop.
 * @param {Function} [options.fetchCollection] Collection stub.
 * @param {Function} [options.fetchObject] Single-object stub.
 * @return {object} The mounted wrapper.
 */
function mountPicker({
	value = null,
	fetchCollection = vi.fn().mockResolvedValue([]),
	fetchObject = vi.fn().mockResolvedValue(null),
} = {}) {
	storeStub = { fetchCollection, fetchObject }
	return mount(InitiatorPicker, {
		props: { value },
		global: {
			mocks: { t: (_app, text) => text },
		},
	})
}

describe('InitiatorPicker — one write path for the requester', () => {
	it('emits the uuid and the projection when a person is picked', async () => {
		const fetchCollection = vi.fn().mockResolvedValue([PERSON_ROW])
		const wrapper = mountPicker({ fetchCollection })

		wrapper.vm.query = 'Janssen'
		await wrapper.vm.runSearch()
		wrapper.vm.select(wrapper.vm.results[0])

		expect(fetchCollection).toHaveBeenCalledWith('brpPerson', {
			_search: 'Janssen',
			_limit: 20,
		})
		expect(wrapper.emitted('select')[0][0]).toEqual({
			requester: 'uuid-person-1',
			initiatorType: 'person',
			initiatorSourceId: '999990627',
			initiatorDisplayName: 'Stephan Janssen',
		})
	})

	it('emits the uuid and the projection when a company is picked', async () => {
		const fetchCollection = vi.fn().mockResolvedValue([COMPANY_ROW])
		const wrapper = mountPicker({ fetchCollection })

		wrapper.vm.activeTab = 'company'
		wrapper.vm.query = 'Dagobert'
		await wrapper.vm.runSearch()
		wrapper.vm.select(wrapper.vm.results[0])

		expect(fetchCollection).toHaveBeenCalledWith('kvkCompany', {
			_search: 'Dagobert',
			_limit: 20,
		})
		expect(wrapper.emitted('select')[0][0]).toEqual({
			requester: 'uuid-company-1',
			initiatorType: 'company',
			initiatorSourceId: '69599084',
			initiatorDisplayName: 'Test EMZ Dagobert',
		})
	})

	it('leaves requester empty for a contact, which has no register row', () => {
		const wrapper = mountPicker()

		wrapper.vm.select({
			type: 'contact',
			sourceId: 'uid-9',
			displayName: 'Anna de Wit',
			objectId: null,
		})

		expect(wrapper.emitted('select')[0][0]).toEqual({
			requester: '',
			initiatorType: 'contact',
			initiatorSourceId: 'uid-9',
			initiatorDisplayName: 'Anna de Wit',
		})
	})

	it('marks the emitted payload as the current choice when it comes back in', async () => {
		const fetchCollection = vi.fn().mockResolvedValue([PERSON_ROW])
		const wrapper = mountPicker({
			fetchCollection,
			value: {
				requester: 'uuid-person-1',
				initiatorType: 'person',
				initiatorSourceId: '999990627',
				initiatorDisplayName: 'Stephan Janssen',
			},
		})

		wrapper.vm.query = 'Janssen'
		await wrapper.vm.runSearch()

		expect(wrapper.vm.isSelected(wrapper.vm.results[0])).toBe(true)
		expect(wrapper.vm.currentChoice).toMatchObject({
			type: 'person',
			sourceId: '999990627',
			displayName: 'Stephan Janssen',
		})
	})

	it('resolves a bare requester uuid to the row it names', async () => {
		const fetchObject = vi.fn().mockImplementation(async (schema) =>
			schema === 'brpPerson' ? PERSON_ROW : null,
		)
		const wrapper = mountPicker({ value: 'uuid-person-1', fetchObject })

		await wrapper.vm.resolveValue()

		expect(fetchObject).toHaveBeenCalledWith('brpPerson', 'uuid-person-1')
		expect(wrapper.vm.currentChoice).toMatchObject({
			type: 'person',
			displayName: 'Stephan Janssen',
		})
	})

	it('falls back to the company set for a uuid the person set does not hold', async () => {
		const fetchObject = vi.fn().mockImplementation(async (schema) =>
			schema === 'kvkCompany' ? COMPANY_ROW : null,
		)
		const wrapper = mountPicker({ value: 'uuid-company-1', fetchObject })

		await wrapper.vm.resolveValue()

		expect(fetchObject).toHaveBeenCalledWith('kvkCompany', 'uuid-company-1')
		expect(wrapper.vm.currentChoice).toMatchObject({
			type: 'company',
			displayName: 'Test EMZ Dagobert',
		})
	})

	it('shows no current choice for a uuid neither set can resolve', async () => {
		const wrapper = mountPicker({ value: 'uuid-nobody' })

		await wrapper.vm.resolveValue()

		expect(wrapper.vm.currentChoice).toBe(null)
	})
})
