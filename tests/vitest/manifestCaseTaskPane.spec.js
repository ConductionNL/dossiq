/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task pane's manifest-to-registry contract (task-on-the-case).
 *
 * Everything that binds the pane to the page is config, and config fails
 * quietly. A widget whose `type` no registry key answers to renders NOTHING
 * inside a tab panel and logs nothing (CnDetailWidgetHost's fallback comment
 * says as much). A page retyped to `custom` would move the ADR-100 ratchet.
 * A widget lifted out of the tabs strip into `layout` would render twice.
 * None of those raise an error anywhere in the build, so this file is what
 * turns them into failing tests.
 *
 * `src/registry.js` is read as TEXT rather than imported: it imports roughly
 * forty single-file components, and a probe import under vitest did not
 * settle within the 5s test budget. What is asserted is therefore the whole
 * chain that has to hold on disk — the manifest's widget `type`, the registry
 * key that answers it, the component identifier that key binds, the import
 * that binds that identifier to a path, and the file at that path.
 *
 * @spec openspec/specs/task-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

const ROOT = path.resolve(__dirname, '../..')
const REGISTRY_PATH = path.join(ROOT, 'src/registry.js')
const MANIFEST_PATH = path.join(ROOT, 'src/manifest.json')
const ICONS_PATH = path.join(ROOT, 'src/icons.js')

const registrySource = () => fs.readFileSync(REGISTRY_PATH, 'utf8')
const manifest = () => JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))

/**
 * Pages of `type: "custom"` on `origin/development`, before this change. The
 * pane adds no page and retypes none, so a difference here means the ADR-100
 * ratchet moved and the change did something it said it would not.
 *
 * The TOTAL page count used to be pinned here too, at 43. It is not any more:
 * a full-tree count is moved by every later change that adds a page for its
 * own reasons (`CaseObjects` was the first), which turns this guard into a
 * tripwire for unrelated work while saying nothing about the task pane. The
 * ratchet ADR-100 actually sets is on CUSTOM pages, and that stays exact; the
 * assertion below states the rest of the intent directly instead.
 *
 * Moved 10 -> 11 on 2026-09-08 by `FeaturesRoadmap`, which is the only page
 * since the pane to spend a unit of the ratchet. It did not have a choice:
 * the library's CnFeaturesAndRoadmapView declares zero slots (checked against
 * the installed @conduction/nextcloud-vue 2.41.0 dist, not the docs), so
 * `type: "roadmap"` cannot carry the capability comparison. The unit comes
 * back the day the library grows a slot or a comparison tab and the page
 * returns to `type: "roadmap"`; the manifest `_note` on that page says so
 * too. Anything else that moves this number is a change that owes an
 * explanation here.
 */
const CUSTOM_PAGE_COUNT_BEFORE = 11

/**
 * One page as the manifest declares it.
 *
 * @param {string} id The manifest page id.
 * @return {object} The page entry.
 */
function page(id) {
	return manifest().pages.find((entry) => entry.id === id)
}

/**
 * One widget of a detail page.
 *
 * @param {string} pageId The manifest page id.
 * @param {string} widgetId The manifest widget id.
 * @return {object|undefined} The widget entry.
 */
function widget(pageId, widgetId) {
	// CaseDetail goes through the shared helper: since the strip came down
	// from fourteen tabs to six, ten of its panels are SECTIONS of a tab, so a
	// top-level `find` returns undefined for them and every assertion reads as
	// "the widget was deleted".
	if (pageId === 'CaseDetail') return panels.caseWidget(widgetId)
	return page(pageId).config.widgets.find((entry) => entry.id === widgetId)
}

/**
 * The registry entry body for a key, as it is written in the source.
 *
 * @param {string} key The registry key.
 * @return {string} The entry's source text, up to its closing brace.
 */
function registryEntry(key) {
	const source = registrySource()
	const opener = source.indexOf(`\n\t'${key}': {`)
	const named = source.indexOf(`\n\t${key}: {`)
	const start = opener !== -1 ? opener : named
	expect(start, `registry has no entry for ${key}`).toBeGreaterThan(-1)
	const end = source.indexOf('\n\t},', start)
	expect(end).toBeGreaterThan(start)
	return source.slice(start, end)
}

