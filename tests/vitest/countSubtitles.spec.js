/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The boards put a list's counts under its card title: DqZaakContact
 * "4 vastgelegd", DqContact and DqOrganisatie "3 in totaal", DqTenant
 * "5 van 7 stappen klaar", DqTaak "2 van 4 klaar". The lists say them as
 * their header subtitle.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const full = read('src/manifest.json')
const simple = read('src/menu-layout.simple.json')
const organisations = read('src/manifest.d/67-environment-organisations.json')

/**
 * Every widget with this id under a manifest.
 *
 * @param {object} node The manifest or a part of it.
 * @param {string} id The widget id.
 * @return {Array<object>} The widgets.
 */
function widgets(node, id) {
	if (Array.isArray(node)) {
		return node.flatMap((item) => widgets(item, id))
	}
	if (node && typeof node === 'object') {
		const own = node.id === id && node.type ? [node] : []
		return own.concat(Object.values(node).flatMap((value) => widgets(value, id)))
	}
	return []
}

describe('the count subtitles', () => {
	it('the latest contact moments say their total at the right of the title, on contacts and organisations', () => {
		const lists = widgets(full, 'contact-latest-moments')
		expect(lists).toHaveLength(2)
		for (const list of lists) {
			expect(nl[list.content.headerNote]).toBe('{total} in totaal')
			expect(list.content.headerSubtitle).toBeUndefined()
		}
	})

	it('the case\'s contact moments say how many are recorded, beside the title as the board draws it', () => {
		const [list] = widgets(simple, 'case-contact-moments-card')
		expect(nl[list.content.headerNote]).toBe('{total} vastgelegd')
		expect(list.content.headerSubtitle).toBeUndefined()
	})

	it('the set-up steps say how many are done, at the right of the title as the board draws it', () => {
		const [list] = widgets(organisations, 'organisation-onboarding')
		expect(nl[list.content.headerNote]).toBe('{count:status=completed} van {total} stappen klaar')
		expect(list.content.headerSubtitle).toBeUndefined()
	})

	it('the task checklist puts "2 of 4 done" under its title', () => {
		const [list] = widgets(full, 'task-checklist')
		expect(list.content.summaryInHeader).toBe(true)
	})
})
