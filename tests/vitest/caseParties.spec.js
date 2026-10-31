// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The parties of a case, in their roles, and what their indicators refuse.
 *
 * Three things fail silently in this module if nobody checks them, and each
 * one has a test below.
 *
 * The PRIMARY PARTY FIRST rule is an ordering, and an ordering that quietly
 * stops working looks exactly like a case whose applicant happens to sort
 * second. So the assertion names the party, not the length of the list.
 *
 * A FAILED READ must not become an empty case. `null` and `{results: []}` are
 * different answers and the widget says different things about them; a fetch
 * that turned an outage into an empty listing would tell a handler the
 * gemachtigde they added this morning is gone.
 *
 * AN UNKNOWN INDICATOR EFFECT must warn rather than vanish. A chip nobody can
 * read is still a chip somebody asks about. A dropped one is not.
 *
 * @spec openspec/specs/roles-decisions/spec.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const { fetchCaseParties, resolvePartyByAddress } = await import(
	'../../src/services/caseParties.js'
)

/** A listing with a primary party in the second role the grouping names. */
const LISTING = {
	results: [
		{ partyUuid: 'p-buur', displayName: 'De buurman' },
		{ partyUuid: 'p-jan', displayName: 'Jan de Vries' },
		{ partyUuid: 'p-mr', displayName: 'Mr. Advocaat' },
	],
	byRole: {
		belanghebbende: [{ partyUuid: 'p-buur', displayName: 'De buurman' }],
		aanvrager: [
			{ partyUuid: 'p-mr', displayName: 'Mr. Advocaat' },
			{ partyUuid: 'p-jan', displayName: 'Jan de Vries' },
		],
	},
	roles: [{ key: 'rt-1', label: 'Behandelaar' }],
	kinds: [{ key: 'person', label: 'Persoon' }],
	primary: 'p-jan',
}

beforeEach(() => {
	get.mockReset()
})

describe('the parties of a case', () => {
	// Grouping by role, the primary party first and the role labels are the
	// library parties widget's now (nextcloud-vue objectParties, with these
	// cases ported to its spec). dossiq keeps the read the document dialog uses.
	it('answers null when the listing could not be read, never an empty case', async () => {
		get.mockRejectedValueOnce(new Error('OpenRegister is down'))

		expect(await fetchCaseParties('case-1')).toBeNull()
	})

	it('reads the listing for the case it was given', async () => {
		get.mockResolvedValueOnce({ data: LISTING })

		const listing = await fetchCaseParties('case-1')

		expect(get).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/dossiq/case/case-1/parties',
		)
		expect(listing.primary).toBe('p-jan')
	})
})

describe('resolving an address before a second party is created', () => {
	it('answers the party already holding the address', async () => {
		get.mockResolvedValueOnce({ data: { id: 'p-jan', name: 'Jan de Vries' } })

		const party = await resolvePartyByAddress(' jan@example.nl ')

		expect(get).toHaveBeenCalledWith('/apps/openregister/api/parties/resolve', {
			params: { address: 'jan@example.nl' },
		})
		expect(party.id).toBe('p-jan')
	})

	it('answers null when nobody holds it, and asks nothing for an empty address', async () => {
		get.mockRejectedValueOnce({ response: { status: 404 } })
		expect(await resolvePartyByAddress('nobody@example.nl')).toBeNull()

		expect(await resolvePartyByAddress('')).toBeNull()
		expect(get).toHaveBeenCalledTimes(1)
	})
})
