/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqInstellingen: the Koppelingen table lists Kadaster (BRK) en WOZ, and its
 * settings card shows under the table once that row is followed, as
 * "Kadaster (BRK) en WOZ instellen", "Geopend vanuit de rij Kadaster (BRK) en
 * WOZ". A row's link opens its card on the same page, and a connection
 * switched off reads Uit.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const admin = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

describe('Kadaster opened from its row', () => {
	const card = admin.slice(admin.indexOf('id="section-kadaster"'), admin.indexOf(':fields="kadasterFields"'))

	it('names the card as the board and opens it from the Kadaster row', () => {
		expect(card).toContain(":name=\"t('dossiq', 'Set up Kadaster (BRK) and WOZ')\"")
		expect(card).toContain(":openedFrom=\"t('dossiq', 'Kadaster (BRK) and WOZ')\"")
		expect(nl['Set up Kadaster (BRK) and WOZ']).toBe('Kadaster (BRK) en WOZ instellen')
	})

	it('links each connection row to its card on the same page', () => {
		const columns = admin.slice(admin.indexOf('connectionColumns() {'), admin.indexOf('intakeActivitySource() {'))
		expect(columns).toMatch(/key: 'title',[\s\S]*?widget: 'link',[\s\S]*?widgetProps: \{ href: '\{settingsUrl\}', target: '_self' \}/)
	})

	it('reads a switched-off connection as Off', () => {
		const columns = admin.slice(admin.indexOf('connectionColumns() {'), admin.indexOf('intakeActivitySource() {'))
		expect(columns).toMatch(/'disabled',/)
		expect(columns).toMatch(/disabled: 'Off',/)
	})
})
