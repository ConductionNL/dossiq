/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaken and DqZakenSorteren draw the handler's initials as a 28px chip,
 * in the table and in each card's footer (r32: 32px on live, the library's
 * board default).
 *
 * @spec openspec/specs/case-list-handler-chip-28/spec.md#requirement-the-case-list-handler-chip-is-28px
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

describe('the case list handler chip in the simple profile', () => {
	const built = buildProfiledManifest(buildManifest, manifest, fragments, readJson('src', 'menu-layout.simple.json'))
	const cases = built.pages.find((page) => page.id === 'Cases')

	it('draws the handler as a 28px chip', () => {
		const column = cases.config.columns.find((col) => col.key === 'assignee')
		expect(column.widget).toBe('avatar')
		expect(column.widgetProps.size).toBe(28)
	})
})
