/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakTaken draws "Wat hierna komt" and "Incidenten" as rows under a rule
 * (r26c: header.separator 1 vs 0, the planned actions as a table): the
 * planned actions as rows with a calendar tile, the owner and the date at
 * the end, and "{total} gepland" in the title row.
 *
 * @spec openspec/changes/case-lists-as-board-rows/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const caseDetail = simple.pages.find((entry) => entry.id === 'CaseDetail')
const widget = (id) => caseDetail.configAppend.widgets.find((entry) => entry.id === id)

describe('the case lists as board rows', () => {
	it('rules above the first incident', () => {
		expect(widget('case-incidents-card').content.ruleAbove).toBe(true)
	})

	it('draws the planned actions as ruled rows with a calendar tile, the owner and the date', () => {
		const planned = widget('case-planned-actions-card')
		expect(planned.type).toBe('record-list')
		expect(planned.content).toMatchObject({ presentation: 'rows', ruleAbove: true, icon: 'CalendarBlankOutline', title: '{label}', trailing: '{plannedFor|date}' })
		expect(planned.content.filter).toEqual({ case: '@objectId', state: 'planned' })
		expect(planned.content.sort).toEqual({ field: 'plannedFor', dir: 'asc' })
		expect(nl[planned.content.headerNote]).toBe('{total} gepland')
		expect(nl[planned.content.meta[0]]).toBe('Eigenaar {owner|user}')
	})

	it('keeps adding a planned action, as the table offered', () => {
		const add = widget('case-planned-actions-card').headerActions.find((action) => action.label === 'Add')
		expect(add.action).toMatchObject({ type: 'open-form', schema: 'plannedAction', props: { case: '@objectId', state: 'planned' } })
	})
})
