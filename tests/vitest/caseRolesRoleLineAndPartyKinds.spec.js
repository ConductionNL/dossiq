/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakBetrokkenen draws different words for one party in its two
 * cards. Partijen prints the person's details from the party link's note,
 * led by the word for the person's kind ("Contact · Lindelaan 14 · ingelogd
 * met DigiD", "Organisatie · KvK ...", "Collega · ..."). Rollen prints the
 * role line from the role record's description ("Gemachtigde namens ... ·
 * Awb 2:1"). Both structures, full and simple, declare the same.
 *
 * @spec openspec/changes/case-roles-role-line-and-party-kinds/specs/case-roles-role-line-and-party-kinds/spec.md#requirement-each-people-card-reads-the-field-meant-for-it
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (file) => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../..', file), 'utf8'))
const nl = read('l10n/nl.json').translations

function fullSections() {
	const caseDetail = read('src/manifest.json').pages.find((page) => page.id === 'CaseDetail')
	const people = caseDetail.config.widgets.find((widget) => widget.id === 'case-people-panel')
	return people.content.sections.map((section) => section.widget)
}
function simpleWidgets() {
	return read('src/menu-layout.simple.json').pages
		.find((page) => page.id === 'CaseDetail')
		.configAppend.widgets
}

const structures = {
	full: () => ({ roles: fullSections().find((w) => w.id === 'case-party-roles'), parties: fullSections().find((w) => w.id === 'case-parties') }),
	simple: () => ({ roles: simpleWidgets().find((w) => w.id === 'case-party-roles-card'), parties: simpleWidgets().find((w) => w.id === 'case-parties-card') }),
}

describe.each(Object.keys(structures))('the %s structure\'s people cards', (name) => {
	it('Rollen reads the role record\'s description, not the link note Partijen prints', () => {
		const { roles } = structures[name]()
		expect(roles.content.rolesObjects).toMatchObject({ register: 'dossiq', schema: 'role' })
		expect(roles.content.note).toEqual({ from: 'roles', field: 'description' })
	})

	it('Partijen leads a person\'s details with the word for their kind, in the board\'s Dutch', () => {
		const { parties } = structures[name]()
		const words = parties.labels.kindLabels
		expect(nl[words.person]).toBe('Contact')
		expect(nl[words.organisation]).toBe('Organisatie')
		expect(nl[words.colleague]).toBe('Collega')
		expect(nl[words.user]).toBe('Collega')
		expect(nl[words.contact]).toBe('Contact')
	})
})
