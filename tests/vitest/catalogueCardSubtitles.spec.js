/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqCatalogus draws a line under the Objecttypen title, on what is now the
 * Object types page. A dashboard card draws its definition's `subtitle`; a
 * `description` drew nothing.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const widgets = read('src/manifest.d/64-catalogue.json').pages[0].config.widgets
const nl = read('l10n/nl.json').translations
const widget = (id) => widgets.find((entry) => entry.id === id)

describe('the catalogue card subtitles (DqCatalogus)', () => {
	it.each([
		['catalogue-object-types', 'Objecten die u aan zaken koppelt; eigenschappen beheert u hier'],
	])('%s draws its line as a subtitle', (id, text) => {
		expect(nl[widget(id).subtitle]).toBe(text)
		expect(widget(id).description).toBeUndefined()
	})
})
