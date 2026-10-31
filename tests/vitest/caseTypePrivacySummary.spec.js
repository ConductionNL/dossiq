/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeOverzicht draws a Privacy card on the Overview tab: Doel,
 * Grondslag, Categorieën and Bewaartermijn, and under them "Telt mee in het
 * register van verwerkingen." The editable personal data card stays on the
 * Privacy tab.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const tabs = page.configAppend.widgets.find((widget) => widget.id === 'case-type-panels').content.tabs
const summary = page.configAppend.widgets.find((widget) => widget.id === 'case-type-privacy-summary')
const nl = read('l10n/nl.json').translations

describe('case type privacy summary (DqZaaktypeOverzicht)', () => {
	it('stands on the Overview tab; the Privacy tab keeps the editable card', () => {
		const overview = tabs.find((tab) => tab.id === 'overview')
		expect(overview.widgetIds).toContain('case-type-privacy-summary')
		expect(overview.widgetIds).not.toContain('case-type-privacy')
		expect(tabs.find((tab) => tab.id === 'privacy').widgetId).toBe('case-type-privacy')
	})

	it('reads Privacy with the board\'s four rows and the register line', () => {
		expect(summary.type).toBe('facts')
		expect(nl[summary.title]).toBe('Privacy')
		const fields = summary.content.fields
		expect(fields.filter((field) => field.label).map((field) => nl[field.label])).toEqual(['Doel', 'Grondslag', 'Categorieën', 'Bewaartermijn'])
		const line = fields.find((field) => field.as === 'line')
		expect(nl[line.text]).toBe('Telt mee in het register van verwerkingen.')
	})
})