describe('the CaseTaskPane registry binding', () => {
	it('answers the widget type by the key CnDetailWidgetHost looks up', () => {
		// `cnRegistry[widget.type]` is the ONLY resolution a tab child gets. A
		// key spelled after the component (`CaseTaskPane`) would satisfy a
		// page slot and leave the tab panel blank.
		const entry = registryEntry('case-task-pane')
		expect(entry).toContain("kind: 'widget'")
		expect(entry).toContain('component: CaseTaskPane')
	})

	it('carries a _note and a reason-bearing ratchet exclusion', () => {
		// Gate 29 (custom-widget-ratchet) fails a kind:"widget" entry with no
		// `_note`, and fails the app when the count grows without a reason.
		const entry = registryEntry('case-task-pane')
		expect(entry).toContain('_note:')
		expect(entry).toMatch(/@custom-widget-ratchet exclude \S+ \S+/)
		expect(entry).toContain('CnObjectListWidget has no rowActions')
	})

	it('imports the component from a file that exists', () => {
		const match = registrySource().match(/^import CaseTaskPane from '(.+)'$/m)
		expect(match, 'CaseTaskPane must be imported').not.toBeNull()
		expect(
			fs.existsSync(path.join(ROOT, 'src', match[1].replace(/^\.\//, ''))),
		).toBe(true)
	})
})

describe('the case-tasks widget after the retype', () => {
	it('keeps its id, title, icon and every content key', () => {
		const pane = widget('CaseDetail', 'case-tasks')
		expect(pane).toBeDefined()
		expect(pane.type).toBe('case-task-pane')
		expect(pane.title).toBe('Tasks')
		expect(pane.icon).toBe('ClipboardCheckOutline')
		// The content is what the widget goes back to reading the day
		// CnObjectListWidget grows a lifecycle column. Dropping a key here
		// would make the swap back a second change rather than a revert.
		expect(pane.content).toMatchObject({
			register: 'dossiq',
			schema: 'caseTask',
			filter: { case: '@objectId' },
			sort: { field: 'dueDate', dir: 'asc' },
			rowRoute: 'TaskDetail',
			viewAllRoute: 'Tasks',
			viewAllQuery: { case: '@objectId' },
		})
		expect(Array.isArray(pane.content.columns)).toBe(true)
		expect(typeof pane.content.emptyText).toBe('string')
	})

	it('stays inside the tabs strip and out of the layout', () => {
		const detail = page('CaseDetail')

		// It leads the Work tab, with the appointments under it. Plan item 3.18
		// asked for the pane to move OUT of the strip into the right column
		// instead; it did not, because that column already carries four cards
		// and a fifth moves the complexity rather than removing it.
		const where = panels.caseTabOf('case-tasks')
		expect(where, 'case-tasks is not reachable from the strip').toBeTruthy()
		expect(where.tab).toBe('Work')
		expect(where.label).toBe('Tasks')

		// A tab child in `layout` renders twice: once in the grid and once in
		// its panel. That is why the retype does not move it.
		expect(
			detail.config.layout.some((item) => item.widgetId === 'case-tasks'),
		).toBe(false)
	})

	it('adds no page and retypes none', () => {
		const pages = manifest().pages
		expect(pages.filter((entry) => entry.type === 'custom')).toHaveLength(
			CUSTOM_PAGE_COUNT_BEFORE,
		)
		// The pane is a WIDGET on the case page. The retype it could have been
		// given instead is a page of its own, which is the thing this asserts:
		// no page renders the case's tasks, and the two pages over `caseTask`
		// are the index and the task detail that existed before.
		expect(pages.some((entry) => entry.id === 'CaseTasks')).toBe(false)
		// ONE page over `caseTask` now, not two. The Tasks index moved to
		// `entitySource: "tasks"` and reads the engine's inbox, so it binds
		// no schema at all. TaskDetail is the last one, and it goes when
		// the schema does.
		expect(
			pages
				.filter(
					(entry) => entry.config && entry.config.schema === 'caseTask',
				)
				.map((entry) => entry.id)
				.sort(),
		).toEqual(['TaskDetail'])
	})

	it('names an icon src/icons.js registers', () => {
		// An unregistered icon renders NO icon rather than a fallback glyph,
		// and hydra gate 60 fails on it.
		const icons = fs.readFileSync(ICONS_PATH, 'utf8')
		expect(icons).toContain(
			"import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'",
		)
	})
})

describe('the TaskCaseCard registry binding', () => {
	it('is keyed by component name, for the page slot on TaskDetail', () => {
		const entry = registryEntry('TaskCaseCard')
		expect(entry).toContain("kind: 'widget'")
		expect(entry).toContain('component: TaskCaseCard')
		expect(entry).toContain('_note:')
		expect(entry).toMatch(/@custom-widget-ratchet exclude \S+ \S+/)
	})

	it('imports the component from a file that exists', () => {
		const match = registrySource().match(/^import TaskCaseCard from '(.+)'$/m)
		expect(match, 'TaskCaseCard must be imported').not.toBeNull()
		expect(
			fs.existsSync(path.join(ROOT, 'src', match[1].replace(/^\.\//, ''))),
		).toBe(true)
	})
})

describe('the task-case widget on TaskDetail', () => {
	it('is a custom widget resolved through the page slot', () => {
		const card = widget('TaskDetail', 'task-case')
		expect(card).toBeDefined()
		expect(card.type).toBe('custom')
		expect(page('TaskDetail').slots['widget-task-case']).toBe('TaskCaseCard')
		// A `custom` widget with no slot entry renders nothing and says
		// nothing, on this path exactly as on the tab path.
		expect(page('TaskDetail').slots['widget-task-waiting-case']).toBe(
			'TaskWaitingCaseSection',
		)
	})

	it('sits above the Data widget and draws no title', () => {
		const layout = page('TaskDetail').config.layout
		const item = (id) => layout.find((entry) => entry.widgetId === id)
		// A widget-<id> slot is rendered per GRID item, so a layout entry is
		// not decoration here: without one the component never mounts.
		expect(item('task-case')).toBeDefined()
		expect(item('task-case').gridY).toBeLessThan(item('task-data').gridY)
		// An empty titled box on every task with no case is the clutter the
		// null render exists to avoid.
		expect(item('task-case').showTitle).toBe(false)
	})

	it('hides the case row from the Data widget, so the case is stated once', () => {
		// The card resolves `case` to a title and a link. Leaving the raw
		// $ref row in the grid below shows the same relationship twice, and
		// the second one shows it as a uuid.
		const data = widget('TaskDetail', 'task-data')
		expect(data.content.overrides.case.hidden).toBe(true)
	})

	it('carries the notes and appointment leaves, side by side below the task', () => {
		const notes = widget('TaskDetail', 'task-notes')
		const calendar = widget('TaskDetail', 'task-calendar')
		expect(notes.type).toBe('integration')
		expect(notes.integrationId).toBe('notes')
		expect(calendar.type).toBe('integration')
		expect(calendar.integrationId).toBe('calendar')

		const layout = page('TaskDetail').config.layout
		const item = (id) => layout.find((entry) => entry.widgetId === id)
		// Below the task data, and beside each other rather than stacked.
		expect(item('task-notes').gridY).toBeGreaterThan(item('task-data').gridY)
		expect(item('task-calendar').gridY).toBe(item('task-notes').gridY)
		expect(item('task-notes').gridWidth + item('task-calendar').gridWidth).toBe(
			12,
		)
		// These two DO draw a title: unlike the case card they render their
		// own empty state, so a titled empty box is the correct affordance.
		expect(item('task-notes').showTitle).toBe(true)
		expect(item('task-calendar').showTitle).toBe(true)
	})

	it('does not disturb the flow waiting section', () => {
		const waiting = widget('TaskDetail', 'task-waiting-case')
		expect(waiting.type).toBe('custom')
		expect(waiting.icon).toBe('CheckboxMarkedCircleOutline')
	})

	it('names an icon src/icons.js registers', () => {
		const icons = fs.readFileSync(ICONS_PATH, 'utf8')
		expect(icons).toContain(
			"import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'",
		)
	})
})
