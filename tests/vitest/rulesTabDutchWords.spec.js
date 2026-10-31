/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Rules tab's rows read in Dutch (DqZaaktypeRegels): a calculated field
 * without a schema title ("Days overdue") and the engine's own kind lines
 * ("Derives a value ...") have a Dutch catalogue entry, as do the case
 * schema's first sentences shown under a title.
 *
 * @spec openspec/specs/case-types/spec.md
 */
import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import { ruleRows } from '../../src/services/schemaRules.js'

const KINDS = {
	calculation: { description: 'Derives a value from the object and writes it before the object is stored.' },
	flow: { description: 'Runs a flow after the object is stored.' },
}
const RULES = [
	{ id: 'calculation:case:daysOverdue', kind: 'calculation', key: 'daysOverdue', label: 'daysOverdue' },
	{ id: 'calculation:case:daysSinceActivity', kind: 'calculation', key: 'daysSinceActivity', label: 'daysSinceActivity' },
	{ id: 'calculation:case:daysUntilDeadline', kind: 'calculation', key: 'daysUntilDeadline', label: 'daysUntilDeadline' },
	{ id: 'calculation:case:deadline', kind: 'calculation', key: 'deadline', label: 'deadline' },
	{ id: 'flow:case:30e07359-1a37-4799-815f-19d586eb1e14', kind: 'flow', key: '30e07359-1a37-4799-815f-19d586eb1e14', label: '30e07359-1a37-4799-815f-19d586eb1e14' },
]
const PROPERTIES = { deadline: { title: 'Deadline', description: 'Processing deadline. Calculated from the start date.' } }

describe('the Rules tab rows in Dutch', () => {
	it('has a Dutch entry for every title and line the rows show', () => {
		const rows = ruleRows(RULES, PROPERTIES, KINDS)
		const missing = rows.flatMap((row) => [row.title, row.line]).filter((text) => text && nl.translations[text] === undefined)
		expect(missing).toEqual([])
		expect(nl.translations[rows[0].title]).toBe('Dagen te laat')
		expect(nl.translations[rows[0].line]).toBe('Berekend uit de zaak, bij elk opslaan.')
	})
})
