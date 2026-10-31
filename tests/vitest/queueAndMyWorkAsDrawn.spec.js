/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * r34 DqTeamwachtrij and DqAanMijToegewezen, as manifest config.
 *
 * - The team queue's list columns are the board's: the wait 60px, the type
 *   172px, the deadline 96px; the title column takes the rest.
 * - Assigned to me reads "10 of 10 · sorted by urgency" on its footer and
 *   explains the urgency in an info notice under the cards, on the page ground.
 * - Dossiq's index pages put the footer 20px under a card grid, as the boards
 *   do (nextcloud-vue `--cn-board-pager-gap`).
 *
 * @spec openspec/specs/case-search-and-lists/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const views = manifest.pages.find((page) => page.id === 'Cases').config.defaultSavedViews
const queue = views.find((view) => view.slug === 'team-queue').overrides
const myWork = views.find((view) => view.slug === 'my-work').overrides
const css = fs.readFileSync(path.join(ROOT, 'src/assets/app.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

describe('the team queue list columns', () => {
	it('are 60px, 172px and 96px wide', () => {
		expect(queue.listConfig.leading.width).toBe('60px')
		expect(queue.listConfig.trailing.map((cell) => [cell.key, cell.width])).toEqual([['caseType', '172px'], ['deadline', '96px']])
	})
})

describe('assigned to me', () => {
	it('notes the order on the footer', () => {
		expect(myWork.footerNote).toBe('sorted by urgency')
		expect(nl['sorted by urgency']).toBe('gesorteerd op urgentie')
	})

	it('explains the urgency in an info notice under the cards, on the page ground', () => {
		expect(myWork.notice.variant).toBe('info')
		expect(myWork.notice.placement).toBe('page')
		expect(nl[myWork.notice.text]).toBe('De urgentie volgt uit de termijn, de prioriteit en hoe lang een zaak stil ligt. Werk van een collega voor wie u waarneemt staat erbij met zijn naam, zolang de waarneming loopt.')
	})
})

describe('the footer under a card grid', () => {
	it('stands 20px under the cards on dossiq index pages', () => {
		expect(css).toMatch(/\.cn-index-page\.cn-look-board\s*\{[^}]*--cn-board-pager-gap:\s*20px;/)
	})
})
