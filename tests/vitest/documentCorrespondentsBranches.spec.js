// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every branch of the correspondent picker's readers: the party identifier,
 * the options, the stored selection and the identifiers a value stores.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	identifiersOf,
	partyIdentifier,
	partyOptions,
	selectedOptions,
} from '../../src/services/documentCorrespondents.js'

describe('partyIdentifier', () => {
	it.each([
		[null, ''],
		['p-1', ''],
		[{ partyUuid: ' u-1 ', contactUid: 'c-1' }, 'u-1'],
		[{ partyUuid: '   ', contactUid: ' c-1 ' }, 'c-1'],
		[{ partyUuid: 5 }, '5'],
		[{}, ''],
	])('reads %j as %j', (party, id) => {
		expect(partyIdentifier(party)).toBe(id)
	})
})

describe('partyOptions', () => {
	it('labels by name, then contact uid, then id, and trims the email', () => {
		expect(partyOptions({
			results: [
				{ partyUuid: 'u-1', displayName: ' Ann ', email: ' a@x.nl ' },
				{ partyUuid: 'u-2', contactUid: 'c-2' },
				{ partyUuid: 'u-3', displayName: '   ' },
				{ partyUuid: 'u-4' },
				{ displayName: 'nobody' },
				{ partyUuid: 'u-1', displayName: 'Ann again' },
			],
		})).toEqual([
			{ id: 'u-1', label: 'Ann', email: 'a@x.nl' },
			{ id: 'u-2', label: 'c-2', email: '' },
			{ id: 'u-3', label: 'u-3', email: '' },
			{ id: 'u-4', label: 'u-4', email: '' },
		])
	})

	it('reads a listing without a results list as empty', () => {
		expect(partyOptions({ results: {} })).toEqual([])
		expect(partyOptions(undefined)).toEqual([])
	})
})

describe('selectedOptions', () => {
	const options = [{ id: 'u-1', label: 'Ann', email: 'a@x.nl' }]

	it('matches, keeps unknowns, drops blanks and repeats, in stored order', () => {
		expect(selectedOptions(['x-9', ' u-1 ', '', null, 'u-1', 'x-9'], options)).toEqual([
			{ id: 'x-9', label: 'x-9', email: '' },
			options[0],
		])
		expect(selectedOptions('u-1', options)).toEqual([options[0]])
		expect(selectedOptions(7, options)).toEqual([{ id: '7', label: '7', email: '' }])
		expect(selectedOptions(undefined, options)).toEqual([])
	})
})

describe('identifiersOf', () => {
	it('reads strings, numbers, option objects and party links', () => {
		expect(identifiersOf([
			' a ',
			3,
			{ id: 'o-1' },
			{ partyUuid: 'u-1' },
			{ contactUid: 'c-1' },
			{ id: '', partyUuid: 'u-2' },
			{},
			null,
			'a',
			0,
		])).toEqual(['a', '3', 'o-1', 'u-1', 'c-1', 'u-2', '0'])
	})

	it('reads a single value and an empty one', () => {
		expect(identifiersOf({ id: ' o-2 ' })).toEqual(['o-2'])
		expect(identifiersOf(null)).toEqual([])
		expect(identifiersOf('')).toEqual([])
	})
})
