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
	personDisplayName,
	personResult,
	companyResult,
	contactResult,
	initiatorProjection,
	searchContacts,
} from '../../src/services/initiatorSearch.js'

const axiosPost = vi.hoisted(() => vi.fn())

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
		expect(personDisplayName({ voornamen: 'Tina-Antïna', voorvoegsel: 'de', geslachtsnaam: 'Bruin' }))
			.toBe('Tina-Antïna de Bruin')
		expect(personDisplayName({ voornamen: 'Stephan', voorvoegsel: '', geslachtsnaam: 'Janssen' }))
			.toBe('Stephan Janssen')
		expect(personDisplayName(null)).toBe('')
	})

	it('shapes a brpPerson row into a unified person result', () => {
		const result = personResult({
			id: 'uuid-1',
			burgerservicenummer: '999990627',
			displayName: 'Stephan Janssen',
			geboorte: { datum: '1975-04-06' },
			naam: { voornamen: 'Stephan', geslachtsnaam: 'Janssen' },
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
			kvkNummer: '69599084',
			handelsnaam: 'Test EMZ Dagobert',
			rechtsvorm: 'Eenmanszaak',
		})
		expect(result.type).toBe('company')
		expect(result.sourceId).toBe('69599084')
		expect(result.displayName).toBe('Test EMZ Dagobert')
		expect(result.detail).toContain('KVK 69599084')
	})

	it('maps a picked result onto the case projection fields (one write path)', () => {
		expect(initiatorProjection({ type: 'company', sourceId: '69599084', displayName: 'Test EMZ Dagobert' }))
			.toEqual({
				initiatorType: 'company',
				initiatorSourceId: '69599084',
				initiatorDisplayName: 'Test EMZ Dagobert',
			})
		// No initiator picked -> no projection fields at all (case creatable without).
		expect(initiatorProjection(null)).toEqual({})
	})

	it('searches contacts via the core contactsmenu endpoint', async () => {
		axiosPost.mockResolvedValue({
			data: { contacts: [{ id: 'c1', fullName: 'Anna de Wit', emailAddresses: ['anna@example.org'] }] },
		})
		const results = await searchContacts('anna')
		expect(axiosPost).toHaveBeenCalledWith('/index.php/contactsmenu/contacts', { filter: 'anna' })
		expect(results).toHaveLength(1)
		expect(results[0]).toMatchObject({ type: 'contact', displayName: 'Anna de Wit' })
	})

	it('degrades to an empty list when the contacts source is unavailable', async () => {
		axiosPost.mockRejectedValue(new Error('404 — Contacts absent'))
		await expect(searchContacts('anna')).resolves.toEqual([])
	})

	it('shapes a contactsmenu entry into a unified contact result', () => {
		const result = contactResult({ id: 'uid-9', fullName: 'Anna de Wit', emailAddresses: ['anna@example.org'] })
		expect(result).toMatchObject({
			type: 'contact',
			sourceId: 'uid-9',
			displayName: 'Anna de Wit',
			detail: 'anna@example.org',
		})
	})
})
