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
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const {
	fetchCaseParties,
	indicatorVerdict,
	indicatorsOf,
	partiesInOrder,
	publicationRefusal,
	resolvePartyByAddress,
	roleLabel,
	rolesInOrder,
} = await import('../../src/services/caseParties.js')

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
	it('puts the primary party first, and its role group first', () => {
		const groups = rolesInOrder(LISTING)

		expect(groups[0].key).toBe('aanvrager')
		expect(groups[0].parties[0].displayName).toBe('Jan de Vries')
		expect(groups[1].key).toBe('belanghebbende')
	})

	it('leaves the order alone when no party is primary', () => {
		const groups = rolesInOrder({ ...LISTING, primary: null })

		expect(groups[0].key).toBe('belanghebbende')
		expect(groups[0].parties[0].displayName).toBe('De buurman')
		expect(partiesInOrder(LISTING.byRole.aanvrager, null)[0].partyUuid).toBe(
			'p-mr',
		)
	})

	it('labels a generic role in the reader language and a role type from the schema', () => {
		expect(roleLabel('gemachtigde', LISTING.roles)).toBe(
			'Authorised representative',
		)
		expect(roleLabel('rt-1', LISTING.roles)).toBe('Behandelaar')
		expect(roleLabel('rt-9', LISTING.roles)).toBe('rt-9')
	})

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

describe('what an indicator refuses', () => {
	it('names the act for each effect, and warns on one it does not know', () => {
		expect(indicatorVerdict({ effect: 'refuse-publication' }).severity).toBe(
			'error',
		)
		expect(indicatorVerdict({ effect: 'refuse-send' }).severity).toBe('error')
		// An indicator with an effect this version does not know warns. It
		// never vanishes.
		expect(indicatorVerdict({ effect: 'embargo', label: 'Embargo' })).toEqual({
			effect: 'embargo',
			severity: 'warning',
			label: 'Embargo',
			verdict: 'Read this before acting on the case',
		})
	})

	it('finds the indicator that refuses publication, and stamps its party', () => {
		const indicators = indicatorsOf([
			{ id: 'p-jan', name: 'Jan de Vries', indicators: [{ key: 'x', effect: 'warn' }] },
			{
				id: 'p-buur',
				name: 'De buurman',
				indicators: [{ key: 'geheim', label: 'Geheimhouding', effect: 'refuse-publication' }],
			},
		])

		const refusal = publicationRefusal(indicators)

		expect(refusal.label).toBe('Geheimhouding')
		expect(refusal.partyName).toBe('De buurman')
		expect(publicationRefusal([])).toBeNull()
	})
})

describe('resolving an address before a second party is created', () => {
	it('answers the party already holding the address', async () => {
		get.mockResolvedValueOnce({ data: { id: 'p-jan', name: 'Jan de Vries' } })

		const party = await resolvePartyByAddress(' jan@example.nl ')

		expect(get).toHaveBeenCalledWith(
			'/apps/openregister/api/parties/resolve',
			{ params: { address: 'jan@example.nl' } },
		)
		expect(party.id).toBe('p-jan')
	})

	it('answers null when nobody holds it, and asks nothing for an empty address', async () => {
		get.mockRejectedValueOnce({ response: { status: 404 } })
		expect(await resolvePartyByAddress('nobody@example.nl')).toBeNull()

		expect(await resolvePartyByAddress('')).toBeNull()
		expect(get).toHaveBeenCalledTimes(1)
	})
})
