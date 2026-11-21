/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Dashboard widgets card on the System tab: one field per contributed
 * widget under its title, keyed by the id the server keys the audience on,
 * read from and saved to the admin-only endpoint.
 *
 * @spec openspec/changes/case-widgets-declare-who-may-see-them/specs/dashboard-widget-visibility/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	contributedWidgets,
	WIDGET_AUDIENCE_URL,
	widgetAudienceFields,
} from '../../src/views/settings/adminFields.js'

const ROOT = path.resolve(__dirname, '../..')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const admin = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')
const shipped = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'widget_roles.json'), 'utf8')).widgets

/**
 * Translate to Dutch, as the page does; a missing key stays itself.
 *
 * @param {string} app The app id.
 * @param {string} text The English key.
 * @return {string} The Dutch text.
 */
function t(app, text) {
	return nl[text] ?? text
}

describe('the Dashboard widgets card', () => {
	it('has one field per widget the server ships an audience for, in no other set', () => {
		expect(widgetAudienceFields(t).map((field) => field.key).sort()).toEqual(Object.keys(shipped).sort())
	})

	it('labels each field with the widget title, in Dutch', () => {
		expect(contributedWidgets(t).map((widget) => widget.title)).toEqual([
			'Zaakoverzicht',
			'Mijn werk',
			'Termijnsignalen',
			'Taakherinneringen',
			'Stilstaande zaken',
			'Zaak starten',
		])
	})

	it('says under every field that an empty list means everybody', () => {
		for (const field of widgetAudienceFields(t)) {
			expect(field.hint).toBe('Groepsnamen, gescheiden door komma\'s. Leeg: iedereen ziet de widget.')
		}
	})

	it('reads and saves through the admin-only endpoint the route declares', () => {
		const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
		expect(WIDGET_AUDIENCE_URL).toBe('/apps/dossiq/api/settings/widget-audience')
		expect(routes).toContain("'widgetAudience#show', 'url' => '/api/settings/widget-audience', 'verb' => 'GET'")
		expect(routes).toContain("'widgetAudience#save', 'url' => '/api/settings/widget-audience', 'verb' => 'PUT'")
		const card = admin.slice(admin.indexOf('id="section-widget-audience"'))
		expect(card.slice(0, card.indexOf('/>'))).toContain(':url="widgetAudienceUrl"')
	})

	it('sits on the System tab', () => {
		const system = admin.slice(admin.indexOf('<template #tab-system>'), admin.indexOf('<template #tab-integrations>'))
		expect(system).toContain('id="section-widget-audience"')
	})
})
