// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The rules the correspondent picker draws from.
 *
 * THE POINT OF THIS FILE IS THAT A CORRESPONDENT IS A PARTY. Every assertion
 * below is about an identifier surviving the round trip from the parties
 * listing, through the picker's option objects, back to the identifier the
 * document stores. A helper that quietly handed back a display name would
 * draw exactly the same screen and store a string nothing can filter on.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	allowedFor,
	identifiersOf,
	partyIdentifier,
	partyOptions,
	selectedOptions,
} from '../../src/services/documentCorrespondents.js'

/** The listing shape openregister#3761 answers for a case. */
const LISTING = {
	primary: 'party-jan',
	results: [
		{
			partyUuid: 'party-jan',
			displayName: 'Jan Jansen',
			email: 'jan@example.org',
			role: 'aanvrager',
		},
		{
			partyUuid: 'party-jan',
			displayName: 'Jan Jansen',
			email: 'jan@example.org',
			role: 'afzender',
		},
		{
			partyUuid: 'party-council',
			displayName: 'Gemeente Utrecht',
			role: 'geadresseerde',
		},
		{
			contactUid: 'uid-ouder',
			displayName: 'Ouder Link',
			role: 'belanghebbende',
		},
	],
}

describe('the picker offers the parties of the case', () => {
	it('offers a party in two roles exactly once', () => {
		const options = partyOptions(LISTING)

		expect(options.map((option) => option.id)).toEqual([
			'party-jan',
			'party-council',
			'uid-ouder',
		])
	})

	it('offers a link written before the party model under its contact uid', () => {
		expect(partyIdentifier({ contactUid: 'uid-ouder' })).toBe('uid-ouder')
		expect(
			partyIdentifier({ partyUuid: 'party-jan', contactUid: 'uid-x' }),
		).toBe('party-jan')
		expect(partyIdentifier(null)).toBe('')
	})

	it('offers nothing when the listing could not be read', () => {
		// 🔴 null is "we could not ask", and it must not become "nobody is on
		// this case": a picker offering nothing looks the same either way, so
		// the widget beside it is the one that says which happened.
		expect(partyOptions(null)).toEqual([])
		expect(partyOptions({})).toEqual([])
	})
})

describe('the picker stores identifiers and never names', () => {
	it('reads the identifier out of the option object a picker hands back', () => {
		expect(
			identifiersOf([
				{ id: 'party-jan', label: 'Jan Jansen' },
				{ id: 'party-council', label: 'Gemeente Utrecht' },
			]),
		).toEqual(['party-jan', 'party-council'])
	})

	it('reads a single picker, a bare string and an empty value', () => {
		expect(identifiersOf({ id: 'party-jan' })).toEqual(['party-jan'])
		expect(identifiersOf('party-jan')).toEqual(['party-jan'])
		expect(identifiersOf(null)).toEqual([])
		expect(identifiersOf([null, '', '  '])).toEqual([])
	})

	it('stores the same party once however often it was picked', () => {
		expect(
			identifiersOf([{ id: 'party-jan' }, 'party-jan', { id: 'party-jan' }]),
		).toEqual(['party-jan'])
	})

	it('shows a stored party the case no longer knows rather than dropping it', () => {
		const options = partyOptions(LISTING)
		const chosen = selectedOptions(['party-council', 'party-gone'], options)

		expect(chosen.map((option) => option.label)).toEqual([
			'Gemeente Utrecht',
			'party-gone',
		])
	})
})

describe('the direction narrows what the dialog offers', () => {
	it('offers addressees on an outgoing document and a sender on an incoming one', () => {
		expect(allowedFor('outgoing')).toEqual({ sender: false, recipients: true })
		expect(allowedFor('incoming')).toEqual({ sender: true, recipients: false })
	})

	it('offers both on an internal document and on a direction it cannot read', () => {
		expect(allowedFor('internal')).toEqual({ sender: true, recipients: true })
		expect(allowedFor('')).toEqual({ sender: true, recipients: true })
		expect(allowedFor(undefined)).toEqual({ sender: true, recipients: true })
	})
})

describe("a party's own documents", () => {
	// Counting what a party sent and what it received is the library parties
	// widget's now (nextcloud-vue objectParties.documentsOfParty, with these
	// cases ported to its spec). The fields it reads are the ones the picker
	// above stores.
	it('names the fields the Roles section counts on', () => {
		const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, '../../src/manifest.json'), 'utf8'))
		const roles = manifest.pages
			.find((page) => page.id === 'CaseDetail')
			.config.widgets.find((widget) => widget.id === 'case-people-panel')
			.content.sections.map((section) => section.widget)
			.find((widget) => widget.id === 'case-party-roles')
		// The library reads `sender` and `recipients` unless told otherwise.
		expect(roles.content.correspondence.senderField).toBeUndefined()
		expect(roles.content.correspondence.recipientsField).toBeUndefined()
	})
})
