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
			.filter((field) => !field.url && !field.readOnly)
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

	it('only points at the CORS setting, which the portal owns', () => {
		const cors = fields.find((field) => field.key === 'allowedWebsites')
		expect(cors.readOnly).toBe(true)
		expect(configKeys).not.toContain("'allowedWebsites',")
	})
})
