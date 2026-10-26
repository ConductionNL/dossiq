/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakGerelateerd opens with "Gerelateerde zaken": the case's typed peer
 * relations grouped by what they are to this case ("Onderwerp van deze
 * zaak", "Wacht op"), each with the other case's initials, number, case type
 * and handler and its status.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const widgets = simple.configAppend.widgets
const card = widgets.find((widget) => widget.id === 'case-related-cases-card')
const tabs = simple.configPatch.widgets['case-panels'].content.tabs
const nl = read('l10n/nl.json').translations
const relationTypes = fs.readFileSync(path.join(ROOT, 'lib/Service/CaseRelationService.php'), 'utf8')
	.match(/RELATION_TYPES = \[([^\]]*)\]/)[1].match(/'([^']+)'/g).map((quoted) => quoted.slice(1, -1))
const roles = read('lib/Settings/dossiq_register.json').components.schemas.statusType.properties.role.enum

describe('the related cases card (DqZaakGerelateerd)', () => {
	it('opens the Related tab', () => {
		expect(tabs.find((tab) => tab.label === 'Related').widgetIds[0]).toBe('case-related-cases-card')
	})

	it('lists the typed relations from the relations endpoint, each linking to its case', () => {
		expect(card.type).toBe('record-list')
		expect(card.content.endpointSource).toEqual({ url: '/apps/dossiq/api/cases/@objectId/relations?planned=1', responsePath: 'results' })
		expect(card.content.rowRoute).toBe('CaseDetail')
		expect(card.content.rowIdField).toBe('caseId')
	})

	it('reads the number, case type and handler the endpoint adds', () => {
		// related-cases-as-board: the avatar names the case type, 32px, in the primary tone.
		expect(card.content.avatar).toEqual({ text: '{caseTypeTitle}', size: 32, tone: 'primary' })
		expect(card.content.meta[0]).toBe('{identifier} · {caseTypeTitle} · {assigneeName}')
	})

	it('groups by what the relation is to this case, with a heading for every relation type', () => {
		expect(card.content.groupBy).toBe('group')
		for (const type of relationTypes) {
			expect(nl[card.content.valueLabels.group[type]], type).toBeTruthy()
		}
		expect(nl['Subject of this case']).toBe('Onderwerp van deze zaak')
	})

	it('ends with the planned follow-ups under Gepland vervolg, with Plan follow-up and Stop series', () => {
		expect(card.content.endpointSource.url).toContain('?planned=1')
		expect(nl[card.content.valueLabels.group.planned]).toBe('Gepland vervolg')
		expect(card.content.footerAction).toMatchObject({ type: 'open-modal', target: 'CasePlanFollowUpDialog' })
		expect(card.content.rowActions[0]).toMatchObject({ label: 'Stop series', endpoint: '/apps/dossiq/api/case/@objectId/planned/{id}/stop', method: 'POST' })
		// Only a series can be stopped; a single planned flow carries no menu.
		expect(card.content.rowActions[0].visibleWhen).toEqual({ field: 'plannedKind', value: 'series' })
		for (const kind of ['flow', 'series']) {
			expect(nl[card.content.valueLabels.plannedKind[kind]], kind).toBeTruthy()
		}
	})

	it('draws a status pill for every role a status can have', () => {
		for (const role of roles) {
			expect(nl[card.content.pill.labels[role]], role).toBeTruthy()
		}
	})

	it('leaves the relations and the planned cases out of the parent case card, so nothing is listed twice', () => {
		const parent = widgets.find((widget) => widget.id === 'case-related-card')
		expect(parent.content.extraSources).toBeUndefined()
		expect(parent.content.referenceSections[0].field).toBe('parentCase')
	})
})
