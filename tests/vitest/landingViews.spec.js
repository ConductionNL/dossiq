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
 * @spec openspec/changes/landing-views/specs/my-work-landing/spec.md
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
		expect(home.config.viewsLabel).toBe('Whose work')
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
		// The shared queue is the dashboard's own preset, which reads the
		// Queue page's filter.
		const preset = dashboard.config.userWidgets.find(
			(item) => item.id === 'your-teams-queue',
		)
		expect(team[0]).toEqual({ id: preset.id, ...preset.widget })
		expect(team[0].content.filter).toEqual(
			page(manifest.pages, 'Queue').config.filter,
		)
	})

	it('gives My team a sentence for when it has nothing to draw', () => {
		expect(pageView(home, 'team').emptyText).toBeTruthy()
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
		expect(run.stdout + run.stderr).toContain('PASS (0 errors)')
		expect(run.status).toBe(0)
	}, 120_000)
})
