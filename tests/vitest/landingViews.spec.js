/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The landing page holds two views, My work and My team.
 *
 * Ruben, 7 October 2026: the greeting's My work / My team switch opened two
 * other pages, and below the greeting the landing page drew an empty area.
 * The switch now changes views of the landing page itself (nextcloud-vue
 * 2.66.0 page views). This file holds the full structure, which the manifest
 * declares; `simpleListAndDashboard.spec.js` holds the simple one.
 *
 * Every check here is against the thing it names: a view that names a widget
 * it does not declare leaves a hole, and a team widget copied from the
 * dashboard can drift from it without anybody noticing.
 *
 * @spec openspec/specs/my-work-landing/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { spawnSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'
import { pageGrids, pageView } from './helpers/pageViews.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const manifest = readJson('src', 'manifest.json')
const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
function build(file) {
	return buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', file),
	)
}
const builtFull = build('menu-layout.json')
const builtSimple = build('menu-layout.simple.json')

const page = (pages, id) => pages.find((item) => item.id === id)
const home = page(manifest.pages, 'MyWorkHome')
const dashboard = page(manifest.pages, 'Dashboard')

/**
 * A definition without its `_` notes, which a copy leaves behind.
 *
 * @param {object} definition A widget definition.
 * @return {object} The same definition, notes left out.
 */
function withoutNotes(definition) {
	return JSON.parse(
		JSON.stringify(definition, (key, value) =>
			key.startsWith('_') ? undefined : value,
		),
	)
}

describe('the landing page in the full structure', () => {
	it('is still the page at the root of the app', () => {
		expect(home.route).toBe('/')
		expect(home.type).toBe('dashboard')
	})

	it('declares My work and My team, and opens on My work', () => {
		expect(home.config.views.map((view) => [view.id, view.label])).toEqual([
			['mine', 'My work'],
			['team', 'My team'],
		])
		expect(home.config.defaultView).toBe('mine')
		// DqMijnWerk and DqMijnTeam name the switch "Weergave".
		expect(home.config.viewsLabel).toBe('Display')
	})

	it('keeps its own grid empty, so the switch sits in the page header', () => {
		// A greeting with view options is what moves the switch out of the
		// header; the full structure has none, so the page draws it.
		expect(home.config.widgets ?? []).toEqual([])
		expect(home.config.layout ?? []).toEqual([])
	})

	it('shows in My work what the landing page showed before', () => {
		expect(pageView(home, 'mine').widgets.map((widget) => widget.id)).toEqual([
			'my-work',
			'deadlines',
			'open-cases',
			'followed-cases',
			'archival-reviews',
		])
	})

	it('shows in My team widgets the dashboard already declares, unchanged', () => {
		const team = pageView(home, 'team').widgets
		expect(team.map((widget) => widget.id)).toEqual([
			'your-teams-queue',
			'kpi-open-cases',
			'kpi-overdue',
			'kpi-completed',
			'cases-by-status',
			'cases-by-type',
			'stalled-cases',
		])
		for (const widget of team.slice(1)) {
			const source = dashboard.config.widgets.find(
				(item) => item.id === widget.id,
			)
			expect(widget, widget.id).toEqual(withoutNotes(source))
		}
		// The team queue asks what the dashboard's own preset asks: the team
		// queue view's filter narrowed to the reader's own teams
		// (my-teams-queue). DqMijnTeam draws it as rows with a Pick up
		// button, so its columns and row actions are its own.
		const preset = dashboard.config.userWidgets.find(
			(item) => item.id === 'your-teams-queue',
		)
		expect(team[0].type).toBe(preset.widget.type)
		expect(team[0].content.filter).toEqual(preset.widget.content.filter)
		expect(team[0].content.filter).toEqual({
			...page(manifest.pages, 'Cases').config.defaultSavedViews.find((view) => view.slug === 'team-queue').overrides.lockedFilters,
			assignedGroup: '@myGroups',
		})
		// The deadline reads as a date, not as the stored ISO string
		// (my-work-cards-and-team-tiles-read-right, finding F3).
		expect(team[0].content.columns[0].secondary).toContain('{deadline|date}')
	})

	it('fits the team cards to their content, as DqMijnTeam draws them', () => {
		// A grid that floats keeps every card on its row, so a card that
		// shrinks would leave a band under it; this one packs upward.
		expect(home.config.gridFloat).toBe(false)
		expect(page(builtSimple.pages, 'MyWorkHome').config.gridFloat).toBe(false)
		const layout = pageView(home, 'team').layout
		for (const id of ['t4', 't5', 't6', 't7']) {
			expect(layout.find((item) => item.id === id).sizeToContent, id).toBe(true)
		}
	})

	it('gives My team a sentence for when it has nothing to draw', () => {
		expect(pageView(home, 'team').emptyText).toBeTruthy()
	})
})

