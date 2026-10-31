/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The VTH tab's "Activiteiten uit het Omgevingsloket" as DqVthInstellingen
 * draws it: DSO-activiteit, Wordt zaaktype, Variant, Laatst ontvangen, and a
 * row per activity no mapping links yet.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-dso-activity-table-shows-what-each-activity-becomes-and-when-it-last-arrived
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
}))

const { dsoActivityFormatters } = await import('../../src/views/settings/dsoActivityFormatters.js')

const ROOT = path.resolve(__dirname, '../..')
const admin = fs.readFileSync(path.join(ROOT, 'src/views/settings/AdminRoot.vue'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const columns = admin.slice(admin.indexOf('intakeActivityColumns() {'), admin.indexOf('feeScheduleSource() {'))

describe('the DSO activity table', () => {
	it('reads integriq\'s overview, with the unmapped activities', () => {
		expect(admin).toMatch(/url: '\/apps\/integriq\/api\/admin\/dso-activities',\s+responsePath: 'results',/)
		expect(admin).toContain(':endpointSource="intakeActivitySource"')
	})

	it('has the board\'s four columns', () => {
		const keys = [...columns.matchAll(/key: '([^']+)'/g)].map((m) => m[1])
		expect(keys).toEqual(['activityName', 'caseTypes', 'variant', 'lastReceivedAt'])
		expect(columns).toContain("formatter: 'dsoCaseTypes'")
		expect(columns).toContain("formatter: 'dsoVariants'")
		expect(columns).toContain("formatter: 'relativeDate'")
		expect(nl.Variant).toBe('Variant')
		expect(nl['Last received']).toBe('Laatst ontvangen')
	})

	it('keeps a row\'s note and marks a switched-off row', () => {
		expect(columns).toContain("secondary: 'note'")
		expect(columns).toContain("pills: [{ field: 'isActive', equals: false, label: this.t('dossiq', 'Inactive') }]")
	})

	it('hands the formatters to the admin shell', () => {
		expect(admin).toContain(':formatters="adminFormatters"')
	})

	it('names the case types, or how many requests wait for a link', () => {
		const row = { caseTypes: [{ reference: 'OV', title: 'Omgevingsvergunning regulier', variant: 'Regulier' }, { reference: 'TI' }] }
		expect(dsoActivityFormatters.dsoCaseTypes(row.caseTypes, row)).toBe('Omgevingsvergunning regulier, TI')
		expect(dsoActivityFormatters.dsoVariants(row.caseTypes, row)).toBe('Regulier')
		const waiting = { unmapped: true, waiting: 1, caseTypes: [] }
		expect(dsoActivityFormatters.dsoCaseTypes([], waiting)).toBe('Not linked yet: 1 request waiting')
		expect(dsoActivityFormatters.dsoVariants([], waiting)).toBe('')
		expect(nl['_Not linked yet: %n request waiting_::_Not linked yet: %n requests waiting_']).toEqual(['Nog niet gekoppeld: %n aanvraag wacht', 'Nog niet gekoppeld: %n aanvragen wachten'])
	})
})
