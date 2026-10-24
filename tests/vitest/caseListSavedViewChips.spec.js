/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaken, DqZakenSorteren, DqZakenKaart, DqFilterBeheren and DqWerkbord draw
 * no row of saved-view chips under the lenses: the views are listed under
 * "Mijn weergaven" in the navigation and in the side panel. The case list
 * asks the library to leave the chips out (`savedViewChips: false`, nc-vue
 * feat/saved-views-hide-chip-rows) in both structure profiles. Save view stays.
 * A view opened from the menu names the list (DqFilterInMenu "Termijn deze
 * week", nc-vue feat/r25-saved-view-names-the-page `savedViewTitle`).
 *
 * @spec openspec/specs/my-work/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const manifest = readJson('src', 'manifest.json')
const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

describe.each(['menu-layout.json', 'menu-layout.simple.json'])('the case list in %s', (layout) => {
	const cases = buildProfiledManifest(buildManifest, manifest, fragments, readJson('src', layout))
		.pages.find((page) => page.id === 'Cases')

	it('leaves the saved-view chips out of the toolbar and keeps saved views', () => {
		expect(cases.config.savedViewChips).toBe(false)
		expect(cases.config.allowSavedViews).toBe(true)
	})

	it('counts every saved view, so the side panel shows its number (DqFilterBeheren)', () => {
		expect(cases.config.viewCounts).toBe(true)
	})

	it('heads the list with the applied saved view\'s name (DqFilterInMenu)', () => {
		expect(cases.config.savedViewTitle).toBe(true)
	})

	it('draws the board view (DqWerkbord) from the same page', () => {
		expect(cases.config.viewModes).toContain('board')
	})
})
