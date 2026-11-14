/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * r34 follow-ups on the case lists, as configuration.
 *
 * - Cases with the same deadline keep one order: the one received first
 *   comes first (`deadline,receivedAt`). On :8080 the two ties the boards
 *   draw (DqZakenSorteren, design-system #741) are 2026-4865 before 2026-4866
 *   and 2026-4845 before 2026-4838: the identifier gives the first pair
 *   ascending and the second descending, so neither direction holds both;
 *   receivedAt ascending holds both.
 * - The team queue's deadline reads late under five days left and soon up
 *   to seven (DqTeamwachtrij "nog 4 dagen" red, "nog 6 dagen" amber).
 * - The map's missing-address card counts cases ("8 zaken zonder adres").
 * - The cards view's footer says how the cards are sorted and where to
 *   change it (DqZakenSorteren).
 *
 * @spec openspec/specs/case-search-and-lists/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const manifest = read('src/manifest.json')
const simple = read('src/menu-layout.simple.json')
const nl = read('l10n/nl.json').translations
const cases = manifest.pages.find((page) => page.id === 'Cases').config
const simpleCases = simple.pages.find((page) => page.id === 'Cases').config
const queue = cases.defaultSavedViews.find((view) => view.slug === 'team-queue').overrides

/** The four tied cases as :8080 answered them on 28 Oct (read only). */
const tied = [
	{ identifier: '2026-4866', deadline: '2026-11-03', receivedAt: '2026-10-24T08:05:00+00:00' },
	{ identifier: '2026-4838', deadline: '2026-11-18', receivedAt: '2026-10-25T15:40:00+00:00' },
	{ identifier: '2026-4865', deadline: '2026-11-03', receivedAt: '2026-10-23T12:40:00+00:00' },
	{ identifier: '2026-4845', deadline: '2026-11-18', receivedAt: '2026-10-25T10:00:00+00:00' },
]

/**
 * Sort rows by an order string such as "deadline,receivedAt" or "-identifier".
 *
 * @param {Array<object>} rows The rows.
 * @param {string} order The order.
 * @return {string[]} The identifiers in order.
 */
function orderBy(rows, order) {
	const keys = order.split(',').map((part) => (part.startsWith('-') ? [part.slice(1), -1] : [part, 1]))
	return [...rows].sort((a, b) => {
		for (const [key, dir] of keys) {
			if (a[key] !== b[key]) {
				return (a[key] < b[key] ? -1 : 1) * dir
			}
		}
		return 0
	}).map((row) => row.identifier)
}

const drawn = ['2026-4865', '2026-4866', '2026-4845', '2026-4838']

describe('cases with the same deadline', () => {
	it('come in the order the boards draw only by receivedAt, not by identifier', () => {
		expect(orderBy(tied, 'deadline,receivedAt')).toEqual(drawn)
		expect(orderBy(tied, 'deadline,identifier')).not.toEqual(drawn)
		expect(orderBy(tied, 'deadline,-identifier')).not.toEqual(drawn)
	})

	it('are ordered so in every deadline order the case list declares', () => {
		expect(simpleCases.defaultOrder).toBe('deadline,receivedAt')
		expect(cases.sortSelectOptions.find((option) => option.value === 'deadline').order).toBe('deadline,receivedAt')
		for (const file of ['lib/Settings/register.d/81-woo-verzoek.json', 'lib/Settings/register.d/81-woo-verzoek-version-2.json']) {
			const text = fs.readFileSync(path.join(ROOT, file), 'utf8')
			expect(text).toMatch(/"defaultSort": \[\s*\{\s*"key": "deadline",\s*"order": "asc"\s*\},\s*\{\s*"key": "receivedAt",\s*"order": "asc"\s*\}\s*\]/)
		}
	})
})

describe('the team queue deadline', () => {
	it('is late under five days and soon up to seven', () => {
		const rules = [{ op: 'lt', value: 5, variant: 'error' }, { op: 'lte', value: 7, variant: 'warning' }]
		expect(queue.columns.find((column) => column.key === 'deadline').widgetProps.variantWhen).toEqual(rules)
		expect(queue.listConfig.trailing.find((cell) => cell.key === 'deadline').widgetProps.variantWhen).toEqual(rules)
	})
})

describe('the map and the cards footer', () => {
	it('counts cases without an address', () => {
		const title = simpleCases.mapConfig.list.missingTitle
		expect(title).toEqual({ singular: '{total} case without an address', plural: '{total} cases without an address' })
		expect(nl[`_${title.singular}_::_${title.plural}_`]).toEqual(['{total} zaak zonder adres', '{total} zaken zonder adres'])
	})

	it('says how the cards are sorted', () => {
		const note = simpleCases.footerNoteByView.cards
		expect(nl[note]).toBe('gesorteerd op termijn, oplopend · sorteren via het zijpaneel · 20 per pagina')
	})
})
