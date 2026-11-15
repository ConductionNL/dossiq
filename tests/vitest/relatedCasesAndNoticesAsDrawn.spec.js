/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakGerelateerd and DqTermijnen as drawn: the related-case groups headed by
 * what the other case is to this one ("Onderwerp van deze zaak", "Wacht op")
 * whatever label the relation type reads, the planned rows' wording and
 * two-letter initials, and the notices table's case as a link.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'))
const layout = read('src/menu-layout.simple.json')
const manifest = read('src/manifest.json')
const nl = read('l10n/nl.json').translations

/**
 * The first object at any depth whose id is `id`.
 *
 * @param {unknown} node A node.
 * @param {string} id The id.
 * @return {object|null}
 */
function byId(node, id) {
	if (node === null || typeof node !== 'object') {
		return null
	}
	if (!Array.isArray(node) && node.id === id) {
		return node
	}
	for (const child of Object.values(node)) {
		const hit = byId(child, id)
		if (hit !== null) {
			return hit
		}
	}
	return null
}

describe('related cases as drawn', () => {
	const content = byId(layout, 'case-related-cases-card').content

	it('heads a group by what the other case is to this one, for the label the relation type reads', () => {
		const group = content.valueLabels.group
		expect(nl[group['gaat over']]).toBe('Onderwerp van deze zaak')
		expect(nl[group['is about']]).toBe('Onderwerp van deze zaak')
		expect(nl[group['wacht op']]).toBe('Wacht op')
		expect(nl[group['waits on']]).toBe('Wacht op')
	})

	it('words a planned flow and a series as the board does', () => {
		const texts = content.meta.cases.map((entry) => entry.text[0])
		expect(texts.map((text) => nl[text])).toEqual([
			'Geplande flow · maakt op {date|date} een zaak aan',
			'Reeks · volgende op {date|date} · {occurrences} zaken eerder geopend',
			'Reeks · volgende op {date|date} · 1 zaak eerder geopend',
			'Reeks · volgende op {date|date}',
		])
		expect(content.meta.cases[0].when).toEqual({ field: 'plannedKind', value: 'flow' })
	})

	it('draws two letters for a one-word case type and the kind of a planned row', () => {
		expect(content.avatar.letters).toBe(2)
		expect(content.avatar.text).toEqual(['{caseTypeTitle}', '{plannedKind}'])
	})
})

describe('notices of default as drawn', () => {
	it('links the case in its first column', () => {
		const column = byId(manifest, 'deadline-report-notices').content.columns[0]
		expect(column.key).toBe('caseLabel')
		expect(column.widget).toBe('link')
	})
})
