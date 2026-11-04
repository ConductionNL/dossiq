/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakBetrokkenen "Toegang" on the simple People tab: the case's access
 * links as rows ("Link voor Sanne de Vries", 32px initials, what the holder
 * may do, "Verloopt 15 nov"), a revoke icon button on a live link, and
 * "Delen" opening the sidebar Sharing tab, which keeps the whole share flow.
 * Under the links, in the same card, the users and groups that can open the
 * case, read from OpenRegister's grants list ("Team Woo en informatie · via
 * het zaaktype", "Juridische zaken · gedeeld door Pieter Jansen").
 *
 * @spec openspec/changes/people-tab-access-card/specs/case-page/spec.md#requirement-the-simple-people-tab-lists-the-cases-access-links
 * @spec openspec/changes/case-access-card-lists-team-and-group-grants/specs/case-page/spec.md#requirement-the-toegang-card-lists-the-teams-and-groups-that-can-open-the-case
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const caseDetail = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const manifest = read('src/manifest.json')
const nl = read('l10n/nl.json').translations
const en = read('l10n/en.json').translations
const card = caseDetail.configAppend.widgets.find((widget) => widget.id === 'case-access-card')
const sectionOf = (url) => card.content.sections.find((section) => section.endpointSource.url.includes(url))
const links = sectionOf('/api/access-links/case/')
const grants = sectionOf('/grants')
const icons = fs.readFileSync(path.join(ROOT, 'src/icons.js'), 'utf8')

function strings() {
	return [
		card.title,
		card.subtitle,
		card.headerActions[0].label,
		links.title,
		links.meta,
		links.pill.label,
		...links.pill.cases.map((entry) => entry.label),
		...Object.values(links.valueLabels.capabilities),
		links.rowAction.label,
		links.rowAction.errorText,
		links.emptyText,
		links.errorText,
		card.content.footnote,
		...grants.meta.cases.map((entry) => entry.text),
		grants.pill.label,
		grants.emptyText,
		grants.refusalText,
		grants.errorText,
	]
}

/**
 * The origin line a grant row reads, as the record list writes it: the first
 * meta case whose condition holds, in the reader's language.
 *
 * @param {object} row A row of OpenRegister's grants list.
 * @return {string|undefined} The Dutch line with the row's values filled in.
 */
function originLine(row) {
	const holds = (cond) => {
		if (cond.all) {
			return cond.all.every(holds)
		}
		const value = cond.field.split('.').reduce((node, key) => (node === null || node === undefined ? undefined : node[key]), row)
		switch (cond.op) {
		case 'eq': return value === cond.value
		case 'contains': return Array.isArray(value) && value.includes(cond.value)
		case 'notEmpty': return value !== null && value !== undefined && value !== ''
		case 'empty': return value === null || value === undefined || value === ''
		default: throw new Error(`operator ${cond.op} is not modelled here`)
		}
	}
	const found = grants.meta.cases.find((entry) => holds(entry.when))
	return found && nl[found.text].replace(/\{([\w.]+)\}/g, (match, key) => key.split('.').reduce((node, part) => node?.[part], row))
}

describe('the Toegang card', () => {
	it('lists who can open the case first and the access links under them, as the board draws Toegang', () => {
		expect(card.content.sections.map((section) => section.endpointSource.url)).toEqual([
			'/apps/openregister/api/objects/dossiq/case/@objectId/grants',
			'/apps/dossiq/api/access-links/case/@objectId',
		])
	})

	it('reads the links the sidebar Sharing tab reads', () => {
		expect(card.type).toBe('record-list')
		expect(links.endpointSource).toEqual({ url: '/apps/dossiq/api/access-links/case/@objectId', responsePath: 'results' })
	})

	it('draws the board words', () => {
		expect(nl[card.title]).toBe('Toegang')
		expect(nl[card.subtitle]).toBe('Wie deze zaak mag openen, en waarom')
		expect(nl[card.headerActions[0].label]).toBe('Delen')
		expect(nl[links.title].replace('{label}', 'Sanne de Vries')).toBe('Link voor Sanne de Vries')
		expect(nl[links.meta].replace('{capabilities}', nl[links.valueLabels.capabilities.read])).toBe('Alleen lezen, zonder account · de link kan eindigen')
		expect(nl[links.pill.label]).toBe('Verloopt {expiresAt|date}')
		expect(nl[card.content.footnote]).toBe('Wie het BSN of een ander gevoelig veld leest, komt in het verwerkingenlog.')
	})

	it('puts the holder\'s initials in a 32px circle', () => {
		expect(links.avatar).toEqual({ text: '{label}', size: 32 })
	})

	it('revokes a live or paused link with an icon button, and not a revoked or expired one', () => {
		const action = links.rowAction
		expect(action).toMatchObject({ iconOnly: true, variant: 'outlined', icon: 'LinkVariantOff', method: 'DELETE' })
		expect(action.endpoint).toBe('/apps/dossiq/api/access-links/{accessLinkId}?caseId=@objectId')
		expect(action.labels).toEqual({ field: 'state', map: { revoked: '', expired: '' } })
		expect(nl[action.label]).toBe('Link intrekken')
		expect(icons).toMatch(/^\tLinkVariantOff,$/m)
	})

	it('opens the sidebar Sharing tab from Delen, a tab the case page declares', () => {
		expect(card.headerActions[0].action).toEqual({ type: 'open-sidebar-tab', tab: 'sharing' })
		expect(JSON.stringify(manifest)).toContain('"id":"sharing"')
	})

	it('has every string in English and Dutch, without an em-dash', () => {
		for (const text of strings()) {
			expect(en[text]).toBe(text)
			expect(typeof nl[text]).toBe('string')
			expect(nl[text]).not.toBe('')
			expect(`${text} ${nl[text]}`).not.toContain('\u2014')
		}
	})
})

