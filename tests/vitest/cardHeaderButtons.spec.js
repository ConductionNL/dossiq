/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The boards draw a button in the head of DqTenant "Quota", DqZaaktype
 * "Termijnen" and DqZaaktypeOverzicht "Statussen" and "Regels". Each does
 * something that exists: the quota is edited on OpenRegister's organisation
 * page, the terms in the case type's edit form, the statuses on the Statuses
 * tab, and a rule comes from a field rule on the Fields tab.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const caseType = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseTypeDetail')
const caseTypeCard = (id) => caseType.configAppend.widgets.find((widget) => widget.id === id)
const tabIds = caseTypeCard('case-type-panels').content.tabs.map((tab) => tab.id)
const organisationCard = (id) => read('src/manifest.d/67-environment-organisations.json').pages.flatMap((page) => page.config?.widgets || []).find((widget) => widget.id === id)

describe('the card head buttons', () => {
	it('Quota opens OpenRegister\'s organisation page', () => {
		const [button] = organisationCard('organisation-quota').headerActions
		expect(nl[button.label]).toBe('Pakket wijzigen')
		expect(button.action).toEqual({ type: 'navigate', target: '/index.php/apps/openregister/organisation' })
	})

	it('Termijnen opens the case type\'s edit form', () => {
		const [button] = caseTypeCard('case-type-terms-written').headerActions
		expect(nl[button.label]).toBe('Bewerken')
		expect(button.action).toEqual({ type: 'edit-record' })
	})

	it.each([
		['case-type-statuses', 'Bewerken', 'statuses'],
		['case-type-rules-summary', 'Regel toevoegen', 'fields'],
	])('%s opens a tab the page has', (id, label, tab) => {
		const [button] = caseTypeCard(id).headerActions
		expect(nl[button.label]).toBe(label)
		expect(button.action).toEqual({ type: 'open-tab', tab })
		expect(tabIds).toContain(tab)
	})
})
