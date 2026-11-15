/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakTaken: the case's task table shows the deadline as a date, the state
 * in dossiq's words ("In behandeling" in the work-in-progress tone, "Klaar")
 * and the link to all of the case's tasks under the table.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const table = simple.configAppend.widgets.find((widget) => widget.id === 'case-tasks-card').content
const nl = read('l10n/nl.json').translations

describe('the case task table', () => {
	it('shows the deadline as a date and the link under the table', () => {
		expect(table.dueFormat).toBe('date')
		expect(table.viewAllPlacement).toBe('footer')
	})

	it('words and colours the state as the board does', () => {
		expect(nl[table.stateLabels.active]).toBe('In behandeling')
		expect(nl[table.stateLabels.completed]).toBe('Klaar')
		expect(table.stateColors.active).toBe('purple')
	})

	it('sorts and filters from its column heads, as the board draws them', () => {
		expect(table.sortable).toBe(true)
		expect(table.filterable).toBe(true)
	})
})
