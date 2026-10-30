/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakBetrokkenen "Rollen" (design-system #811): "Primaire partij ·
 * verzoeker", "Geen geheimhouding" on the primary person only, and the indicator
 * as "Indicator: meerdere meldingen".
 *
 * @spec openspec/changes/case-roles-board-rows-r40/specs/case-roles-board-rows-r40/spec.md#requirement-the-roles-rows-read-as-the-board-draws-them
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (file) => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../..', file), 'utf8'))
const manifest = read('src/manifest.json')
const nl = read('l10n/nl.json').translations

describe('the Roles rows as the board draws them', () => {
	const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
	const people = caseDetail.config.widgets.find((widget) => widget.id === 'case-people-panel')
	const roles = people.content.sections.map((section) => section.widget).find((widget) => widget.id === 'case-party-roles')

	it('says no secrecy on the primary person only', () => {
		expect(roles.content.secrecy).toEqual({ indicator: 'geheimhouding', kinds: ['person'], primaryOnly: true })
		expect(nl[roles.content.labels.noSecrecy]).toBe('Geen geheimhouding')
	})

	it('names the primary party and an indicator in the board\'s words', () => {
		expect(nl[roles.content.labels.primary]).toBe('Primaire partij')
		expect(nl[roles.content.labels.indicatorPill]).toBe('Indicator: {label}')
	})
})
