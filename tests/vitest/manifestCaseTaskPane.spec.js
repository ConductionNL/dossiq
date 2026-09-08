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
 * @spec openspec/changes/task-on-the-case/specs/task-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const REGISTRY_PATH = path.join(ROOT, 'src/registry.js')
const MANIFEST_PATH = path.join(ROOT, 'src/manifest.json')
const ICONS_PATH = path.join(ROOT, 'src/icons.js')

const registrySource = () => fs.readFileSync(REGISTRY_PATH, 'utf8')
const manifest = () => JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))

/**
 * The page count on `origin/development`, before this change. The pane adds
 * no page and retypes none, so a difference here means the ADR-100 ratchet
 * moved and the change did something it said it would not.
 */
const PAGE_COUNT_BEFORE = 43

/** Pages of `type: "custom"` before this change. */
const CUSTOM_PAGE_COUNT_BEFORE = 10

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

	it('stays a child of the tabs strip and out of the layout', () => {
		const detail = page('CaseDetail')
		const strip = widget('CaseDetail', 'case-panels')
		expect(strip.content.tabs.some((tab) => tab.widgetId === 'case-tasks')).toBe(
			true,
		)
		// A tab child in `layout` renders twice: once in the grid and once in
		// its panel. That is why the retype does not move it.
		expect(
			detail.config.layout.some((item) => item.widgetId === 'case-tasks'),
		).toBe(false)
	})

	it('adds no page and retypes none', () => {
		const pages = manifest().pages
		expect(pages).toHaveLength(PAGE_COUNT_BEFORE)
		expect(pages.filter((entry) => entry.type === 'custom')).toHaveLength(
			CUSTOM_PAGE_COUNT_BEFORE,
		)
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

describe('the TaskCaseLink registry binding', () => {
	it('is keyed by component name, for the page slot on TaskDetail', () => {
		const entry = registryEntry('TaskCaseLink')
		expect(entry).toContain("kind: 'widget'")
		expect(entry).toContain('component: TaskCaseLink')
		expect(entry).toContain('_note:')
		expect(entry).toMatch(/@custom-widget-ratchet exclude \S+ \S+/)
	})

	it('imports the component from a file that exists', () => {
		const match = registrySource().match(/^import TaskCaseLink from '(.+)'$/m)
		expect(match, 'TaskCaseLink must be imported').not.toBeNull()
		expect(
			fs.existsSync(path.join(ROOT, 'src', match[1].replace(/^\.\//, ''))),
		).toBe(true)
	})
})

describe('the task-case-link widget on TaskDetail', () => {
	it('is a custom widget resolved through the page slot', () => {
		const link = widget('TaskDetail', 'task-case-link')
		expect(link).toBeDefined()
		expect(link.type).toBe('custom')
		expect(page('TaskDetail').slots['widget-task-case-link']).toBe(
			'TaskCaseLink',
		)
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
		expect(item('task-case-link')).toBeDefined()
		expect(item('task-case-link').gridY).toBeLessThan(item('task-data').gridY)
		// An empty titled box on every task with no case is the clutter the
		// null render exists to avoid.
		expect(item('task-case-link').showTitle).toBe(false)
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
