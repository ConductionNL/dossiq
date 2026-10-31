/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaken, DqZakenSorteren, DqFilterBeheren and DqWerkbord draw the bulk band
 * ("Met de geselecteerde zaken") before anything is selected, its buttons
 * waiting for a selection. The case list asks the library for that
 * (`bulkBandAlways`) in both structure profiles.
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md
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
	const built = buildProfiledManifest(buildManifest, manifest, fragments, readJson('src', layout))
	const cases = built.pages.find((page) => page.id === 'Cases')

	it('draws the bulk band before a selection', () => {
		expect(cases.config.bulkBandAlways).toBe(true)
		expect(cases.config.bulkActions.length).toBeGreaterThan(0)
	})
})