describe('the Toegang card\'s grant rows', () => {
	it('reads OpenRegister\'s grants list for users and groups, after the links, in the same card', () => {
		expect(card.content.sections).toHaveLength(2)
		expect(grants.endpointSource).toEqual({
			url: '/apps/openregister/api/objects/dossiq/case/@objectId/grants',
			params: { principalTypes: 'user,group' },
			responsePath: 'results',
		})
		expect(card.content.ruleAbove).toBe(true)
		expect(card.content.presentation).toBe('rows')
	})

	it('draws a group icon for a group and the initials for a user at 32px, with the name as the title', () => {
		expect(grants.avatar).toEqual({ text: '{principal.displayName}', size: 32, icon: { field: 'principal.type', map: { group: 'AccountGroup' } } })
		expect(icons).toMatch(/^\tAccountGroup,$/m)
		expect(grants.title).toEqual(['{principal.displayName}', '{principal.id}'])
		expect(grants.rowAction).toBeUndefined()
	})

	it('hides the background service, which holds rights on every case', () => {
		expect(grants.hideWhen).toEqual({ field: 'principal.id', op: 'in', value: ['dossiq-background-service'] })
	})

	it('says where each grant came from, as the board draws it', () => {
		expect(originLine({ origin: 'rule', matchedOn: ['caseType'], role: null })).toBe('via het zaaktype')
		expect(originLine({ origin: 'rule', matchedOn: [], role: 'behandelaar' })).toBe('via de rol behandelaar')
		expect(originLine({ origin: 'rule', matchedOn: [], role: null })).toBe('via een regel op alle zaken')
		expect(originLine({ origin: 'shared', sharedBy: { id: 'pjansen', displayName: 'Pieter Jansen' } })).toBe('gedeeld door Pieter Jansen')
		expect(originLine({ origin: 'shared', sharedBy: { id: 'weg', displayName: null } })).toBe('gedeeld door een oud-gebruiker')
		expect(originLine({ origin: 'inherited', sharedBy: { id: 'pjansen', displayName: 'Pieter Jansen' } })).toBe('via de hoofdzaak, gedeeld door Pieter Jansen')
		expect(originLine({ origin: 'inherited', sharedBy: { id: 'weg', displayName: null } })).toBe('via de hoofdzaak, gedeeld door een oud-gebruiker')
		expect(originLine({ origin: 'owner' })).toBe('eigenaar van de zaak')
	})

	it('reads the English source the same way', () => {
		const texts = grants.meta.cases.map((entry) => entry.text)
		expect(texts).toContain('via the case type')
		expect(texts).toContain('shared by {sharedBy.displayName}')
	})

	it('shows when a share ends, and its own text for nobody, a refusal and a failure', () => {
		expect(grants.pill).toEqual({ cases: [], label: 'Expires {expiresAt|date}', variant: 'warning' })
		expect(nl[grants.emptyText]).toBe('Niemand anders heeft toegang tot deze zaak')
		expect(nl[grants.refusalText]).toBe('Alleen de mensen aan wie deze zaak is toegewezen zien wie toegang heeft')
		expect(nl[grants.errorText]).toBe('Kon niet lezen wie toegang heeft')
	})

	it('leaves the card\'s footnote to the card, so it ends the card once', () => {
		expect(links.footnote).toBeUndefined()
		expect(grants.footnote).toBeUndefined()
		expect(card.content.footnote).toBeTruthy()
	})
})
