/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's Statuses tab against board DqZaaktypeStatussen: each list
 * says what it is for under its title, its Add names what it adds, the
 * statuses list shows what a handler and an applicant see of a status, and a
 * result's retention period reads in words.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-the-statuses-tab-reads-as-the-board
 */

import { describe, expect, it } from 'vitest'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'
import simple from '../../src/menu-layout.simple.json'

const page = manifest.pages.find((p) => p.id === 'CaseTypeDetail').config
const appended = simple.pages.find((p) => p.id === 'CaseTypeDetail').configAppend.widgets
const widget = (id) => page.widgets.find((w) => w.id === id) || appended.find((w) => w.id === id)
const dutch = (key) => nl.translations[key]

describe('the Statuses tab against DqZaaktypeStatussen', () => {
	it('says under Statuses and Results what each list is for', () => {
		expect(dutch(widget('case-type-statuses-editor').subtitle)).toBe('Volgorde, kleur en wat de aanvrager ziet. Sleep om de volgorde te wijzigen.')
		expect(dutch(widget('case-type-results').subtitle)).toBe('Hoe een zaak eindigt, en wat er daarna met het dossier gebeurt')
	})

	it('names what each Add adds', () => {
		const labels = ['case-type-statuses-editor', 'case-type-results', 'case-type-document-types', 'case-type-decision-types']
			.map((id) => dutch(widget(id).content.addLabel))
		expect(labels).toEqual(['Status toevoegen', 'Resultaat toevoegen', 'Documenttype toevoegen', 'Besluittype toevoegen'])
	})

	it('names a status as a link with its colour as a dot, and a result as a link in sentence case', () => {
		const statuses = widget('case-type-statuses-editor').content
		expect(statuses.rowTitle).toBeUndefined()
		const name = statuses.columns.find((c) => c.key === 'name')
		expect(name.format).toMatchObject({ style: 'swatch', colorField: 'colour' })
		expect(Object.keys(name.format.colorMap)).toEqual(expect.arrayContaining(['blue', 'orange', 'purple', 'teal', 'green', 'red']))
		const results = widget('case-type-results').content
		expect(results.rowTitle).toBeUndefined()
		const action = results.columns.find((c) => c.key === 'archivalAction')
		expect(['bewaren', 'vernietigen', 'blijvend_bewaren'].map((v) => dutch(action.enumLabels[v]))).toEqual(['Bewaren', 'Vernietigen', 'Blijvend bewaren'])
	})

	it('writes the list colour as a word, as the board does, not as a pill', () => {
		const colour = widget('case-type-statuses-editor').content.columns.find((c) => c.key === 'colour')
		expect(colour.widget).toBe('label')
		expect(['blue', 'orange', 'purple', 'teal', 'green', 'red'].map((v) => dutch(colour.enumLabels[v]))).toEqual(['Blauw', 'Oranje', 'Paars', 'Groenblauw', 'Groen', 'Rood'])
	})

	it('lists a status by name, list colour, whether it ends the case and what the applicant sees', () => {
		const columns = widget('case-type-statuses-editor').content.columns
		expect(columns.map((c) => c.key)).toEqual(['name', 'colour', 'isFinal', 'publicLabel'])
		expect(columns.map((c) => dutch(c.label))).toEqual(['Status', 'Kleur in lijsten', 'Eindstatus', 'Wat de aanvrager ziet'])
		// Order is still kept by dragging, and the form still edits role and waiting-on.
		expect(widget('case-type-statuses-editor').content.reorder).toEqual({ field: 'order' })
		expect(widget('case-type-statuses-editor').content.formIncludeFields).toEqual(expect.arrayContaining(['order', 'role', 'waitingOn']))
	})

	it('reads a result\'s retention period in words', () => {
		const period = widget('case-type-results').content.columns.find((c) => c.key === 'archivalPeriod')
		expect(period.formatter).toBe('isoDuration')
	})

	it('reads a kept result without a period as Permanent, and leaves a destroyed one empty', () => {
		const period = widget('case-type-results').content.columns.find((c) => c.key === 'archivalPeriod')
		expect(period.emptyValueBy.field).toBe('archivalAction')
		expect(dutch(period.emptyValueBy.values.bewaren)).toBe('Permanent')
		expect(dutch(period.emptyValueBy.values.blijvend_bewaren)).toBe('Permanent')
		expect(period.emptyValueBy.values.vernietigen).toBeUndefined()
	})

	it('writes Eindstatus as Nee, with only the final ones as an Eind pill, and the archival action as a word', () => {
		const final = widget('case-type-statuses-editor').content.columns.find((c) => c.key === 'isFinal')
		expect(final.widgetProps).toEqual({ trueLabel: 'End status', falseLabel: 'No', pill: true })
		expect([dutch('End status'), dutch('No')]).toEqual(['Eind', 'Nee'])
		const action = widget('case-type-results').content.columns.find((c) => c.key === 'archivalAction')
		expect(action.widget).toBe('label')
	})
})
