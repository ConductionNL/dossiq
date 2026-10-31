/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZakenSorteren: the cards view of the case list draws each case as the
 * record layout: the status pill on top, the title, the number and case type,
 * and a footer with the handler and the deadline.
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

describe('the case cards in the simple profile (DqZakenSorteren)', () => {
	const built = buildProfiledManifest(buildManifest, manifest, fragments, readJson('src', 'menu-layout.simple.json'))
	const config = built.pages.find((page) => page.id === 'Cases').config
	const layout = config.cardLayout

	it('heads each card with the status in its status type\'s colour', () => {
		expect(layout.headPill).toEqual({ field: 'status', resolve: { schema: 'statusType', colorField: 'colour' } })
	})

	it('reads the number and the case type under the title', () => {
		expect(layout.title).toBe('title')
		expect(layout.meta).toEqual(['identifier', { field: 'caseType', formatter: 'caseTypeName' }])
	})

	it('ends with the handler through the Handler column and the deadline', () => {
		expect(layout.footerCell).toBe('assignee')
		expect(config.columns.find((column) => column.key === 'assignee').widget).toBe('avatar')
		expect(layout.footerDate).toEqual({ field: 'deadline', overdueVariant: 'error' })
	})
})
