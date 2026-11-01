/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakBetrokkenen "Toegang" on the simple People tab: the case's access
 * links as rows ("Link voor Sanne de Vries", 32px initials, what the holder
 * may do, "Verloopt 15 nov"), a revoke icon button on a live link, and
 * "Delen" opening the sidebar Sharing tab, which keeps the whole share flow.
 *
 * @spec openspec/changes/people-tab-access-card/specs/case-page/spec.md#requirement-the-simple-people-tab-lists-the-cases-access-links
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
const icons = fs.readFileSync(path.join(ROOT, 'src/icons.js'), 'utf8')

function strings(content) {
	return [
		card.title,
		card.subtitle,
		card.headerActions[0].label,
		content.title,
		content.meta,
		content.pill.label,
		...content.pill.cases.map((entry) => entry.label),
		...Object.values(content.valueLabels.capabilities),
		content.rowAction.label,
		content.rowAction.errorText,
		content.emptyText,
		content.errorText,
		content.footnote,
	]
}

describe('the Toegang card', () => {
	it('reads the links the sidebar Sharing tab reads', () => {
		expect(card.type).toBe('record-list')
		expect(card.content.endpointSource).toEqual({ url: '/apps/dossiq/api/access-links/case/@objectId', responsePath: 'results' })
	})

	it('draws the board words', () => {
		expect(nl[card.title]).toBe('Toegang')
		expect(nl[card.subtitle]).toBe('Wie deze zaak mag openen, en waarom')
		expect(nl[card.headerActions[0].label]).toBe('Delen')
		expect(nl[card.content.title].replace('{label}', 'Sanne de Vries')).toBe('Link voor Sanne de Vries')
		expect(nl[card.content.meta].replace('{capabilities}', nl[card.content.valueLabels.capabilities.read])).toBe('Alleen lezen, zonder account · de link kan eindigen')
		expect(nl[card.content.pill.label]).toBe('Verloopt {expiresAt|date}')
		expect(nl[card.content.footnote]).toBe('Wie het BSN of een ander gevoelig veld leest, komt in het verwerkingenlog.')
	})

	it('puts the holder\'s initials in a 32px circle', () => {
		expect(card.content.avatar).toEqual({ text: '{label}', size: 32 })
	})

	it('revokes a live or paused link with an icon button, and not a revoked or expired one', () => {
		const action = card.content.rowAction
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
		for (const text of strings(card.content)) {
			expect(en[text]).toBe(text)
			expect(typeof nl[text]).toBe('string')
			expect(nl[text]).not.toBe('')
			expect(`${text} ${nl[text]}`).not.toContain('\u2014')
		}
	})
})
