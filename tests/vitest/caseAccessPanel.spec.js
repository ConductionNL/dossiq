// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The case access tab: who holds which right, and where the grant came from.
 *
 * The tab is the library's `object-permissions` sidebar built-in
 * (CnObjectPermissionsTab); its reads and rows are pinned by the library's own
 * specs (tests/utils/objectPermissions.spec.js and the component spec). What
 * is pinned here is the dossiq half: that the case page declares it, points it
 * at the case type's field-rule declaration, and names a registered icon. A
 * sidebar tab declaring an unknown widget type renders an empty panel and logs
 * nothing a reader sees.
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

/** Every sidebar tab declared on any page of the manifest. */
function sidebarTabs() {
	const tabs = []
	for (const page of manifest.pages || []) {
		for (const tab of page?.config?.sidebar?.tabs || page?.sidebar?.tabs || []) {
			tabs.push({ page: page.id, ...tab })
		}
	}
	return tabs
}

describe('The access tab is wired, not orphaned', () => {
	const tab = () => sidebarTabs().find((entry) => entry.id === 'access' && entry.page === 'CaseDetail')

	it('is declared as a sidebar tab on the case page', () => {
		expect(
			tab(),
			'A case with no access tab answers nobody about who could open it.',
		).toBeTruthy()
	})

	it('renders the library permissions widget, not a dossiq component', () => {
		expect(tab().component).toBeUndefined()
		expect(tab().widgets).toEqual([
			expect.objectContaining({ type: 'object-permissions' }),
		])
		expect(registrySource).not.toContain('CaseAccessTab')
	})

	it("points the field rules at the case type's declaration", () => {
		// Without it the "Which fields each role reads" section is left out,
		// and a handler looking at a gap is not told which rule made it.
		expect(tab().widgets[0].props.fieldRules).toEqual({
			typeField: 'caseType',
			typeSchema: 'caseType',
			declarationField: 'fieldRoleRules',
		})
	})

	it('reads the case in the register and schema the sidebar is on', () => {
		const page = manifest.pages.find((entry) => entry.id === 'CaseDetail')
		expect(page.config.register).toBe('dossiq')
		expect(page.config.schema).toBe('case')
	})

	it('names an icon that is registered, or the tab has no glyph', () => {
		expect(iconsSource).toContain(tab().icon)
	})
})
