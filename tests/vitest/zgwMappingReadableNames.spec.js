/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ZGW API mapping table names each resource as a person reads it
 * ("Zaaktype", "Roltype") instead of the stored key ("caseType", "roltype").
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-a-zgw-mapping-row-names-its-resource
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { ZGW_RESOURCE_NAMES, zgwResourceName } from '../../src/views/settings/zgwResourceNames.js'

const ROOT = path.resolve(__dirname, '../..')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const repair = fs.readFileSync(path.join(ROOT, 'lib/Repair/LoadDefaultZgwMappings.php'), 'utf8')
const table = fs.readFileSync(path.join(ROOT, 'src/views/settings/ZgwMappingSettings.vue'), 'utf8')
const dutch = (app, text) => nl[text] || text

describe('the ZGW mapping names', () => {
	it('names every resource the default mappings store', () => {
		const keys = [...new Set([...repair.matchAll(/'zgwResource' => '(\w+)'/g)].map((m) => m[1]))]
		expect(keys.length).toBeGreaterThan(20)
		for (const key of keys) {
			expect(ZGW_RESOURCE_NAMES[key]).toBeTruthy()
			expect(typeof nl[ZGW_RESOURCE_NAMES[key]]).toBe('string')
		}
	})

	it('reads in Dutch, and an unknown key as itself', () => {
		expect(zgwResourceName('caseType', dutch)).toBe('Zaaktype')
		expect(zgwResourceName('roltype', dutch)).toBe('Roltype')
		expect(zgwResourceName('applicatie', dutch)).toBe('Applicatie')
		expect(zgwResourceName('somethingNew', dutch)).toBe('somethingNew')
	})

	it('shows the name in the table, not the key', () => {
		expect(table).toContain('<td>{{ resourceName(key) }}</td>')
		expect(table).not.toContain('<td>{{ key }}</td>')
	})

	it('sets a row\'s Edit and Reset side by side', () => {
		expect(table).toMatch(/<div class="zgw-mapping-settings__actions">\s*<NcButton type="secondary"/)
		expect(table).toMatch(/\.zgw-mapping-settings__actions \{\s*display: flex;/)
	})
	it('draws the state as a pill and 34px row buttons, as the board', () => {
		expect(table).toContain('class="cn-status-badge cn-status-badge--success"')
		expect(table).not.toMatch(/\.status-enabled \{/)
		expect(table).toMatch(/:deep\(\.button-vue\) \{\s*min-height: 34px;/)
		expect(nl['ZGW Resource']).toBe('ZGW-bron')
	})
})
