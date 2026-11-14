/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The four forfeit settings of a case type are edited in one Collection card,
 * behind the Properties tab of the case type, because the board draws no
 * place for them.
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const full = read('src/manifest.json').pages.find((page) => page.id === 'CaseTypeDetail')
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseTypeDetail')
const nl = read('l10n/nl.json').translations
const FIELDS = ['forfeitRaisesInvoice', 'forfeitPayerRoleType', 'forfeitAdministration', 'forfeitRequestType']

describe('the case type collection settings', () => {
	const widget = full.config.widgets.find((w) => w.id === 'case-type-collection')

	it('is one data card with the four forfeit settings', () => {
		expect(widget.type).toBe('data')
		expect(widget.content.include).toEqual(FIELDS)
		expect(nl[widget.title]).toBe('Invorderen')
	})

	it('has a place in the full profile grid', () => {
		expect(full.config.layout.some((cell) => cell.widgetId === 'case-type-collection')).toBe(true)
	})

	it('sits behind the Properties tab of the simple profile, after the terms', () => {
		const tabs = simple.configAppend.widgets[0].content.tabs
		const properties = tabs.find((tab) => tab.id === 'properties')
		expect(properties.overflow).toBe(true)
		expect(properties.widgetIds.indexOf('case-type-collection')).toBe(properties.widgetIds.indexOf('case-type-terms') + 1)
	})
})
