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
		NcNoteCard: passthrough('NcNoteCard'),
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

/**
 * What OpenRegister answers when asked who already holds an address.
 *
 * Replaced per test. The picker asks BEFORE a second record is created for
 * a person somebody already wrote down, which is the same call
 * `docs/Features/parties.md` asks of integriq's BRP and KvK adapters.
 */
let resolvedParty = null

vi.mock('../../src/services/caseParties.js', () => ({
	resolvePartyByAddress: vi.fn(async () => resolvedParty),
}))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeStub,
}))

const { default: InitiatorPicker } =
	await import('../../src/components/initiator/InitiatorPicker.vue')

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
 * @param {object} [options.errors] The store's per-type error map.
 * @return {object} The mounted wrapper.
 */
function mountPicker({
	value = null,
	fetchCollection = vi.fn().mockResolvedValue([]),
	fetchObject = vi.fn().mockResolvedValue(null),
	errors = {},
} = {}) {
	storeStub = { fetchCollection, fetchObject, errors }
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
		await wrapper.vm.select(wrapper.vm.results[0])

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
		await wrapper.vm.select(wrapper.vm.results[0])

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

	it('leaves requester empty for a contact, which has no register row', async () => {
		const wrapper = mountPicker()

		await wrapper.vm.select({
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
		const fetchObject = vi
			.fn()
			.mockImplementation(async (schema) =>
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
		const fetchObject = vi
			.fn()
			.mockImplementation(async (schema) =>
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

describe('InitiatorPicker — no second party for an address somebody holds', () => {
	it('names the party that already holds the contact address', async () => {
		resolvedParty = { id: 'uuid-party-1', name: 'Jan de Vries' }
		const wrapper = mountPicker()

		await wrapper.vm.select({
			type: 'contact',
			sourceId: 'contact-uid-1',
			displayName: 'Jan de Vries',
			detail: 'jan@example.nl',
			objectId: null,
		})

		expect(wrapper.emitted('select')[0][0].requester).toBe('uuid-party-1')
	})

	it('records the choice unchanged when no party holds the address', async () => {
		resolvedParty = null
		const wrapper = mountPicker()

		await wrapper.vm.select({
			type: 'contact',
			sourceId: 'contact-uid-1',
			displayName: 'Jan de Vries',
			detail: 'jan@example.nl',
			objectId: null,
		})

		expect(wrapper.emitted('select')[0][0]).toEqual({
			requester: '',
			initiatorType: 'contact',
			initiatorSourceId: 'contact-uid-1',
			initiatorDisplayName: 'Jan de Vries',
		})
	})

	it('does not ask about a register row it just picked', async () => {
		resolvedParty = { id: 'uuid-party-1', name: 'Somebody else' }
		const wrapper = mountPicker()

		await wrapper.vm.select({
			type: 'person',
			sourceId: '999990627',
			displayName: 'Stephan Janssen',
			detail: 'BSN 999990627',
			objectId: 'uuid-person-1',
		})

		expect(wrapper.emitted('select')[0][0].requester).toBe('uuid-person-1')
	})
})

describe('InitiatorPicker — a refused term is not an empty register', () => {
	/**
	 * 🔴 `fetchCollection()` RESOLVES WITH `[]` ON A 400. OpenRegister refuses
	 * a term it cannot parse rather than running it as a literal, precisely so
	 * the answer is not an honest-looking zero. The store then swallows that
	 * into an empty array and an entry on `errors`, so a picker reading only
	 * the returned rows says "No results" for a bracket the reader forgot to
	 * close, and the reader searches for a different person.
	 *
	 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
	 */
	const refusal = {
		status: 400,
		message: 'Unbalanced bracket at position 1.',
		details: 'Unbalanced bracket at position 1.',
		isValidation: true,
		fields: null,
	}

	it('shows the refusal instead of the empty state', async () => {
		const wrapper = mountPicker({ errors: { brpPerson: refusal } })

		wrapper.vm.query = '(Janssen'
		await wrapper.vm.runSearch()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="initiator-picker-refusal"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('We could not read this search from character 1.')
		expect(wrapper.text()).toContain('Unbalanced bracket at position 1.')
		expect(wrapper.find('.NcEmptyContent-stub').exists()).toBe(false)
	})

	it('holds back the rows the refused fetch returned', async () => {
		const fetchCollection = vi.fn().mockResolvedValue([PERSON_ROW])
		const wrapper = mountPicker({ fetchCollection, errors: { brpPerson: refusal } })

		wrapper.vm.query = '(Janssen'
		await wrapper.vm.runSearch()

		expect(wrapper.vm.results).toEqual([])
	})

	it('clears the refusal once the next term reads', async () => {
		const wrapper = mountPicker({ errors: { brpPerson: refusal } })

		wrapper.vm.query = '(Janssen'
		await wrapper.vm.runSearch()
		expect(wrapper.vm.refusal).not.toBeNull()

		wrapper.vm.objectStore.errors = {}
		wrapper.vm.query = 'Janssen'
		await wrapper.vm.runSearch()

		expect(wrapper.vm.refusal).toBeNull()
	})

	it('clears the refusal when the box is emptied, which searches nothing', async () => {
		// The early return for an empty box skips the fetch entirely, so this
		// is the one path where only the reset at the top of runSearch can
		// clear a refusal. Without it the hint outlives the term it is about.
		const wrapper = mountPicker({ errors: { brpPerson: refusal } })

		wrapper.vm.query = '(Janssen'
		await wrapper.vm.runSearch()
		expect(wrapper.vm.refusal).not.toBeNull()

		wrapper.vm.query = ''
		await wrapper.vm.runSearch()

		expect(wrapper.vm.refusal).toBeNull()
	})

	it('leaves a search that was not refused alone', async () => {
		const fetchCollection = vi.fn().mockResolvedValue([PERSON_ROW])
		const wrapper = mountPicker({ fetchCollection })

		wrapper.vm.query = 'Janssen'
		await wrapper.vm.runSearch()

		expect(wrapper.vm.refusal).toBeNull()
		expect(wrapper.vm.results).toHaveLength(1)
	})

	it('still passes the typed term through unchanged', async () => {
		const fetchCollection = vi.fn().mockResolvedValue([])
		const wrapper = mountPicker({ fetchCollection })

		wrapper.vm.query = '  Jans* AND NOT "de Vries"  '
		await wrapper.vm.runSearch()

		expect(fetchCollection).toHaveBeenCalledWith('brpPerson', {
			_search: 'Jans* AND NOT "de Vries"',
			_limit: 20,
		})
	})
})
