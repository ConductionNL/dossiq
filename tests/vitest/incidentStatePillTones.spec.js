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
const state = incidents.content.columns.find((column) => column.key === 'state')

describe('the incident state pills', () => {
	it('draw each state in its tone', () => {
		expect(state.colorMap).toEqual({ open: 'warning', 'in-behandeling': 'purple', afgehandeld: 'success' })
	})

	it('name a tone for every state the schema allows', () => {
		const values = register.components.schemas.caseIncident.properties.state.enum
		expect(Object.keys(state.colorMap).sort()).toEqual([...values].sort())
	})
})
