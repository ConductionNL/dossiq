/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the l10n merge driver in tools/merge-l10n.js.
 *
 * The driver only earns its place if it beats git's ordinary merge on the one
 * case that actually costs lanes time: two branches each adding a translation
 * key. So the first test states that case exactly, and the rest pin the
 * behaviours that could quietly go wrong instead of failing — a dropped
 * translation, a manufactured conflict, or output that no longer matches what
 * `npm run l10n:build` writes.
 *
 * Two of these guard specific mistakes already made while building it:
 *
 *   - Plural entries hold an ARRAY of forms, so comparing values with `===`
 *     reports every plural key as changed on both sides and manufactures a
 *     conflict on every merge. `mergeValue` compares by content.
 *   - A merged `.js` catalogue has to be byte-identical to a rebuilt one, or
 *     `npm run check:l10n-js` reports the merge as stale output.
 *
 * @spec tests/l10n/check-l10n.js
 */

import { createRequire } from 'node:module'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const { renderJs } = require('../../scripts/build-l10n-js.js')
const {
	mergeCatalogues,
	mergeValue,
	parseCatalogue,
	sortKeys,
} = require('../../tools/merge-l10n.js')

/**
 * Build a catalogue document the way l10n/<locale>.json is written on disk.
 *
 * @param {object} translations - key -> translation
 * @return {string} the file body
 */
function json(translations) {
	return JSON.stringify({ translations }, null, 4) + '\n'
}

describe('the merge driver on the case that costs lanes time', () => {
	it('keeps a key each side added, where a line merge would conflict', () => {
		const base = json({ Alpha: 'Alpha', Zulu: 'Zulu' })
		const ours = json({ Alpha: 'Alpha', Zulu: 'Zulu', 'Zzz ours': 'Zzz ours' })
		const theirs = json({
			Alpha: 'Alpha',
			Zulu: 'Zulu',
			'Zzz theirs': 'Zzz theirs',
		})

		const result = mergeCatalogues(base, ours, theirs)

		expect(result.error).toBe(null)
		expect(result.conflicts).toEqual([])
		const merged = JSON.parse(result.text).translations
		expect(Object.keys(merged)).toEqual([
			'Alpha',
			'Zulu',
			'Zzz ours',
			'Zzz theirs',
		])
	})

	it('writes output that parses, which merge=union would not', () => {
		const result = mergeCatalogues(
			json({ A: 'A' }),
			json({ A: 'A', B: 'B' }),
			json({ A: 'A', C: 'C' }),
		)
		expect(() => JSON.parse(result.text)).not.toThrow()
		expect(result.text.endsWith('}\n')).toBe(true)
	})

	it('writes the merged catalogue in canonical order', () => {
		const result = mergeCatalogues(
			json({ middle: 'middle' }),
			json({ middle: 'middle', Zebra: 'Zebra' }),
			json({ middle: 'middle', apple: 'apple' }),
		)
		expect(Object.keys(JSON.parse(result.text).translations)).toEqual([
			'apple',
			'middle',
			'Zebra',
		])
	})
})

describe('what the driver does with each kind of change', () => {
	it('takes the one side that retranslated a key', () => {
		const result = mergeCatalogues(
			json({ Save: 'Save' }),
			json({ Save: 'Save' }),
			json({ Save: 'Save changes' }),
		)
		expect(result.conflicts).toEqual([])
		expect(JSON.parse(result.text).translations.Save).toBe('Save changes')
	})

	it('drops a key one side deleted and the other left alone', () => {
		const result = mergeCatalogues(
			json({ Keep: 'Keep', Drop: 'Drop' }),
			json({ Keep: 'Keep', Drop: 'Drop' }),
			json({ Keep: 'Keep' }),
		)
		expect(result.conflicts).toEqual([])
		expect(Object.keys(JSON.parse(result.text).translations)).toEqual(['Keep'])
	})

	it('reports a conflict, with markers, when both sides retranslate one key differently', () => {
		const result = mergeCatalogues(
			json({ Middle: 'Middle' }),
			json({ Middle: 'Our wording' }),
			json({ Middle: 'Their wording' }),
		)

		expect(result.conflicts).toHaveLength(1)
		expect(result.conflicts[0].key).toBe('Middle')
		expect(result.text).toContain('<<<<<<< ours')
		expect(result.text).toContain('"Middle": "Our wording"')
		expect(result.text).toContain('"Middle": "Their wording"')
		expect(result.text).toContain('>>>>>>> theirs')
		// Deliberately unparseable: a real conflict must not be staged unnoticed.
		expect(() => JSON.parse(result.text)).toThrow()
	})

	it('keeps the same edit made on both sides, without calling it a conflict', () => {
		const result = mergeCatalogues(
			json({ Save: 'Save' }),
			json({ Save: 'Save changes' }),
			json({ Save: 'Save changes' }),
		)
		expect(result.conflicts).toEqual([])
		expect(JSON.parse(result.text).translations.Save).toBe('Save changes')
	})
})

