/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeVelden: the Fields card says what it holds under its title, and
 * the field rights card reads "Wie wat ziet en invult, per ... en status"
 * with its line under the title.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { applyPageOverlay } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const full = read('src/manifest.json').pages.find((page) => page.id === 'CaseTypeDetail')
const overlay = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseTypeDetail')
const widgets = applyPageOverlay(full, overlay).config.widgets
const widget = (id) => widgets.find((entry) => entry.id === id)
const nl = read('l10n/nl.json').translations

describe('case type Fields tab texts (DqZaaktypeVelden)', () => {
	it('subtitles the Fields card', () => {
		expect(nl[widget('case-type-fields-editor').subtitle]).toBe('Het gegevensschema van dit zaaktype. Sleep om de volgorde te wijzigen. Velden van het moedertype zijn paars.')
	})

	it('titles the field rights card per role and status and draws its line as a subtitle (field-rights-matrix-by-status)', () => {
		const rules = widget('case-type-field-rules')
		expect(nl[rules.title]).toBe('Wie wat ziet en invult, per rol en status')
		expect(nl[rules.subtitle]).toBe('Verborgen, verplicht of alleen lezen per rol en status')
		expect(rules.description).toBeUndefined()
	})
})
