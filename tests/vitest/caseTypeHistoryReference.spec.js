/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktype's Historie card ends in "Kenmerk ZT-OMV-REG": the case type's
 * identifier under the trail. Its rows are compact (56px a two-line row).
 *
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const page = readJson('src', 'menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const history = page.config.sideColumn.find((card) => card.id === 'side-case-type-history')
const nl = readJson('l10n', 'nl.json').translations

describe('the case type Historie card', () => {
	it('ends in the case type\'s reference', () => {
		expect(history.content.footnote).toBe('Reference {identifier}')
		expect(nl['Reference {identifier}']).toBe('Kenmerk {identifier}')
	})

	it('leaves out what the system writes, so the people who changed the type stay in view', () => {
		// The window refresh and the upgrades save a case type as System (with OpenRegister's cause import or
		// migration); the board draws "Versie 4 gepubliceerd, Lars de Koning". The All history tab keeps them.
		expect(history.content.excludeActors).toEqual(['System'])
		expect(history.content.excludeActions).toEqual(['read'])
	})

	it('draws compact stacked rows, 10px above and below, as DqZaaktype does', () => {
		expect(history.content.layout).toBe('stacked')
		expect(history.content.density).toBe('compact')
	})
})