describe('My team in the simple structure', () => {
	const team = pageView(page(builtSimple.pages, 'MyWorkHome'), 'team')
	const fullTeam = pageView(home, 'team')

	it('draws the stalled cases as DqMijnTeam\'s rows: title, number and handler, days idle at the end, no chevron', () => {
		const stalled = team.widgets.find((widget) => widget.id === 'stalled-cases')
		const table = dashboard.config.widgets.find((widget) => widget.id === 'stalled-cases')
		expect(stalled.type).toBe('record-list')
		expect(stalled.content).toMatchObject({
			register: table.content.source.register,
			schema: table.content.source.schema,
			filter: table.content.source.filter,
			extend: ['calculations'],
			sort: { field: 'lastActivityAt', dir: 'asc' },
			limit: 3,
			presentation: 'rows',
			title: '{title}',
			meta: { parts: ['{identifier}', '{assignee|user}'] },
			trailing: ['{daysSinceActivity} days idle'],
			chevron: false,
			rowRoute: 'CaseDetail',
		})
		const { name, ...rest } = table.content.viewAllRoute
		expect(stalled.headerLink).toEqual({ label: 'View all', route: name, ...rest })
	})

	it('draws the team queue as DqMijnTeam\'s rows: number, case type and deadline, Pick up at the end', () => {
		const queue = team.widgets.find((widget) => widget.id === 'your-teams-queue')
		const full = fullTeam.widgets.find((widget) => widget.id === 'your-teams-queue')
		expect(queue.type).toBe('record-list')
		expect(queue.content).toMatchObject({
			register: full.content.register,
			schema: full.content.schema,
			filter: full.content.filter,
			extend: ['caseType'],
			limit: 3,
			presentation: 'rows',
			title: '{title}',
			meta: { parts: ['{identifier}', '{caseType.title}', 'deadline {deadline|date}'] },
			rowAction: { id: 'claim', label: 'Pick up', type: 'handler', handler: 'claimCase' },
			chevron: false,
			rowRoute: 'CaseDetail',
			emptyText: full.content.emptyText,
		})
		expect(queue.headerLink).toEqual({ label: 'To the queue', route: 'Cases', query: { savedView: 'default:team-queue' } })
	})

	it('keeps every other team widget as the full structure declares it', () => {
		expect(team.widgets.map((widget) => widget.id)).toEqual(fullTeam.widgets.map((widget) => widget.id))
		for (const widget of team.widgets.filter((item) => !['stalled-cases', 'your-teams-queue'].includes(item.id))) {
			expect(withoutNotes(widget), widget.id).toEqual(withoutNotes(fullTeam.widgets.find((item) => item.id === widget.id)))
		}
		expect(team.layout).toEqual(fullTeam.layout)
	})
})

