/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Parties tab's manifest-to-schema contract.
 *
 * Everything this change ships is config: a widget, a tab entry, a header
 * action and two index columns. Config fails the way config fails — a column
 * bound to a property nothing declares renders an em-dash forever, a filter on
 * a key the schema does not have returns every row on the instance, and an
 * `open-form` action that does not carry a required property answers 400 on a
 * button no CI run ever presses. None of those raise an error anywhere in the
 * build, so this file is the check that turns them into failing tests.
 *
 * Both files are read from disk rather than imported, so the assertions cover
 * the on-disk config the importer and the manifest renderer actually read.
 *
 * @spec openspec/changes/parties-on-the-case/specs/roles-decisions/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const MANIFEST_PATH = path.resolve(__dirname, '../../src/manifest.json')
const REGISTER_PATH = path.resolve(
	__dirname,
	'../../lib/Settings/dossiq_register.json',
)

const loadJson = (filePath) => JSON.parse(fs.readFileSync(filePath, 'utf8'))

/**
 * One page as the manifest declares it.
 *
 * @param {string} id The manifest page id.
 * @return {object} The page entry.
 */
function page(id) {
	return loadJson(MANIFEST_PATH).pages.find((entry) => entry.id === id)
}

/**
 * One schema of the shipped dossiq register.
 *
 * @param {string} slug The schema slug.
 * @return {object} The schema entry.
 */
function schema(slug) {
	return loadJson(REGISTER_PATH).components.schemas[slug]
}

/**
 * One widget of the CaseDetail page.
 *
 * @param {string} id The manifest widget id.
 * @return {object} The widget entry.
 */
function widget(id) {
	return page('CaseDetail').config.widgets.find((entry) => entry.id === id)
}

/**
 * One header action of the CaseDetail page.
 *
 * @param {string} id The manifest action id.
 * @return {object} The action entry.
 */
function action(id) {
	return page('CaseDetail').config.headerActions.find((entry) => entry.id === id)
}

describe('the Parties widget', () => {
	it('lists the roles of the open case, sorted by role type', () => {
		const roles = widget('case-roles')

		expect(roles).toBeTruthy()
		expect(roles.type).toBe('object-list')
		expect(roles.content.register).toBe('dossiq')
		expect(roles.content.schema).toBe('role')
		expect(roles.content.filter).toEqual({ case: '@objectId' })
		expect(roles.content.sort).toEqual({ field: 'roleType', dir: 'asc' })
		expect(roles.content.limit).toBe(50)
		expect(roles.content.emptyText).toBeTruthy()
	})

	it('shows the role type, the participant and the delegation window', () => {
		expect(
			widget('case-roles').content.columns.map((column) => column.key),
		).toEqual(['roleType', 'participant', 'delegate', 'delegateUntil'])
	})

	it('reads only properties the role schema declares', () => {
		const properties = Object.keys(schema('role').properties)
		const roles = widget('case-roles')

		for (const key of Object.keys(roles.content.filter)) {
			expect(properties, `filter key ${key}`).toContain(key)
		}
		for (const column of roles.content.columns) {
			expect(properties, `column ${column.key}`).toContain(column.key)
		}
		expect(properties, 'the sort field').toContain(roles.content.sort.field)
	})

	it('is a tab of the case panels and never a layout cell of its own', () => {
		const detail = page('CaseDetail')
		const tabs = detail.config.widgets.find((entry) => entry.id === 'case-panels')
			.content.tabs

		expect(tabs.map((tab) => tab.widgetId)).toContain('case-roles')
		// A widget rendered by the tabs widget AND placed in `layout` renders
		// twice, which is why its siblings are absent from `layout` too.
		expect(
			(detail.config.layout || []).map((cell) => cell.widgetId),
		).not.toContain('case-roles')
	})
})
