/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakBetrokkenen "Rollen" in the simple structure: the Rollen card of
 * `src/menu-layout.simple.json` is its own widget, not an overlay on the full
 * manifest's, so it carries the same row words: "Primaire partij · verzoeker",
 * "Geen geheimhouding" on the primary person, "Machtiging tot 31 dec" on a
 * representative and "Indicator: meerdere meldingen".
 *
 * @spec openspec/changes/case-roles-board-rows-r40/specs/case-roles-board-rows-r40/spec.md#requirement-the-roles-rows-read-as-the-board-draws-them
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (file) => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../..', file), 'utf8'))
const nl = read('l10n/nl.json').translations

const fullRoles = () => {
	const caseDetail = read('src/manifest.json').pages.find((page) => page.id === 'CaseDetail')
	const people = caseDetail.config.widgets.find((widget) => widget.id === 'case-people-panel')
	return people.content.sections.map((section) => section.widget).find((widget) => widget.id === 'case-party-roles')
}
const simpleRoles = () => read('src/menu-layout.simple.json').pages
	.find((page) => page.id === 'CaseDetail')
	.configAppend.widgets.find((widget) => widget.id === 'case-party-roles-card')

describe('the simple structure\'s Rollen rows', () => {
	it('say no secrecy on the primary person only, as the full structure does', () => {
		expect(simpleRoles().content.secrecy).toEqual(fullRoles().content.secrecy)
	})

	it('name when a representation ends, as the full structure does', () => {
		expect(simpleRoles().content.representationUntil).toBe(fullRoles().content.representationUntil)
		expect(nl[simpleRoles().content.representationUntil]).toBe('Machtiging tot {date}')
	})

	it('name the primary party, no secrecy and an indicator in the board\'s words', () => {
		const labels = simpleRoles().content.labels
		expect(nl[labels.primary]).toBe('Primaire partij')
		expect(nl[labels.noSecrecy]).toBe('Geen geheimhouding')
		expect(nl[labels.indicatorPill]).toBe('Indicator: {label}')
	})
})