describe('My work in the simple structure keeps the board\'s side column', () => {
	it('draws the side cards 320px wide and the cards beside them in the rest, the tiles untouched', () => {
		// DqMijnWerk: Mijn taken and Recente activiteit 320px, Termijnen deze
		// week, per stap and Verder werken filling the rest; the four counts and
		// the two half-width cards keep their twelve shares.
		const mine = pageView(page(builtSimple.pages, 'MyWorkHome'), 'mine')
		expect(mine.gridSideColumn).toEqual({ width: 320 })
		const tracks = Object.fromEntries(mine.layout.map((cell) => [cell.widgetId, cell.track]))
		expect(tracks).toMatchObject({
			'simple-week': 'main',
			'simple-per-step': 'main',
			'simple-continue': 'main',
			'simple-my-tasks': 'side',
			'simple-recent-activity': 'side',
		})
		for (const cell of mine.layout) {
			if (cell.track === 'main') {
				expect(cell.gridX + cell.gridWidth, cell.widgetId).toBeLessThanOrEqual(8)
			} else if (cell.track === 'side') {
				expect(cell.gridX, cell.widgetId).toBeGreaterThanOrEqual(8)
			} else {
				expect(cell.track, cell.widgetId).toBeUndefined()
			}
		}
		expect(tracks['simple-my-open']).toBeUndefined()
		expect(tracks['archival-reviews']).toBeUndefined()
	})

	it('fits every card under First today to its content, as DqMijnWerk draws them', () => {
		// The board's counts are 110px, not two grid rows (136), and each card
		// below ends where its content ends, so the next one closes up.
		expect(page(builtSimple.pages, 'MyWorkHome').config.gridFloat).toBe(false)
		const mine = pageView(page(builtSimple.pages, 'MyWorkHome'), 'mine')
		for (const cell of mine.layout) {
			expect(cell.sizeToContent, cell.widgetId).toBe(true)
		}
	})
})

describe('no view is empty, in either structure', () => {
	it.each([
		['full', builtFull],
		['simple', builtSimple],
	])(
		'%s: every view places widgets it declares, without overlap',
		(name, built) => {
			const landing = page(built.pages, 'MyWorkHome')
			const views = pageGrids(landing).filter((g) => g.id !== 'page')
			// Without views the loop below checks nothing, so say how many.
			expect(views.map((g) => g.id)).toEqual(['mine', 'team'])
			for (const grid of views) {
				expect(grid.layout.length, `${name}/${grid.id}`).toBeGreaterThan(0)
				const ids = new Set(grid.widgets.map((widget) => widget.id))
				const cells = new Set()
				for (const item of grid.layout) {
					expect(
						ids.has(item.widgetId),
						`${name}/${grid.id}: ${item.widgetId}`,
					).toBe(true)
					expect(item.gridX + item.gridWidth).toBeLessThanOrEqual(12)
					for (let x = item.gridX; x < item.gridX + item.gridWidth; x++) {
						for (
							let y = item.gridY;
							y < item.gridY + item.gridHeight;
							y++
						) {
							const at = `${x}:${y}`
							expect(
								cells.has(at),
								`${name}/${grid.id}: ${item.widgetId} at ${at}`,
							).toBe(false)
							cells.add(at)
						}
					}
				}
				const placed = new Set(grid.layout.map((item) => item.widgetId))
				for (const id of ids) {
					expect(
						placed.has(id),
						`${name}/${grid.id}: ${id} is not placed`,
					).toBe(true)
				}
			}
		},
	)

	it.each([
		['full', builtFull],
		['simple', builtSimple],
	])(
		'%s: every custom widget in a view resolves through a page slot',
		(name, built) => {
			const landing = page(built.pages, 'MyWorkHome')
			for (const grid of pageGrids(landing)) {
				for (const widget of grid.widgets.filter(
					(w) => w.type === 'custom',
				)) {
					expect(
						landing.slots[`widget-${widget.id}`],
						`${name}/${grid.id}: ${widget.id}`,
					).toBeTruthy()
				}
			}
		},
	)
})

describe('the built full manifest', () => {
	it('still passes the schema the installed library ships', () => {
		const file = path.join(
			fs.mkdtempSync(path.join(os.tmpdir(), 'dossiq-full-')),
			'manifest.json',
		)
		fs.writeFileSync(file, JSON.stringify(builtFull))
		const run = spawnSync(
			'node',
			[path.join(ROOT, 'tests', 'validate-manifest.js')],
			{
				cwd: ROOT,
				env: { ...process.env, APP_MANIFEST: file },
				encoding: 'utf8',
			},
		)
		// A lag of the installed library is not a defect here (validate-manifest.js
		// tells them apart); an error of this app's own still fails.
		expect(run.stdout + run.stderr).toMatch(
			/PASS \(0 errors(\)| of this app's own)/,
		)
		expect(run.status).toBe(0)
	}, 120_000)
})
