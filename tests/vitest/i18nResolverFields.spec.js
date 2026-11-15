// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Resolving one field of an OpenRegister object to the text a template shows.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-procest-app-scaffold/tasks.md
 */
import { describe, expect, it } from 'vitest'
import { resolveField, resolveText } from '../../src/utils/i18nResolver.js'

describe('resolving a field of an object', () => {
	it('answers empty for something that is not an object', () => {
		const empty = { text: '', lang: null, isFallback: false }

		expect(resolveField(null, 'title')).toEqual(empty)
		expect(resolveField(undefined, 'title')).toEqual(empty)
		expect(resolveField('Bezwaar', 'title')).toEqual(empty)
	})

	it('passes a plain string field through', () => {
		expect(resolveField({ title: 'Bezwaar' }, 'title')).toEqual({
			text: 'Bezwaar',
			lang: null,
			isFallback: false,
		})
	})

	it('picks the requested language of a language-tagged field', () => {
		const obj = { title: { nl: 'Bezwaar', en: 'Objection' } }

		expect(resolveField(obj, 'title', 'en').text).toBe('Objection')
		expect(resolveField(obj, 'title', 'nl').text).toBe('Bezwaar')
	})

	it('answers empty for a field the object does not carry', () => {
		expect(resolveField({ title: 'Bezwaar' }, 'description').text).toBe('')
	})
})

describe('resolving a field to its text', () => {
	it('answers only the text', () => {
		expect(resolveText({ title: { nl: 'Bezwaar', en: 'Objection' } }, 'title', 'en')).toBe('Objection')
		expect(resolveText({ title: 'Bezwaar' }, 'title')).toBe('Bezwaar')
		expect(resolveText(null, 'title')).toBe('')
	})
})
