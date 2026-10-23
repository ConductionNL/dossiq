/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqCatalogus names the attributes folder "Attributen", while the case type
 * blueprint (DqZaaktypeStatussen) keeps "Kenmerken" for the fields a type
 * offers. The catalogue reads its own key, English "Attributes" either way.
 *
 * @spec openspec/changes/catalogue-attributes-word/specs/catalogue/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const catalogue = read('src/manifest.d/64-catalogue.json').pages[0].config
const items = catalogue.widgets.find((widget) => widget.id === 'catalogue-folders').content.items
const en = read('l10n/en.json').translations
const nl = read('l10n/nl.json').translations

describe('the catalogue\'s attributes folder (DqCatalogus)', () => {
	const folder = items.find((entry) => entry.url === '/index.php/apps/dossiq/settings/attributes')

	it('reads Attributen in Dutch and Attributes in English', () => {
		expect(nl[folder.label]).toBe('Attributen')
		expect(en[folder.label]).toBe('Attributes')
	})

	it('leaves the blueprint\'s Kenmerken alone', () => {
		expect(nl.Attributes).toBe('Kenmerken')
	})
})
