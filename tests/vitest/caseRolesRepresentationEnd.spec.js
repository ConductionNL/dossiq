/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakBetrokkenen "Rollen": a party that acts for another ends its row
 * with "Machtiging tot 31 dec", the end of its link.
 *
 * @spec openspec/changes/case-roles-representation-end/specs/case-roles-representation-end/spec.md#requirement-a-representative-on-a-case-shows-when-the-representation-ends
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (file) => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../..', file), 'utf8'))
const manifest = read('src/manifest.json')

describe('the end of a representation on a case\'s parties', () => {
	it('asks the parties widget for the pill, in words Dutch readers get as "Machtiging tot"', () => {
		const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
		const people = caseDetail.config.widgets.find((widget) => widget.id === 'case-people-panel')
		const roles = people.content.sections.map((section) => section.widget).find((widget) => widget.id === 'case-party-roles')

		expect(roles.content.representationUntil).toBe('Authorisation until {date}')
		expect(read('l10n/nl.json').translations['Authorisation until {date}']).toBe('Machtiging tot {date}')
	})
})
