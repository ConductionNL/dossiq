/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tabs per case type. The case page reads the case type's `tabs` through
 * `tabsFrom`; a case type that lists none shows the tabs as declared. Each
 * listed id names a tab of the case panels (its `id`, else its `widgetId`),
 * and the domain packages list the tabs their boards draw.
 *
 * @spec openspec/changes/tabs-per-case-type/specs/case-page/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const overlay = readJson('src', 'menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const tabIds = overlay.configPatch.widgets['case-panels'].content.tabs.map((tab) => tab.id || tab.widgetId)

/**
 * The case types a package ships.
 *
 * @param {string} id The package id.
 * @return {Array<object>} The case types.
 */
function packageCaseTypes(id) {
	const data = readJson('lib', 'Settings', 'packages', id, 'case-type.json')
	return Array.isArray(data.caseType) ? data.caseType : [data.caseType]
}

describe('tabs per case type', () => {
	it('reads the list off the case type', () => {
		expect(overlay.config.tabsFrom).toBe('@context.caseType.tabs')
		expect(tabIds.slice(0, 1)).toEqual(['case-overview'])
		expect(tabIds).toContain('case-contact')
		expect(tabIds).toContain('case-tasks')
	})

	it('lets the domain packages list tabs of the case panels, Overview first', () => {
		const lists = {
			jeugdwet: packageCaseTypes('jeugdwet')[0].tabs,
			aanbesteding: packageCaseTypes('aanbesteding')[0].tabs,
			subsidie: packageCaseTypes('subsidie')[0].tabs,
			bezwaar: packageCaseTypes('bezwaar-beroep').find((caseType) => caseType.identifier === 'bezwaar').tabs,
		}
		for (const [name, list] of Object.entries(lists)) {
			expect(list[0], name).toBe('case-overview')
			for (const id of list) {
				expect(tabIds, `${name} lists ${id}`).toContain(id)
			}
		}
		const seeded = readJson('lib', 'Settings', 'register.d', '84-woo-bezwaar-case-type.json')
			.components.objects.find((row) => row['@self'].schema === 'caseType' && row.identifier === 'bezwaar')
		expect(seeded.tabs).toEqual(lists.bezwaar)
		for (const caseType of packageCaseTypes('vth')) {
			expect(caseType.tabs).toBeUndefined()
		}
	})
})
