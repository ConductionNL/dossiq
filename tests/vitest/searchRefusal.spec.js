// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A refused search reads as a refusal, not as nothing found.
 *
 * 🔴 THE FAILURE THIS GUARDS IS INVISIBLE. OpenRegister refuses a malformed
 * term with a 400 so it is never evaluated as a literal, because a literal
 * returns zero rows and looks exactly like a search that matched nothing.
 * `fetchCollection()` then returns `[]` anyway, and the list renders its empty
 * state. A reader gets "no cases match" for a bracket they forgot to close.
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	readSearchRefusal,
	searchRefusalHeadline,
} from '../../src/utils/searchRefusal.js'

/**
 * The shape `parseResponseError()` builds for a 400.
 *
 * @param {string} message The refusal message openregister sent.
 * @return {object} The ApiError the object store would hold.
 */
function refusalError(message) {
	return {
		status: 400,
		message,
		details: message,
		isValidation: true,
		fields: null,
	}
}

describe('readSearchRefusal', () => {
	it('recovers the position from the message the platform sends', () => {
		const refusal = readSearchRefusal({
			error: refusalError('Unbalanced bracket at position 12.'),
			term: '(dakkapel AND NOT geweigerd',
		})

		expect(refusal.position).toBe(12)
		expect(refusal.message).toBe('Unbalanced bracket at position 12.')
	})

	it('prefers a position the payload carries over the one in the message', () => {
		const error = refusalError('Unbalanced bracket at position 12.')
		error.details = { error: 'Unbalanced bracket at position 12.', position: 4 }

		expect(readSearchRefusal({ error, term: '(dak' }).position).toBe(4)
	})

	it('splits the term so the surface can point at the character', () => {
		const refusal = readSearchRefusal({
			error: refusalError('Unexpected operator at position 5.'),
			term: 'dak AND',
		})

		expect([refusal.before, refusal.at, refusal.after]).toEqual([
			'dak ',
			'A',
			'ND',
		])
	})

	it('keeps the reason when the position cannot be read', () => {
		const refusal = readSearchRefusal({
			error: refusalError('The search term is malformed'),
			term: 'dakkapel AND',
		})

		expect(refusal.position).toBeNull()
		expect(refusal.message).toBe('The search term is malformed')
		expect(refusal.at).toBe('')
	})

	it('is not a refusal when nothing was typed', () => {
		expect(
			readSearchRefusal({
				error: refusalError('Unbalanced bracket at position 1.'),
				term: '  ',
			}),
		).toBeNull()
	})

	it('is not a refusal when there is no error', () => {
		expect(readSearchRefusal({ error: null, term: 'dakkapel' })).toBeNull()
		expect(readSearchRefusal()).toBeNull()
	})

	it('leaves a failure that is not a 400 alone', () => {
		expect(
			readSearchRefusal({
				error: {
					status: 500,
					message:
						'An unexpected server error occurred. Please try again.',
				},
				term: 'dakkapel',
			}),
		).toBeNull()
	})

	it('survives a position past the end of the term', () => {
		const refusal = readSearchRefusal({
			error: refusalError('Unexpected end of term at position 40.'),
			term: 'dakkapel AND',
		})

		expect(refusal.position).toBe(40)
		expect(refusal.before).toBe('dakkapel AND')
		expect(refusal.at).toBe('')
	})
})

describe('searchRefusalHeadline', () => {
	it('names the character the reader has to look at', () => {
		const refusal = readSearchRefusal({
			error: refusalError('Unbalanced bracket at position 12.'),
			term: '(dakkapel AND NOT geweigerd',
		})

		expect(
			searchRefusalHeadline(refusal, (app, text, vars) =>
				text.replace('{position}', vars.position),
			),
		).toBe('We could not read this search from character 12.')
	})

	it('says less when it knows less', () => {
		const refusal = readSearchRefusal({
			error: refusalError('The search term is malformed'),
			term: 'dakkapel AND',
		})

		expect(searchRefusalHeadline(refusal, (app, text) => text)).toBe(
			'We could not read this search.',
		)
	})

	it('says nothing when there is no refusal', () => {
		expect(searchRefusalHeadline(null, (app, text) => text)).toBe('')
	})
})
