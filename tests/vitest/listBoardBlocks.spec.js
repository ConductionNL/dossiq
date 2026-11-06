/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The list and work pages carry the blocks their boards draw.
 *
 * - DqPostvak: the header holds Download and Actions.
 * - DqAanMijToegewezen: the header holds Actions and New case, no Download.
 * - DqMijnTeam: the team's queue reads as rows with a Pick up button, not as
 *   a table with column headings.
 *
 * @spec openspec/specs/my-work/spec.md
 * @spec openspec/specs/my-work-landing/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { pageView } from './helpers/pageViews.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const manifest = readJson('src', 'manifest.json')
const fragmentPages = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.flatMap((name) => readJson('src', 'manifest.d', name).pages || [])
const pages = [...manifest.pages, ...fragmentPages]
const page = (id) => pages.find((item) => item.id === id)

const actionsOf = (buttons) => (buttons || []).map((button) => button.action)

describe('DqPostvak', () => {
	it('offers Download and the Actions menu in the header', () => {
		expect(actionsOf(page('Postvak').config.headerButtons)).toEqual(['export', 'actions-menu'])
	})
})

describe('DqAanMijToegewezen', () => {
	it('offers Actions and New case in the header, without Download', () => {
		const view = page('Cases').config.defaultSavedViews.find((item) => item.slug === 'my-work')
		expect(actionsOf(view.overrides.headerButtons)).toEqual(['actions-menu', 'add'])
	})
})

describe('DqMijnTeam', () => {
	const queue = pageView(page('MyWorkHome'), 'team').widgets.find((widget) => widget.id === 'your-teams-queue')

	it('lists the team queue as rows, not as a table with headings', () => {
		expect(queue.type).toBe('object-list')
		expect(queue.content.hideHeader).toBe(true)
		expect(queue.content.columns.map((column) => column.key)).toEqual(['title'])
		expect(queue.content.columns[0].secondary).toContain('{identifier}')
		expect(queue.content.columns[0].secondary).toContain('{deadline|date}')
	})

	it('lets a reader pick a case up from the row', () => {
		expect(queue.content.rowActions).toEqual([
			expect.objectContaining({ id: 'claim', label: 'Pick up', handler: 'claimCase', type: 'handler' }),
		])
	})
})
