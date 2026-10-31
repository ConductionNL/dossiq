/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisations list opens sorted by its Name column, so the header
 * shows the sort (DqOrganisaties draws "Naam" as the sorted column). A
 * client-side `defaultSort` orders the rows but leaves the header unsorted.
 *
 * @spec exclude board sort state of one list (DqOrganisaties), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((entry) => entry.id === 'Organisations')

describe('the organisations list sort', () => {
	it('opens sorted by the Name column, ascending', () => {
		expect(page.config.sortKey).toBe('tradeName')
		expect(page.config.sortOrder).toBe('asc')
	})

	it('sorts by a column the list draws and lets people sort', () => {
		const column = page.config.columns.find((entry) => entry.key === page.config.sortKey)
		expect(column).toBeDefined()
		expect(column.sortable).not.toBe(false)
	})
})
