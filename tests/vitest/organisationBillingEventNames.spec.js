/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTenant's Mandaten and Facturatie tables read in words ("Terugboeking",
 * "Waarschuwing", "Ja"), as plain text, not as their stored codes. The words go
 * through what nextcloud-vue's columns read: the `label` widget with `enum` +
 * `enumLabels`, or a formatter with `formatterOptions`.
 *
 * @spec openspec/changes/tenant-tables-in-words/specs/tenant-tables-in-words/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text) => ({ Yes: 'Ja', No: 'Nee' }[text] || text),
	translatePlural: (_app, one, other, count) => (count === 1 ? one : other),
}))

const { default: formatters } = await import('../../src/services/formatters.js')

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/manifest.d/67-environment-organisations.json').pages.find((entry) => entry.id === 'EnvironmentOrganisationDetail').config
const schemas = read('lib/Settings/dossiq_register.json').components.schemas
const nl = read('l10n/nl.json').translations
const column = (widgetId, key) => config.widgets.find((widget) => widget.id === widgetId).content.columns.find((col) => col.key === key)

describe('billing event names (DqTenant)', () => {
	it.each(['organisation-billing', 'organisation-billing-list'])('%s names every event type in Dutch, as text', (id) => {
		const col = column(id, 'eventType')
		expect(col.widget).toBe('label')
		expect([...col.enum].sort()).toEqual([...schemas.tenantBillingEvent.properties.eventType.enum].sort())
		expect(Object.keys(col.enumLabels).sort()).toEqual([...col.enum].sort())
		Object.values(col.enumLabels).forEach((label) => expect(nl[label]).toBeTruthy())
		expect(nl[col.enumLabels.case_refund]).toBe('Terugboeking')
		expect(col.valueLabels).toBeUndefined()
	})
})

describe('mandate columns (DqTenant)', () => {
	it('names the enforcement level in Dutch, as text', () => {
		const col = column('organisation-mandates', 'terms.enforcement')
		expect(col.widget).toBe('label')
		expect(col.enum).toEqual(['none', 'warning', 'full'])
		expect(nl[col.enumLabels.warning]).toBe('Waarschuwing')
		expect(col.valueLabels).toBeUndefined()
	})

	it('reads "Decides objections" as yes or no by the bezwaar decision type', () => {
		const col = column('organisation-mandates', 'terms.decisionTypes')
		expect(col.formatter).toBe('containsLabel')
		expect(col.formatterOptions).toEqual({ token: 'bezwaar', yes: 'Yes', no: 'No' })
		expect(col.contains).toBeUndefined()
		const cell = (value) => formatters[col.formatter](value, {}, {}, col.formatterOptions)
		expect(cell(['vergunning', 'bezwaar'])).toBe('Ja')
		expect(cell(['vergunning'])).toBe('Nee')
		expect(cell('vergunning, bezwaar')).toBe('Ja')
		expect(cell(null)).toBe('Nee')
	})
})
