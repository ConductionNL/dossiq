/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqVthInstellingen Handhavingsstrategie: the active matrix as facts (Matrix,
 * Versie, Laatst gewijzigd). The table of every version stays, behind
 * "Eerdere versies", so no version is out of reach.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const admin = fs.readFileSync(path.join(root, 'src/views/settings/AdminRoot.vue'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(root, 'l10n/nl.json'), 'utf8')).translations
const section = admin.slice(admin.indexOf('id="section-enforcement-strategy"'), admin.indexOf('</CnSettingsSection>', admin.indexOf('id="section-enforcement-strategy"')))

describe('the enforcement strategy card (DqVthInstellingen)', () => {
	it('shows the active matrix as facts', () => {
		expect(section).toContain('<CnFactsWidget')
		expect(section).toContain(':content="enforcementMatrixFacts"')
		expect(admin).toMatch(/enforcementMatrixFacts\(\) \{[\s\S]*?filter: \{ active: true \}/)
	})

	it('keeps every version behind Eerdere versies', () => {
		expect(section).toMatch(/<CnWidgetObjectTable\s+v-if="showEarlierMatrices"/)
		expect(nl['Earlier versions']).toBe('Eerdere versies')
	})

	it('drops the description the board does not draw', () => {
		expect(section).not.toContain('The matrix that recommends an intervention')
	})
})
