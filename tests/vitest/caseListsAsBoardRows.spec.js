/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakTaken draws "Wat hierna komt" and "Incidenten" as rows under a rule
 * (r26c: header.separator 1 vs 0, the planned actions as a table): the
 * planned actions as rows with a calendar tile, the owner and the date at
 * the end, and "{total} gepland" in the title row.
 *
 * @spec openspec/specs/case-page/spec.md
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
	})

	it('says who owns it, a person or a team by name, and who or what planned it', () => {
		const [owner, plannedBy] = widget('case-planned-actions-card').content.meta.parts
		expect(owner.map((key) => nl[key])).toEqual(['Eigenaar {owner|user}', 'Eigenaar team {ownerGroup|group}'])
		expect(plannedBy.map((key) => nl[key])).toEqual(['gepland door {plannedBy|user}', 'gepland door {plannedByRule}'])
	})

	it('registers the calendar tile\'s icon, so the row does not draw the unknown-icon glyph (r32: a question mark)', () => {
		const icons = fs.readFileSync(path.resolve(__dirname, '../../src/icons.js'), 'utf8')
		const name = widget('case-planned-actions-card').content.icon
		expect(icons).toContain(`import ${name} from 'vue-material-design-icons/${name}.vue'`)
		expect(icons).toMatch(new RegExp(`\\n\\t${name},\\n`))
	})

	it('keeps adding a planned action, as the table offered', () => {
		const add = widget('case-planned-actions-card').headerActions.find((action) => action.label === 'Add')
		expect(add.action).toMatchObject({ type: 'open-form', schema: 'plannedAction', props: { case: '@objectId', state: 'planned', plannedBy: '@me' } })
	})
})
