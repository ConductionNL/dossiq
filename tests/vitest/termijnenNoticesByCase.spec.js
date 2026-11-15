/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTermijnen "Ingebrekestellingen en dwangsom" as the board draws it: per
 * notice the case (a link), "Ontvangen 7 aug", the assessment as a pill, the
 * penalty ("€ 1.442, vastgesteld" / "€ 207, loopt sinds 16 okt" / "Geen") and
 * where the money stands ("Betaalsignaal verstuurd" / "Bedrag nog niet vast" /
 * "Niet van toepassing"), from GET /api/deadlines/notices.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars) => {
		const nl = { 'Received {date}': 'Ontvangen {date}', '{amount}, fixed': '{amount}, vastgesteld', '{amount}, running since {date}': '{amount}, loopt sinds {date}', None: 'Geen' }
		return (nl[text] || text).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
	},
	translatePlural: (_app, one, other, count) => (count === 1 ? one : other),
	getCanonicalLocale: () => 'nl-NL',
	getLanguage: () => 'nl',
}))

const { default: formatters } = await import('../../src/services/formatters.js')

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/manifest.json').pages.find((p) => p.id === 'TermijnDashboard')
const widget = page.config.widgets.find((w) => w.id === 'deadline-report-notices')
const nl = read('l10n/nl.json').translations
const plain = (text) => String(text).replace(/\u00a0/g, ' ')

describe('the notices of default card (DqTermijnen)', () => {
	it('reads the notices by case from the deadlines API, each row opening its case', () => {
		expect(widget.type).toBe('object-table')
		expect(widget.content.endpointSource).toEqual({ url: '/apps/dossiq/api/deadlines/notices', responsePath: 'notices' })
		expect(widget.content.rowRoute).toBe('CaseDetail')
		expect(widget.content.rowIdField).toBe('caseId')
		expect(widget.content.columns.map((c) => c.key)).toEqual(['caseLabel', 'receiptDate', 'validityStatus', 'penaltyState', 'financeState'])
		expect(widget.content.columns.map((c) => nl[c.label])).toEqual(['Zaak', 'Ingebrekestelling', 'Beoordeling', 'Dwangsom', 'Financiën'])
	})

	it('draws the assessment and the money as pills in words, and no money as plain text', () => {
		const col = (key) => widget.content.columns.find((c) => c.key === key)
		expect(col('validityStatus').widget).toBe('badge')
		expect(nl[col('validityStatus').enumLabels.valid]).toBe('Geldig')
		expect(nl[col('validityStatus').enumLabels.premaat]).toBe('Te vroeg: termijn loopt nog')
		expect(col('financeState').widget).toBe('badge')
		expect(nl[col('financeState').enumLabels['signal-sent']]).toBe('Betaalsignaal verstuurd')
		expect(nl[col('financeState').enumLabels['amount-open']]).toBe('Bedrag nog niet vast')
		expect(nl[col('financeState').emptyLabel]).toBe('Niet van toepassing')
	})

	it('tones a payment signal as handed on (teal), as the board draws it', () => {
		const col = (key) => widget.content.columns.find((c) => c.key === key)
		expect(col('financeState').widgetProps.colorMap['signal-sent']).toBe('teal')
	})

	it('words the receipt and the penalty', () => {
		expect(formatters.receivedOn('2026-08-07')).toBe('Ontvangen 7 aug')
		expect(plain(formatters.penaltyLine('definitive', { penaltyEuros: 1442, penaltySince: '2026-08-21' }))).toBe('€ 1.442, vastgesteld')
		expect(plain(formatters.penaltyLine('running', { penaltyEuros: 207, penaltySince: '2026-10-16' }))).toBe('€ 207, loopt sinds 16 okt')
		expect(formatters.penaltyLine('none', { penaltyEuros: 0 })).toBe('Geen')
	})
})
