/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case's incidents draw their state in tone, as DqZaakTaken does: an
 * open incident amber, one in handling purple, a handled one green.
 *
 * @spec exclude board tone of the incident state pills (DqZaakTaken), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const register = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../lib/Settings/dossiq_register.json'), 'utf8'))
const caseDetail = simple.pages.find((entry) => entry.id === 'CaseDetail')
const incidents = caseDetail.configAppend.widgets.find((widget) => widget.id === 'case-incidents-card')
const state = incidents.content.pill

describe('the incident state pills', () => {
	it('draw each state in its tone', () => {
		expect(state.colorMap).toEqual({ open: 'warning', 'in-behandeling': 'purple', afgehandeld: 'success' })
	})

	it('name a tone for every state the schema allows', () => {
		const values = register.components.schemas.caseIncident.properties.state.enum
		expect(Object.keys(state.colorMap).sort()).toEqual([...values].sort())
	})

	it('label each state, open as the board\'s "Open"', () => {
		expect(Object.keys(state.labels).sort()).toEqual(Object.keys(state.colorMap).sort())
		const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		expect(nl[state.labels.open]).toBe('Open')
	})
})

describe('the incidents as rows (DqZaakTaken Incidenten)', () => {
	it('draws rows, not a table, in eventDate order', () => {
		expect(incidents.type).toBe('record-list')
		expect(incidents.content.presentation).toBe('rows')
		expect(incidents.content.sort).toEqual({ field: 'eventDate', dir: 'asc' })
		expect(incidents.content.columns).toBeUndefined()
	})

	it('keeps the recording moment on every row, so the delay stays visible', () => {
		for (const line of incidents.content.meta) {
			expect(line).toContain('{eventDate|date}')
		}
		expect(incidents.content.meta.slice(0, -1).every((line) => line.includes('{recordedAt|date}'))).toBe(true)
	})

	it('keeps Add, as the incident form', () => {
		const add = incidents.headerActions.find((action) => action.label === 'Add')
		expect(add.action).toMatchObject({ type: 'open-form', schema: 'caseIncident', props: { case: '@objectId' } })
	})
})
