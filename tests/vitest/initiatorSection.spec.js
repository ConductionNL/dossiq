// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The requester card on the case page (requester-on-the-case).
 *
 * Four behaviours are asserted, each of which the card is the only place
 * to observe: the person card renders name, type, number and address off
 * the resolved source row; a case with no requester renders nothing at
 * all; a case carrying only the canonical `requester` uuid gets its
 * projection filled on first render; and a protected person's BSN is
 * masked until a reveal, which is a single read carrying the reason.
 *
 * The spec file lives here rather than beside the component: vitest.config
 * collects `tests/vitest/**` only, so a spec under `src/` would never run.
 *
 * @spec openspec/specs/initiator-display/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: defineComponent({
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	}),
}))

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => ({}),
}))

/** The object store the component sees. Replaced per test. */
let storeStub = {}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeStub,
}))

const { default: InitiatorSection } =
	await import('../../src/components/initiator/InitiatorSection.vue')

const PERSON_ROW = {
	id: 'uuid-person-1',
	citizenServiceNumber: '999990627',
	displayName: 'Stephan Janssen',
	residence: {
		street: 'Mandelaplein',
		houseNumber: 2,
		postcode: '2572HT',
		city: "'s-Gravenhage",
	},
}

const PROTECTED_ROW = {
	id: 'uuid-person-2',
	citizenServiceNumber: '999990792',
	displayName: 'Jan de Cuykelaer',
	indicatieGeheim: true,
	residence: {
		street: 'Thorbeckelaan',
		houseNumber: 731,
		postcode: '2564CJ',
		city: "'s-Gravenhage",
	},
}

/**
 * Mount the card over a stub store and a route carrying a case id.
 *
 * @param {object} options Stub behaviour.
 * @param {object} options.caseObject What `fetchObject('case', id)` answers.
 * @param {Array} [options.rows] What `fetchCollection` answers.
 * @param {Function} [options.fetchObject] Override the whole object read.
 * @param {Function} [options.saveObject] The write stub.
 * @param {Array} [options.calls] Filled with every fetchCollection call.
 * @return {Promise<object>} The mounted wrapper, after its load settles.
 */
