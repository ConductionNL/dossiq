/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list places its quick filter chips per view, as each board draws
 * them: beside the Filter button in the board and map views (DqWerkbord,
 * DqZakenKaart), on their own row in the table and cards views (DqZaken,
 * DqZakenSorteren). Needs nextcloud-vue `quickFilterPlacementByView`.
 *
 * @spec exclude board layout: DqWerkbord, DqZakenKaart and DqZaken are the specification
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = manifest.pages.find((entry) => entry.id === 'Cases')

describe('case list chips per view', () => {
	it('puts the chips on the toolbar row in the board and map views', () => {
		expect(page.config.quickFilterPlacementByView).toEqual({ board: 'toolbar', map: 'toolbar' })
	})

	it('leaves the table and cards views on the page placement', () => {
		expect(page.config.quickFilterPlacement).toBeUndefined()
	})
})
