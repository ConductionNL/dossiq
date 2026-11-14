/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakBesluiten Adviezen: the advice requests as rows, each with the
 * advisor's initials, the subject and advisor, when it was asked and when the
 * answer is due or came in, the status pill, and "Herinneren" on an open
 * request only. "Advies vragen" in the title row, and the line that an open
 * request holds the case back under the list.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const caseDetail = simple.pages.find((entry) => entry.id === 'CaseDetail')
const advice = caseDetail.configAppend.widgets.find((widget) => widget.id === 'case-advice-requests')

describe('DqZaakBesluiten Adviezen as rows', () => {
	it('is a record list of the case\'s advice requests, drawn as rows with the advisor\'s initials', () => {
		expect(advice.type).toBe('record-list')
		expect(advice.content).toMatchObject({ register: 'dossiq', schema: 'adviesAanvraag', filter: { case: '@objectId' }, presentation: 'rows', avatar: { text: '{advisor}', size: 32 }, rowRoute: 'AdviceDetail' })
	})

	it('rules above the first row too, under the title, as the board draws it', () => {
		expect(advice.content.ruleAbove).toBe(true)
	})

	it('tints an open request\'s initials amber and gives Herinneren its bell, as the board', () => {
		expect(advice.content.avatar.tone).toEqual({ field: 'status', map: { requested: 'warning' } })
		expect(advice.content.rowAction.icon).toBe('BellOutline')
	})

	it('keeps the status tones: open amber, received green, expired red', () => {
		expect(advice.content.pill.field).toBe('status')
		expect(advice.content.pill.colorMap).toEqual({ requested: 'warning', received: 'success', expired: 'error' })
		expect(nl[advice.content.pill.labels.requested]).toBe('Open')
	})

	it('reminds an open request only, through dossiq\'s remind route', () => {
		const remind = advice.content.rowAction
		expect(remind.variant).toBe('outlined')
		expect(nl[remind.label]).toBe('Herinneren')
		expect(remind.endpoint).toBe('/apps/dossiq/api/advice/{id}/remind')
		expect(routes).toContain("'url' => '/api/advice/{id}/remind'")
		expect(remind.labels).toEqual({ field: 'status', map: { received: '', expired: '' } })
	})

	it('asks for advice from the title row and says an open request holds the case back', () => {
		const ask = advice.headerActions[0]
		expect(nl[ask.label]).toBe('Advies vragen')
		expect(ask.action).toMatchObject({ type: 'open-form', schema: 'adviesAanvraag' })
		expect(nl[advice.content.footnote]).toBe('Zolang een advies open staat, kan de zaak niet worden afgerond.')
	})
})
