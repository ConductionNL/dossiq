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
		expect(card.content.endpointSource).toEqual({ url: '/apps/dossiq/api/cases/@objectId/relations', responsePath: 'results' })
		expect(card.content.rowRoute).toBe('CaseDetail')
		expect(card.content.rowIdField).toBe('caseId')
	})

	it('reads the number, case type and handler the endpoint adds', () => {
		expect(card.content.avatar).toBe('{title}')
		expect(card.content.meta[0]).toBe('{identifier} · {caseTypeTitle} · {assigneeName}')
	})

	it('groups by what the relation is to this case, with a heading for every relation type', () => {
		expect(card.content.groupBy.field).toBe('displayLabel')
		expect(card.content.groupBy.fallbackField).toBe('aardRelatie')
		for (const type of relationTypes) {
			expect(nl[card.content.groupBy.labels[type]], type).toBeTruthy()
		}
		expect(nl['Subject of this case']).toBe('Onderwerp van deze zaak')
	})

	it('draws a status pill for every role a status can have', () => {
		for (const role of roles) {
			expect(nl[card.content.pill.labels[role]], role).toBeTruthy()
		}
	})

	it('leaves the relations out of the planned cases card, so no relation is listed twice', () => {
		const planned = widgets.find((widget) => widget.id === 'case-related-card')
		expect(planned.content.extraSources.map((source) => source.key)).toEqual(['planned'])
	})
})
