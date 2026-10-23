/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's Statuses tab against board DqZaaktypeStatussen: each list
 * says what it is for under its title, its Add names what it adds, the
 * statuses list shows what a handler and an applicant see of a status, and a
 * result's retention period reads in words.
 *
 * @spec openspec/changes/case-type-statuses-tab-board/specs/case-types/spec.md#requirement-the-statuses-tab-reads-as-the-board
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
})
