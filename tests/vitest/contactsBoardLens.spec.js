/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqContacten: the lens on the municipality reads "Woonplaats Zuiddrecht",
 * and the list opens sorted by its Naam column, ascending, so the header
 * shows the sort.
 *
 * @spec exclude board wording and sort state of one list (DqContacten), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const page = manifest.pages.find((entry) => entry.id === 'Contacts')

describe('the contacts list', () => {
	it('names the municipality lens as the board does', () => {
		const lens = page.config.quickFilters.find((entry) => entry.filter && entry.filter['residence.city'])
		expect(nl[lens.label]).toBe('Woonplaats @config.municipalityCity')
		expect(nl[lens.activeLabel]).toBe('Woonplaats: @config.municipalityCity')
	})

	it('opens sorted by the Name column, ascending, a column people may sort', () => {
		expect(page.config.sortKey).toBe('displayName')
		expect(page.config.sortOrder).toBe('asc')
		const column = page.config.columns.find((entry) => entry.key === page.config.sortKey)
		expect(column).toBeDefined()
		expect(column.sortable).not.toBe(false)
	})
})
