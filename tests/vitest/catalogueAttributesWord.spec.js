/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The attributes entry under Advanced (the catalogue's former folder) reads
 * "Attributen", while the case type blueprint (DqZaaktypeStatussen) keeps
 * "Kenmerken" for the fields a type offers. The entry reads its own key,
 * English "Attributes" either way.
 *
 * @spec openspec/specs/catalogue/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const menu = read('src/manifest.json').menu
const en = read('l10n/en.json').translations
const nl = read('l10n/nl.json').translations

describe('the attributes entry under Advanced', () => {
	const folder = menu.find((entry) => entry.id === 'PropertyDefinitionsMenu')

	it('reads Attributen in Dutch and Attributes in English', () => {
		expect(nl[folder.label]).toBe('Attributen')
		expect(en[folder.label]).toBe('Attributes')
	})

	it('leaves the blueprint\'s Kenmerken alone', () => {
		expect(nl.Attributes).toBe('Kenmerken')
	})
})
