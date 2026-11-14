/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the initiator cross-source search helpers
 * (brp-kvk-register-sets): unified result shaping per source, the case
 * projection mapping (one write path — display projection of the ADR-048
 * requester reference), and the graceful contacts degradation.
 *
 * @spec openspec/specs/initiator-selection/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	companyResult,
	contactResult,
	initiatorChoice,
	initiatorProjection,
	personDisplayName,
	personResult,
	requesterPayload,
} from '../../src/services/initiatorSearch.js'

const axiosPost = vi.hoisted(() => vi.fn())
const resolveParty = vi.hoisted(() => vi.fn())

vi.mock('../../src/services/caseParties.js', () => ({
	resolvePartyByAddress: (...args) => resolveParty(...args),
}))

vi.mock('@nextcloud/axios', () => ({
	default: { post: (...args) => axiosPost(...args) },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (u) => `/index.php${u}`,
}))

describe('initiatorSearch (brp-kvk-register-sets)', () => {
	beforeEach(() => {
		axiosPost.mockReset()
	})

	it('composes a person display name incl voorvoegsel', () => {
		expect(
			personDisplayName({
				given_names: 'Tina-Antïna',
				name_prefix: 'de',
				surname: 'Bruin',
			}),
		).toBe('Tina-Antïna de Bruin')
		expect(
			personDisplayName({
				given_names: 'Stephan',
				name_prefix: '',
				surname: 'Janssen',
			}),
		).toBe('Stephan Janssen')
		expect(personDisplayName(null)).toBe('')
	})

	it('shapes a brpPerson row into a unified person result', () => {
		const result = personResult({
			id: 'uuid-1',
			citizen_service_number: '999990627',
			displayName: 'Stephan Janssen',
			birth: { date: '1975-04-06' },
			name: { given_names: 'Stephan', surname: 'Janssen' },
		})
		expect(result.type).toBe('person')
		expect(result.sourceId).toBe('999990627')
		expect(result.displayName).toBe('Stephan Janssen')
		expect(result.detail).toContain('BSN 999990627')
		expect(result.detail).toContain('1975-04-06')
	})

	it('shapes a kvkCompany row into a unified company result', () => {
		const result = companyResult({
			id: 'uuid-2',
			kvkNumber: '69599084',
			trade_name: 'Test EMZ Dagobert',
			legalForm: 'Eenmanszaak',
		})
		expect(result.type).toBe('company')
		expect(result.sourceId).toBe('69599084')
		expect(result.displayName).toBe('Test EMZ Dagobert')
		expect(result.detail).toContain('KVK 69599084')
	})

	it('maps a picked result onto the case projection fields (one write path)', () => {
		expect(
			initiatorProjection({
				type: 'company',
				sourceId: '69599084',
				displayName: 'Test EMZ Dagobert',
			}),
		).toEqual({
			initiatorType: 'company',
			initiatorSourceId: '69599084',
			initiatorDisplayName: 'Test EMZ Dagobert',
		})
		// No initiator picked -> no projection fields at all (case creatable without).
		expect(initiatorProjection(null)).toEqual({})
	})

	it('reads the schema key casing the seeded rows actually carry', () => {
		// The seeds are camelCase; these helpers were written snake_case and
		// the mismatch never showed, because the register search could not
		// run at all until brpPerson/kvkCompany became registered types.
		expect(personResult({ citizenServiceNumber: '999990792' }).sourceId).toBe(
			'999990792',
		)
		expect(
			companyResult({ kvkNumber: '69599084', tradeName: 'Test EMZ Dagobert' })
				.displayName,
		).toBe('Test EMZ Dagobert')
		expect(
			personDisplayName({
				givenNames: 'Jan',
				namePrefix: 'de',
				surname: 'Cuykelaer',
			}),
		).toBe('Jan de Cuykelaer')
	})

	it('carries the secrecy indication onto the result', () => {
		expect(personResult({ indicatieGeheim: true }).protected).toBe(true)
		expect(personResult({ citizenServiceNumber: '1' }).protected).toBe(false)
	})

	it('writes the uuid and the projection in one payload', () => {
		expect(
			requesterPayload({
				type: 'person',
				sourceId: '999990627',
				displayName: 'Stephan Janssen',
				objectId: 'uuid-1',
			}),
		).toEqual({
			requester: 'uuid-1',
			initiatorType: 'person',
			initiatorSourceId: '999990627',
			initiatorDisplayName: 'Stephan Janssen',
		})
		// A contact has no register row, so the canonical reference stays
		// empty and only the projection is written.
		expect(
			requesterPayload({
				type: 'contact',
				sourceId: 'uid-9',
				displayName: 'Anna de Wit',
				objectId: null,
			}).requester,
		).toBe('')
		expect(requesterPayload(null)).toEqual({})
	})

	it('shapes a contactsmenu entry into a unified contact result', () => {
		const result = contactResult({
			id: 'uid-9',
			fullName: 'Anna de Wit',
			emailAddresses: ['anna@example.org'],
		})
		expect(result).toMatchObject({
			type: 'contact',
			sourceId: 'uid-9',
			displayName: 'Anna de Wit',
			detail: 'anna@example.org',
		})
	})
})

describe('initiatorChoice: no second party for an address somebody holds', () => {
	beforeEach(() => {
		resolveParty.mockReset()
	})

	it('points a contact at the party that already holds its address', async () => {
		resolveParty.mockResolvedValue({ id: 'uuid-party-1', name: 'Jan de Vries' })
		const payload = await initiatorChoice('contact', { id: 'contact-uid-1', fullName: 'Jan de Vries', emailAddresses: ['jan@example.nl'] })
		expect(resolveParty).toHaveBeenCalledWith('jan@example.nl')
		expect(payload).toEqual({ requester: 'uuid-party-1', initiatorType: 'contact', initiatorSourceId: 'contact-uid-1', initiatorDisplayName: 'Jan de Vries' })
	})

	it('records a contact unchanged when no party holds the address', async () => {
		resolveParty.mockResolvedValue(null)
		const payload = await initiatorChoice('contact', { id: 'contact-uid-1', fullName: 'Jan de Vries', emailAddresses: ['jan@example.nl'] })
		expect(payload.requester).toBe('')
	})

	it('takes a register row as it is, without asking', async () => {
		const payload = await initiatorChoice('person', { id: 'uuid-person-1', citizenServiceNumber: '999990627', displayName: 'Stephan Janssen' })
		expect(resolveParty).not.toHaveBeenCalled()
		expect(payload).toEqual({ requester: 'uuid-person-1', initiatorType: 'person', initiatorSourceId: '999990627', initiatorDisplayName: 'Stephan Janssen' })
	})

	it('answers nothing for a kind it does not know', async () => {
		expect(await initiatorChoice('robot', { id: 'x' })).toEqual({})
	})
})

