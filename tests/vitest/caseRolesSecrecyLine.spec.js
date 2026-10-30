/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Roles section of a case asks the parties widget for the secrecy line:
 * a person party without the geheimhouding indicator reads "Geen geheimhouding".
 *
 * @spec openspec/specs/case-roles-no-secrecy-line/spec.md#requirement-a-person-on-a-case-says-whether-it-has-secrecy
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(
	fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'),
)

describe('the secrecy line on a case\'s parties', () => {
	it('asks for it on person parties, keyed by the indicator the party records carry', () => {
		const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
		const people = caseDetail.config.widgets.find((widget) => widget.id === 'case-people-panel')
		const roles = people.content.sections.map((section) => section.widget).find((widget) => widget.id === 'case-party-roles')

		expect(roles.content.secrecy).toEqual({ indicator: 'geheimhouding', kinds: ['person'] })
	})
})
