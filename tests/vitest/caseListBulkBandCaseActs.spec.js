/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaken, DqZakenSorteren and DqFilterInMenu: the bulk band offers the case
 * acts only (another handler, another status, suspend, resume, extend the
 * term). Copying and deleting the selection stay reachable in the header's
 * Actions menu.
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

describe('the case list in the simple profile', () => {
	const built = buildProfiledManifest(buildManifest, manifest, fragments, readJson('src', 'menu-layout.simple.json'))
	const cases = built.pages.find((page) => page.id === 'Cases')

	it('leaves copy and delete out of the bulk band', () => {
		expect(cases.config.showMassCopy).toBe(false)
		expect(cases.config.showMassDelete).toBe(false)
	})

	it('keeps copy and delete in the Actions menu', () => {
		const menu = cases.config.headerButtons.find((button) => button.action === 'actions-menu')
		expect(menu.items).toEqual(expect.arrayContaining(['copy-selected', 'delete-selected']))
	})
})
