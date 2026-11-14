/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeOverzicht titles the personal data card "Privacy", as the tab it
 * belongs to. The card keeps every fact it shows.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { applyPageOverlay } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const full = read('src/manifest.json').pages.find((page) => page.id === 'CaseTypeDetail')
const overlay = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseTypeDetail')
const nl = read('l10n/nl.json').translations

describe('case type privacy card (DqZaaktypeOverzicht)', () => {
	const privacy = applyPageOverlay(full, overlay).config.widgets.find((widget) => widget.id === 'case-type-privacy')
	const original = full.config.widgets.find((widget) => widget.id === 'case-type-privacy')

	it('reads Privacy', () => {
		expect(nl[privacy.title]).toBe('Privacy')
		expect(nl[privacy.content.title]).toBe('Privacy')
	})

	it('keeps every fact it shows', () => {
		expect(privacy.content.include).toEqual(original.content.include)
		expect(privacy.type).toBe(original.type)
	})
})
