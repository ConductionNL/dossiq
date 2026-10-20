/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type page's side column follows the open tab (boards
 * DqZaaktypeOverzicht, DqZaaktype, DqZaaktypeVelden).
 *
 * @spec openspec/changes/case-type-side-cards-per-tab/specs/case-type-side-cards-per-tab/spec.md#requirement-the-case-type-side-column-follows-the-open-tab
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const page = simple.pages.find((entry) => entry.id === 'CaseTypeDetail')
const tabs = page.configAppend.widgets.find((widget) => widget.id === 'case-type-panels').content.tabs
const side = page.config.sideColumn
const tabIds = tabs.map((tab) => tab.id)

/**
 * The tab ids a side card shows beside.
 *
 * @param {string} id The side card id.
 * @return {string[]} The tab ids.
 */
function besides(id) {
	return side.find((card) => card.id === id).tabs
}

describe('case type side column per tab', () => {
	it('gives every tab an id', () => {
		expect(tabIds).toEqual(['overview', 'fields', 'statuses', 'phases', 'rights', 'privacy', 'translations', 'versions', 'portal', 'history'])
	})

	it('names only tabs that exist', () => {
		side.forEach((card) => (card.tabs || []).forEach((id) => expect(tabIds).toContain(id)))
	})

	it('shows the board\'s cards beside Overview, Phases and tasks and Fields', () => {
		const on = (tab) => side.filter((card) => (card.tabs || tabIds).includes(tab)).map((card) => card.id)
		expect(on('overview')).toEqual(['side-case-type-versions', 'side-case-type-check'])
		expect(on('phases')).toEqual(['side-case-type-versions', 'side-case-type-facts', 'side-case-type-history'])
		expect(on('fields')).toEqual(['side-case-type-choice-lists', 'side-case-type-parent'])
	})

	it('draws the check beside Overview, not in its body', () => {
		expect(besides('side-case-type-check')).toEqual(['overview'])
		expect(tabs[0].widgetIds).not.toContain('case-type-check')
	})
})

/**
 * @spec openspec/changes/case-type-choice-lists/specs/case-type-choice-lists/spec.md#requirement-a-fields-choices-are-edited-beside-fields
 */
describe('choice lists beside Fields', () => {
	const card = side.find((entry) => entry.id === 'side-case-type-choice-lists')

	it('lists the type\'s fields that hold a choice list', () => {
		expect(card.type).toBe('object-list')
		expect(card.content.schema).toBe('propertyDefinition')
		expect(card.content.filter).toEqual({ caseType: '@objectId', enumValues: { isnull: false } })
	})

	it('edits a field\'s choices one per line and adds no field', () => {
		expect(card.content.editable).toBe(true)
		expect(card.content.allowCreate).toBe(false)
		expect(card.content.formIncludeFields).toEqual(['enumValues'])
		expect(card.content.formFieldOverrides.enumValues.widget).toBe('lines')
	})
})
