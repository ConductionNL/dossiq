/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The requester projection back-fill, without the card that used to carry it.
 *
 * The initiator card left the case page on 2026-09-12. Its back-fill did not:
 * a case saved with only the canonical `requester` uuid (the semantic handoff
 * writes it that way) still has to gain its projection on first open, or the
 * case list's Requester column and filter read it as having no requester.
 * These are the card's three back-fill cases, moved to the headless component.
 *
 * @spec openspec/specs/initiator-display/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => ({}),
}))

/** The object store the component sees. Replaced per test. */
let storeStub = {}

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeStub,
}))

const { default: RequesterProjection } =
	await import('../../src/components/initiator/RequesterProjection.vue')

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

/**
 * Mount the component over a stub store and a route carrying a case id.
 *
 * @param {object} options Stub behaviour.
 * @param {Function} options.fetchObject What the store answers per type.
 * @param {Function} [options.saveObject] The write stub.
 * @return {Promise<object>} The mounted wrapper, after its load settles.
 */
async function mountProjection({
	fetchObject,
	saveObject = vi.fn().mockResolvedValue({}),
}) {
	storeStub = { fetchObject, saveObject }
	const wrapper = mount(RequesterProjection, {
		global: {
			mocks: {
				$route: { params: { id: 'case-1' } },
			},
		},
	})
	await flushPromises()
	await flushPromises()
	return wrapper
}

describe('RequesterProjection', () => {
	it('renders nothing a reader can see', async () => {
		const wrapper = await mountProjection({
			fetchObject: vi.fn().mockResolvedValue({ id: 'case-1' }),
		})
		const el = wrapper.get('[data-testid="requester-projection"]')
		expect(el.attributes('hidden')).toBeDefined()
		expect(wrapper.text()).toBe('')
	})

	it('fills the projection from a bare requester uuid on first load', async () => {
		const saveObject = vi.fn().mockResolvedValue({})
		const fetchObject = vi.fn().mockImplementation(async (type) => {
			if (type === 'case') {
				return { id: 'case-1', requester: 'uuid-person-1' }
			}
			return type === 'brpPerson' ? PERSON_ROW : null
		})

		await mountProjection({ fetchObject, saveObject })

		expect(saveObject).toHaveBeenCalledTimes(1)
		expect(saveObject.mock.calls[0][0]).toBe('case')
		expect(saveObject.mock.calls[0][1]).toMatchObject({
			id: 'case-1',
			requester: 'uuid-person-1',
			initiatorType: 'person',
			initiatorSourceId: '999990627',
			initiatorDisplayName: 'Stephan Janssen',
		})
	})

	it('does not re-write a projection the case already carries', async () => {
		const saveObject = vi.fn().mockResolvedValue({})
		const fetchObject = vi.fn().mockImplementation(async (type) =>
			type === 'case'
				? {
					id: 'case-1',
					requester: 'uuid-person-1',
					initiatorType: 'person',
					initiatorSourceId: '999990627',
					initiatorDisplayName: 'Stephan Janssen',
				}
				: PERSON_ROW,
		)

		await mountProjection({ fetchObject, saveObject })

		expect(saveObject).not.toHaveBeenCalled()
	})

	it('writes nothing for a case without a requester', async () => {
		const saveObject = vi.fn().mockResolvedValue({})
		await mountProjection({
			fetchObject: vi.fn().mockResolvedValue({ id: 'case-1' }),
			saveObject,
		})
		expect(saveObject).not.toHaveBeenCalled()
	})
})
