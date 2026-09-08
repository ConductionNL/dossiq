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

	it('runs its sentence through the translate function it is given', () => {
		const message = publishRefusalMessage(
			{ response: { status: 403 } },
			(app, text) => `${app}:${text}`,
		)
		expect(message.startsWith('dossiq:')).toBe(true)
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

	it('translates the labels but never the ids', () => {
		const [first] = importStrategies((key) => `nl:${key}`)
		expect(first.id).toBe('skip')
		expect(first.label.startsWith('nl:')).toBe(true)
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