async function mountCard({
	caseObject,
	rows = [],
	fetchObject = null,
	saveObject = vi.fn().mockResolvedValue({}),
	calls = [],
}) {
	storeStub = {
		fetchObject:
			fetchObject
			|| vi
				.fn()
				.mockImplementation(async (type) =>
					type === 'case' ? caseObject : null,
				),
		fetchCollection: vi.fn().mockImplementation(async (type, params) => {
			calls.push([type, params])
			return rows
		}),
		saveObject,
	}
	const wrapper = mount(InitiatorSection, {
		global: {
			mocks: {
				t: (_app, text) => text,
				$route: { params: { id: 'case-1' } },
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('InitiatorSection — the requester on the case', () => {
	it('renders the person, the type, the number and the address', async () => {
		const wrapper = await mountCard({
			caseObject: {
				id: 'case-1',
				initiatorType: 'person',
				initiatorSourceId: '999990627',
				initiatorDisplayName: 'Stephan Janssen',
			},
			rows: [PERSON_ROW],
		})

		expect(wrapper.get('[data-testid="initiator-name"]').text()).toBe(
			'Stephan Janssen',
		)
		expect(wrapper.get('[data-testid="initiator-type"]').text()).toBe('Person')
		expect(wrapper.get('[data-testid="initiator-source-link"]').text()).toBe(
			'999990627',
		)
		// The number links to the person's CONTACT PAGE, not to OpenRegister's
		// raw object viewer. contacts-domain gives a person a page of their own
		// with their cases on it; the old deep link showed every field of the
		// register set, none of their cases, and left the app to do it.
		expect(
			wrapper.get('[data-testid="initiator-source-link"]').attributes('href'),
		).toContain('/apps/dossiq/contacts/uuid-person-1')
		// And it opens in this tab: an in-app route is not somewhere else.
		expect(
			wrapper
				.get('[data-testid="initiator-source-link"]')
				.attributes('target'),
		).toBeUndefined()
		expect(wrapper.get('[data-testid="initiator-address"]').text()).toBe(
			"Mandelaplein 2, 2572HT, 's-Gravenhage",
		)
		expect(wrapper.find('[data-testid="initiator-protected"]').exists()).toBe(
			false,
		)
	})

	it('renders the company address off the KvK block', async () => {
		const wrapper = await mountCard({
			caseObject: {
				id: 'case-1',
				initiatorType: 'company',
				initiatorSourceId: '69599084',
				initiatorDisplayName: 'Test EMZ Dagobert',
			},
			rows: [
				{
					id: 'uuid-company-1',
					kvkNumber: '69599084',
					tradeName: 'Test EMZ Dagobert',
					address: {
						streetName: 'Hoofdstraat',
						houseNumber: 1,
						postcode: '1234AB',
						place: 'Utrecht',
					},
				},
			],
		})

		expect(wrapper.get('[data-testid="initiator-type"]').text()).toBe('Company')
		expect(wrapper.get('[data-testid="initiator-address"]').text()).toBe(
			'Hoofdstraat 1, 1234AB, Utrecht',
		)
		// A company goes to OrganisationDetail, not to ContactDetail. A detail
		// page takes one schema, so the two contact pages are separate, and a
		// company sent to /contacts/:id would render a person page over a
		// kvkCompany row: every field empty, and nothing raised.
		expect(
			wrapper.get('[data-testid="initiator-source-link"]').attributes('href'),
		).toContain('/apps/dossiq/organisations/uuid-company-1')
	})

	it('offers no contact link for a Nextcloud contact, which has no register row', async () => {
		const wrapper = await mountCard({
			caseObject: {
				id: 'case-1',
				initiatorType: 'contact',
				initiatorSourceId: 'nc-contact-1',
				initiatorDisplayName: 'Ada Lovelace',
			},
			rows: [],
		})

		expect(wrapper.find('[data-testid="initiator-source-link"]').exists()).toBe(
			false,
		)
		expect(wrapper.get('[data-testid="initiator-source-id"]').text()).toBe(
			'nc-contact-1',
		)
	})

	it('says so for a case without a requester, instead of an empty box', async () => {
		// 🔴 IT USED TO RENDER NOTHING, on a rule written "no initiator, no
		// clutter". That rule assumed this component could decide whether it
		// appeared at all. It cannot: the manifest declares `initiator` as a
		// grid cell with `showTitle: true`, so the card chrome and the word
		// Initiator painted regardless and the body below them was blank. The
		// page therefore showed an empty titled box on every case with no
		// requester, which is the demo case and most real ones early on.
		//
		// A sentence costs the same space and answers the question the blank
		// box raised.
		const wrapper = await mountCard({ caseObject: { id: 'case-1' } })

		expect(wrapper.find('[data-testid="initiator-section"]').exists()).toBe(true)
		expect(wrapper.get('[data-testid="initiator-empty"]').text()).toBe(
			'No initiator has been recorded for this case.',
		)
		// Still no requester rows: the empty state replaces them, it does not
		// sit above a half-rendered card.
		expect(wrapper.find('[data-testid="initiator-name"]').exists()).toBe(false)
	})

	it('drops the empty line the moment a requester is present', async () => {
		const wrapper = await mountCard({
			caseObject: {
				id: 'case-1',
				requester: 'uuid-person-1',
				initiatorDisplayName: 'Jan Bakker',
				initiatorType: 'person',
			},
		})

		expect(wrapper.find('[data-testid="initiator-empty"]').exists()).toBe(false)
	})

	it('fills the projection from a bare requester uuid on first render', async () => {
		const saveObject = vi.fn().mockResolvedValue({})
		const fetchObject = vi.fn().mockImplementation(async (type) => {
			if (type === 'case') {
				return { id: 'case-1', requester: 'uuid-person-1' }
			}
			return type === 'brpPerson' ? PERSON_ROW : null
		})

		const wrapper = await mountCard({
			caseObject: null,
			fetchObject,
			saveObject,
		})

		expect(wrapper.get('[data-testid="initiator-name"]').text()).toBe(
			'Stephan Janssen',
		)
		expect(saveObject).toHaveBeenCalledTimes(1)
		expect(saveObject.mock.calls[0][1]).toMatchObject({
			id: 'case-1',
			initiatorType: 'person',
			initiatorSourceId: '999990627',
			initiatorDisplayName: 'Stephan Janssen',
		})
	})

	it('does not re-write a projection the case already carries', async () => {
		const saveObject = vi.fn().mockResolvedValue({})
		await mountCard({
			caseObject: {
				id: 'case-1',
				requester: 'uuid-person-1',
				initiatorType: 'person',
				initiatorSourceId: '999990627',
				initiatorDisplayName: 'Stephan Janssen',
			},
			rows: [PERSON_ROW],
			saveObject,
		})

		expect(saveObject).not.toHaveBeenCalled()
	})

	it('masks a protected BSN and marks the person as protected', async () => {
		const wrapper = await mountCard({
			caseObject: {
				id: 'case-1',
				initiatorType: 'person',
				initiatorSourceId: '999990792',
				initiatorDisplayName: 'Jan de Cuykelaer',
			},
			rows: [PROTECTED_ROW],
		})

		expect(wrapper.find('[data-testid="initiator-protected"]').exists()).toBe(
			true,
		)
		expect(wrapper.get('[data-testid="initiator-source-link"]').text()).toBe(
			'•••••0792',
		)
		expect(wrapper.find('[data-testid="initiator-reveal"]').exists()).toBe(true)
	})

	it('shows the full number after a reveal, as one read carrying the reason', async () => {
		const calls = []
		const wrapper = await mountCard({
			caseObject: {
				id: 'case-1',
				initiatorType: 'person',
				initiatorSourceId: '999990792',
				initiatorDisplayName: 'Jan de Cuykelaer',
			},
			rows: [PROTECTED_ROW],
			calls,
		})

		await wrapper.get('[data-testid="initiator-reveal"]').trigger('click')
		await flushPromises()

		expect(wrapper.get('[data-testid="initiator-source-link"]').text()).toBe(
			'999990792',
		)
		const reveals = calls.filter(([, params]) => params._reason === 'bsn-reveal')
		expect(reveals).toHaveLength(1)
		expect(reveals[0][0]).toBe('brpPerson')
		expect(reveals[0][1]).toMatchObject({
			citizenServiceNumber: '999990792',
			_reason: 'bsn-reveal',
		})
		// The reveal is spent: the button goes away rather than logging a
		// second read for a number already on screen.
		expect(wrapper.find('[data-testid="initiator-reveal"]').exists()).toBe(false)
	})

	it('leaves an unprotected person unmasked and unmarked', async () => {
		const wrapper = await mountCard({
			caseObject: {
				id: 'case-1',
				initiatorType: 'person',
				initiatorSourceId: '999990627',
				initiatorDisplayName: 'Stephan Janssen',
			},
			rows: [PERSON_ROW],
		})

		expect(wrapper.get('[data-testid="initiator-source-link"]').text()).toBe(
			'999990627',
		)
		expect(wrapper.find('[data-testid="initiator-reveal"]').exists()).toBe(false)
	})
})
