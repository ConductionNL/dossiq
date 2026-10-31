/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An optional field is marked with one wording.
 *
 * Rehearsal R3: the Woo-besluit dialog said "(optioneel)" where every form
 * the library draws says "(niet verplicht)". The Dutch catalogue follows the
 * library's word.
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))

describe('the wording of an optional field', () => {
	it("is the library's, everywhere in the Dutch catalogue", () => {
		const library = read(
			path.join(
				ROOT,
				'node_modules',
				'@conduction',
				'nextcloud-vue',
				'l10n',
				'nl.json',
			),
		)
		expect(library.translations.optional).toBe('niet verplicht')

		// A select placeholder the board draws word for word: DqUwWachtrij labels the
		// field "(niet verplicht)" and its empty select reads "Kies een sjabloon (optioneel)".
		const boardPlaceholders = ['Choose a template (optional)']
		const nl = read(path.join(ROOT, 'l10n', 'nl.json')).translations
		const off = Object.entries(nl).filter(([key, value]) =>
			/\(optioneel\)/i.test(value) && !boardPlaceholders.includes(key),
		)
		expect(off).toEqual([])
	})
})
