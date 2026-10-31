/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin Configuratie section picks the register and its schemas by name
 * (CnRegisterMapping), while the app config keeps the OpenRegister ids it has
 * always held, under the keys it has always used.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { registerMappingGroups } from '../../src/views/settings/adminFields.js'

const ROOT = path.resolve(__dirname, '../..')
const adminRoot = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')
const configKeys = fs.readFileSync(path.join(ROOT, 'lib', 'Service', 'Settings', 'ConfigKeys.php'), 'utf8')

const t = (app, text) => text

describe('the Configuratie section', () => {
	const [group] = registerMappingGroups(t)
	const schemaKeys = group.types.map((type) => `${type.slug}_schema`)

	it('keeps the register under the register key', () => {
		expect(group.registerConfigKey).toBe('register')
	})

	it('offers the seven schema settings the app config holds', () => {
		expect(schemaKeys).toEqual([
			'case_schema',
			'case_type_schema',
			'status_schema',
			'role_schema',
			'result_schema',
			'decision_schema',
			'status_type_schema',
		])
		for (const key of ['register', ...schemaKeys]) {
			expect(configKeys).toContain(`'${key}',`)
		}
	})

	it('is drawn by the library and saved through dossiq settings', () => {
		expect(adminRoot).toContain('<CnRegisterMapping')
		expect(adminRoot).toContain('id="section-configuration"')
		expect(adminRoot).toContain('@save="saveRegisterMapping"')
		// DqBeheerinstellingen: the register, then the schemas three to a row.
		const tag = adminRoot.slice(adminRoot.indexOf('<CnRegisterMapping'))
		expect(tag.slice(0, tag.indexOf('/>'))).toContain('boardLayout="selects"')
		expect(adminRoot).toContain("axios.put(generateUrl('/apps/dossiq/api/settings'), configuration)")
	})

	it('hands the library dossiq\'s translate, so schema titles read in the reader\'s language', () => {
		expect(adminRoot).toContain("cnTranslate: (key, vars) => this.t('dossiq', key, vars)")
	})
})
