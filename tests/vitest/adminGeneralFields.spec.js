/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Huisstijl and Teksten en toegang write each setting where it lives: theming
 * and thematiq for the look, dossiq's own settings for its words.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	houseStyleFields,
	textsAndAccessFields,
} from '../../src/views/settings/adminFields.js'

const ROOT = path.resolve(__dirname, '../..')
const configKeys = fs.readFileSync(
	path.join(ROOT, 'lib', 'Service', 'Settings', 'ConfigKeys.php'),
	'utf8',
)

/**
 * The translate the fields call.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @return {string} The text.
 */
function t(app, text) {
	return text
}

describe('the general admin sections', () => {
	const fields = [...houseStyleFields(t), ...textsAndAccessFields(t)]

	it.each(
		fields
			.filter((field) => !field.url && !field.readOnly && field.type !== 'note')
			.map((field) => [field.key]),
	)(
		'%s, saved through dossiq settings, is a setting the endpoint saves',
		(key) => {
			expect(configKeys).toContain(`'${key}',`)
		},
	)

	it('keeps the theme and the logo with the apps that own them', () => {
		const byKey = Object.fromEntries(fields.map((field) => [field.key, field]))
		expect(byKey.theme.saveUrl).toBe('/apps/thematiq/settings/app-brands/dossiq')
		expect(byKey.logo.uploadUrl).toBe('/apps/theming/ajax/uploadImage')
	})

	it('reads and writes the organisation name through Nextcloud theming', () => {
		const name = fields.find((field) => field.key === 'organisationName')
		expect(name.url).toBe('/ocs/v2.php/cloud/capabilities?format=json')
		expect(name.valuePath).toBe('ocs.data.capabilities.theming.name')
		expect(name.saveUrl).toBe('/apps/theming/ajax/updateStylesheet')
		expect(name.saveKey).toBe('value')
		expect(name.saveParams).toEqual({ setting: 'name' })
		const logo = fields.find((field) => field.key === 'logo')
		expect(logo.initialsFrom).toBe('organisationName')
	})

	it('asks for the municipality city the contacts and organisations lists open on', () => {
		const city = fields.find((field) => field.key === 'municipality_city')
		expect(city).toBeTruthy()
		expect(city.url).toBeUndefined()
		expect(configKeys).toContain("'municipality_city',")
	})

	it('only points at the CORS setting, which the portal owns, as a line of text', () => {
		const cors = fields.find((field) => field.key === 'allowedWebsites')
		expect(cors.type).toBe('note')
		expect(cors.text).toBe('Set in the portal app (portaliq) and in Nextcloud, not here.')
		expect(configKeys).not.toContain("'allowedWebsites',")
	})
})

/**
 * DqInstellingenAlgemeen draws Huisstijl as one row of four (organisation,
 * city, app name, theme) with the logo under it, unlabelled beside its
 * "Ander logo" button.
 */
describe('Huisstijl as the board', () => {
	it('orders the fields as the board and keeps the logo label for screen readers only', () => {
		const style = houseStyleFields(t)
		expect(style.map((field) => field.key)).toEqual(['organisationName', 'municipality_city', 'staff_app_name', 'theme', 'logo'])
		expect(style.find((field) => field.key === 'logo').hideLabel).toBe(true)
	})

	it('lays the card out in four columns', () => {
		const admin = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')
		const card = admin.slice(admin.indexOf('id="section-house-style"'), admin.indexOf(':fields="houseStyleFields"'))
		expect(card).toContain(':columns="4"')
	})
})

/**
 * DqInstellingenAlgemeen draws "Tekst boven Mijn zaken in het portaal" as one
 * line, like the sender above it. The text is one sentence.
 */
describe('Teksten en toegang', () => {
	it('asks the portal text on one line', () => {
		const field = textsAndAccessFields(t).find((entry) => entry.key === 'portal_intro_text')
		expect(field.type).toBeUndefined()
		expect(field.rows).toBeUndefined()
	})
})
