/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the case-type gestures say when they are refused, and how a duplicate
 * finds the copy it just made.
 *
 * The three refusals are genuinely different and a single "Something went
 * wrong" would send an ordinary user hunting in their own data for a problem
 * only an administrator can solve. And `copiedCaseTypeId` guards the quiet
 * one: OpenRegister writes an id in either of two places depending on how the
 * row was serialised, and reading only one leaves a person on the original
 * after asking for a copy, with no error anywhere.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 * @spec openspec/specs/workflow-import-export/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	copiedCaseTypeId,
	findingsFrom,
	importStrategies,
	publishRefusalMessage,
} from '../../src/utils/caseTypePublish.js'

describe('publishRefusalMessage', () => {
	it('tells a signed-out person to sign in', () => {
		expect(publishRefusalMessage({ response: { status: 401 } })).toMatch(
			/Sign in/,
		)
	})

	it('tells a non-admin it is their account, not their data', () => {
		expect(publishRefusalMessage({ response: { status: 403 } })).toMatch(
			/account/,
		)
	})

	it('says a missing case type is gone', () => {
		expect(publishRefusalMessage({ response: { status: 404 } })).toMatch(
			/no longer exists/,
		)
	})

	it.each([[500], [502], [0]])('gives one generic sentence for a %s', (status) => {
		expect(publishRefusalMessage({ response: { status } })).toMatch(
			/administrator/,
		)
	})

	it('never repeats the server’s own message', () => {
		// The server's text carries paths and driver output.
		const message = publishRefusalMessage({
			response: { status: 500, data: { error: 'SQLSTATE at /var/www/x.php' } },
		})
		expect(message).not.toContain('/var/www')
		expect(message).not.toContain('SQLSTATE')
	})

	it('survives something that is not an axios error at all', () => {
		expect(publishRefusalMessage(undefined)).toBeTruthy()
		expect(publishRefusalMessage('boom')).toBeTruthy()
	})

	it('uses the sentences it is handed, already translated', () => {
		// They arrive translated rather than as keys through a callback: a
		// literal that lives in the helper and passes through a translate
		// function is invisible to the l10n extractor, so it would never reach
		// a translator and would render in English with every check green.
		const message = publishRefusalMessage(
			{ response: { status: 403 } },
			{ forbidden: 'Uw account mag dit niet.' },
		)
		expect(message).toBe('Uw account mag dit niet.')
	})

	it('falls back to a sentence rather than to undefined', () => {
		expect(publishRefusalMessage({ response: { status: 404 } }, {})).toMatch(
			/no longer exists/,
		)
	})
})

describe('findingsFrom', () => {
	it('reads the findings off a 422', () => {
		expect(
			findingsFrom({ response: { data: { findings: ['Give it a title.'] } } }),
		).toEqual(['Give it a title.'])
	})

	it('is empty when the response carries none', () => {
		expect(findingsFrom({ response: { status: 500 } })).toEqual([])
		expect(findingsFrom(null)).toEqual([])
	})

	it('drops anything in the list that is not a sentence', () => {
		expect(
			findingsFrom({ response: { data: { findings: ['Ok', 3, null] } } }),
		).toEqual(['Ok'])
	})
})

describe('importStrategies', () => {
	it('offers the three the endpoint accepts, and no others', () => {
		// The import endpoint refuses anything but these three by name.
		expect(importStrategies().map((s) => s.id)).toEqual([
			'skip',
			'merge',
			'overwrite',
		])
	})

	it('leads with the only one that cannot lose work', () => {
		expect(importStrategies()[0].id).toBe('skip')
	})

	it('uses the labels it is handed, but never their ids', () => {
		const [first] = importStrategies({ skip: 'Laat staan wat er al is' })
		expect(first.id).toBe('skip')
		expect(first.label).toBe('Laat staan wat er al is')
	})

	it('falls back to a label rather than to undefined', () => {
		for (const option of importStrategies()) {
			expect(option.label).toBeTruthy()
		}
	})
})

describe('copiedCaseTypeId', () => {
	it('reads a plain id', () => {
		expect(copiedCaseTypeId({ id: 'copy-1' })).toBe('copy-1')
	})

	it('reads an id out of the @self envelope', () => {
		// Reading only the plain shape leaves a person on the original after
		// asking for a copy, with no error anywhere.
		expect(copiedCaseTypeId({ '@self': { id: 'copy-2' } })).toBe('copy-2')
	})

	it('is empty when the answer carries no id at all', () => {
		expect(copiedCaseTypeId({})).toBe('')
		expect(copiedCaseTypeId(null)).toBe('')
	})
})
