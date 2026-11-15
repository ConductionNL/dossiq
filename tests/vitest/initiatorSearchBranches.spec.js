/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every branch of the initiator result shapes and of the requester choice:
 * each key casing, each fallback, and the address lookup for a contact.
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	companyResult,
	contactResult,
	initiatorChoice,
	personResult,
} from '../../src/services/initiatorSearch.js'

const resolveParty = vi.hoisted(() => vi.fn())

vi.mock('../../src/services/caseParties.js', () => ({
	resolvePartyByAddress: (...args) => resolveParty(...args),
}))

describe('personResult fallbacks', () => {
	it('reads the snake_case number, the composed name and the @self id', () => {
		expect(personResult({
			citizen_service_number: '123',
			name: { givenNames: 'Ann', surname: 'Bos' },
			'@self': { id: 's-1' },
		})).toEqual({
			type: 'person',
			sourceId: '123',
			displayName: 'Ann Bos',
			detail: 'BSN 123',
			objectId: 's-1',
			protected: false,
		})
	})

	it('answers blanks and null for a bare row, and only true is protected', () => {
		expect(personResult({ birth: { date: '1990-01-01' }, indicatieGeheim: 'yes' })).toEqual({
			type: 'person',
			sourceId: '',
			displayName: '',
			detail: '1990-01-01',
			objectId: null,
			protected: false,
		})
	})
})

describe('companyResult fallbacks', () => {
	it('reads the snake_case keys and the @self id', () => {
		expect(companyResult({
			kvk_number: '6900',
			trade_name: 'Bakker BV',
			legal_form: 'BV',
			'@self': { id: 'c-1' },
		})).toEqual({
			type: 'company',
			sourceId: '6900',
			displayName: 'Bakker BV',
			detail: 'BV · KVK 6900',
			objectId: 'c-1',
		})
	})

	it('prefers the camelCase keys and answers blanks for a bare row', () => {
		expect(companyResult({ kvkNumber: '1', kvk_number: '2', tradeName: 'A', trade_name: 'B', legalForm: 'NV', legal_form: 'BV', id: 'i' }))
			.toEqual({ type: 'company', sourceId: '1', displayName: 'A', detail: 'NV · KVK 1', objectId: 'i' })
		expect(companyResult({})).toEqual({ type: 'company', sourceId: '', displayName: '', detail: '', objectId: null })
	})
})

describe('contactResult fallbacks', () => {
	it.each([
		[{ uid: 'u', fullName: 'F', emailAddresses: ['e@x'] }, { sourceId: 'u', displayName: 'F', detail: 'e@x' }],
		[{ emailAddresses: ['e@x'], fullName: 'F' }, { sourceId: 'e@x', displayName: 'F', detail: 'e@x' }],
		[{ fullName: 'F', topAction: { title: 'Mail F' } }, { sourceId: 'F', displayName: 'F', detail: 'Mail F' }],
		[{ id: 'i', emailAddresses: [] }, { sourceId: 'i', displayName: 'i', detail: '' }],
		[{}, { sourceId: '', displayName: '', detail: '' }],
	])('shapes %j', (contact, expected) => {
		expect(contactResult(contact)).toEqual({ type: 'contact', objectId: null, ...expected })
	})
})

describe('initiatorChoice branches', () => {
	beforeEach(() => {
		resolveParty.mockReset()
	})

	it('answers nothing for a missing row', async () => {
		expect(await initiatorChoice('person', null)).toEqual({})
		expect(resolveParty).not.toHaveBeenCalled()
	})

	it('reads the party id from @self, then uuid, else none', async () => {
		const contact = { id: 'c', fullName: 'C', emailAddresses: [' c@x.nl '] }
		resolveParty.mockResolvedValueOnce({ '@self': { id: 'self-1' } })
		expect((await initiatorChoice('contact', contact)).requester).toBe('self-1')
		resolveParty.mockResolvedValueOnce({ uuid: 'uuid-1' })
		expect((await initiatorChoice('contact', contact)).requester).toBe('uuid-1')
		resolveParty.mockResolvedValueOnce({})
		expect((await initiatorChoice('contact', contact)).requester).toBe('')
		expect(resolveParty).toHaveBeenLastCalledWith('c@x.nl')
	})

	it('asks nothing for a contact whose detail is not an address', async () => {
		const payload = await initiatorChoice('contact', { id: 'c', fullName: 'C', topAction: { title: 'Call' } })
		expect(resolveParty).not.toHaveBeenCalled()
		expect(payload).toEqual({ requester: '', initiatorType: 'contact', initiatorSourceId: 'c', initiatorDisplayName: 'C' })
	})

	it('asks for a company without a register id whose detail holds an @', async () => {
		resolveParty.mockResolvedValueOnce({ id: 'p-9' })
		const payload = await initiatorChoice('company', { tradeName: 'X', legalForm: 'a@b' })
		expect(resolveParty).toHaveBeenCalledWith('a@b')
		expect(payload.requester).toBe('p-9')
	})
})
