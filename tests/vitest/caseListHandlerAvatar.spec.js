/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list boards (DqZaken, DqZakenSorteren, the DqFilter boards) draw
 * the handler's initials in a 28px circle; the board look's default avatar
 * is 32px (r32 DqFilterBeheren "PJ" h 28>32).
 *
 * @spec openspec/specs/case-management/spec.md
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

describe('the case list handler column', () => {
	const built = buildProfiledManifest(buildManifest, manifest, fragments, readJson('src', 'menu-layout.simple.json'))
	const cases = built.pages.find((page) => page.id === 'Cases')

	it('draws the handler avatar 28px', () => {
		const column = cases.config.columns.find((col) => col && col.key === 'assignee')
		expect(column.widget).toBe('avatar')
		expect(column.widgetProps.size).toBe(28)
	})
})