describe('plural entries, whose value is an array', () => {
	const plural = {
		'_{count} day_::_{count} days_': ['{count} day', '{count} days'],
	}

	it('does not manufacture a conflict when neither side touched them', () => {
		// `===` on two equal arrays is false, so an identity test would have
		// flagged this key on every merge.
		const result = mergeCatalogues(
			json(plural),
			json({ ...plural, A: 'A' }),
			json({ ...plural, B: 'B' }),
		)
		expect(result.conflicts).toEqual([])
		expect(
			JSON.parse(result.text).translations['_{count} day_::_{count} days_'],
		).toEqual(['{count} day', '{count} days'])
	})

	it('compares array values by content', () => {
		expect(mergeValue(['a', 'b'], ['a', 'b'], ['a', 'b']).conflict).toBe(false)
		expect(mergeValue(['a', 'b'], ['a', 'c'], ['a', 'd']).conflict).toBe(true)
	})

	it('writes an array entry the way JSON.stringify(doc, null, 4) does', () => {
		const result = mergeCatalogues(json(plural), json(plural), json(plural))
		expect(result.text).toBe(json(plural))
	})
})

describe('the generated OC.L10N.register catalogue', () => {
	const js = (translations) =>
		renderJs('dossiq', translations, 'nplurals=2; plural=(n != 1);')

	it('merges both sides and comes out byte-identical to a rebuild', () => {
		const base = js({ Alpha: 'Alpha' })
		const ours = js({ Alpha: 'Alpha', 'Zzz ours': 'Zzz ours' })
		const theirs = js({ Alpha: 'Alpha', 'Zzz theirs': 'Zzz theirs' })

		const result = mergeCatalogues(base, ours, theirs)

		expect(result.error).toBe(null)
		expect(result.conflicts).toEqual([])
		expect(result.text).toBe(
			js({
				Alpha: 'Alpha',
				'Zzz ours': 'Zzz ours',
				'Zzz theirs': 'Zzz theirs',
			}),
		)
	})

	it('reads back the id, the plural form and an array value', () => {
		const parsed = parseCatalogue(js({ '_one_::_many_': ['one', 'many'] }))
		expect(parsed.kind).toBe('js')
		expect(parsed.id).toBe('dossiq')
		expect(parsed.pluralForm).toBe('nplurals=2; plural=(n != 1);')
		expect(parsed.translations['_one_::_many_']).toEqual(['one', 'many'])
	})
})

describe('when the driver cannot be sure', () => {
	it('leaves our version alone and says so if a side does not parse', () => {
		const ours = json({ A: 'A' })
		const result = mergeCatalogues(json({}), ours, '{ not json')
		expect(result.error).toMatch(/not a readable l10n catalogue/)
		expect(result.text).toBe(ours)
	})

	it('treats an empty ancestor as a catalogue both sides created', () => {
		const result = mergeCatalogues('', json({ A: 'A' }), json({ B: 'B' }))
		expect(result.error).toBe(null)
		expect(Object.keys(JSON.parse(result.text).translations)).toEqual(['A', 'B'])
	})
})

describe('the canonical order itself', () => {
	it('sorts case-insensitively so related keys stay together', () => {
		expect(sortKeys(['Zebra', 'apple', 'Banana'])).toEqual([
			'apple',
			'Banana',
			'Zebra',
		])
	})

	it('still gives two keys differing only in case one defined order', () => {
		expect(sortKeys(['case', 'Case'])).toEqual(['Case', 'case'])
		expect(sortKeys(['Case', 'case'])).toEqual(['Case', 'case'])
	})

	it("is idempotent, so a second writer never reorders the first one's output", () => {
		const keys = ['Zoom to fit', 'Add a case', '{count} days', '%n working day']
		expect(sortKeys(sortKeys(keys))).toEqual(sortKeys(keys))
	})
})
