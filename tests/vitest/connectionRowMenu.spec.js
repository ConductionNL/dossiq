/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqInstellingen: every Koppelingen row ends with a "…" menu holding what the
 * row offers, opening its settings card.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-a-connection-row-has-a-menu-with-what-the-row-offers
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const admin = fs.readFileSync(path.join(ROOT, 'src/views/settings/AdminRoot.vue'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

describe('the connection row menu', () => {
	it('fills the connections table\'s row actions slot for a row with a settings card', () => {
		const at = admin.indexOf(':source="connectionsSource"')
		const table = admin.slice(at, admin.indexOf('</CnWidgetObjectTable>', at))
		expect(table).toContain('<template #row-actions="{ row }">')
		expect(table).toContain('<CnRowActions v-if="row.settingsUrl" :actions="connectionRowActions" :row="row" />')
	})

	it('opens the row\'s settings card in the same tab', () => {
		const actions = admin.slice(admin.indexOf('connectionRowActions() {'), admin.indexOf('connectionColumns() {'))
		expect(actions).toContain("label: this.t('dossiq', 'Open settings')")
		expect(actions).toContain('href: (row) => row.settingsUrl')
		expect(actions).toContain("linkTarget: '_self'")
		expect(nl['Open settings']).toBe('Instellingen openen')
	})
})
