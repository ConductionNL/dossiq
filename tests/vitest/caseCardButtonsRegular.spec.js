/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page draws the labelled buttons in its cards' title rows at the
 * 40px control height (DqZaakContact "Live gesprek", DqZaakHistorie), where
 * the setting and case-type pages keep the compact 34px.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

describe('the case page card buttons', () => {
	it('are regular on the case page, in both structures', () => {
		expect(read('src/manifest.json').pages.find((p) => p.id === 'CaseDetail').config.cardButtons).toBe('regular')
		expect(read('src/menu-layout.simple.json').pages.find((p) => p.id === 'CaseDetail').config.cardButtons).toBe('regular')
	})

	it('stay compact on the case-type page', () => {
		expect(read('src/manifest.json').pages.find((p) => p.id === 'CaseTypeDetail').config.cardButtons).toBeUndefined()
	})
})
