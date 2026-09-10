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
 *
 * Moved 11 -> 12 on 2026-09-10 by `TaskDetail` (remove-casetask 2.1), and
 * this one had no choice either. A `type: "detail"` page binds a register
 * and a schema, the schema it bound is the one remove-casetask deletes, and
 * CnDetailPage has no `entitySource` mode to point at the task engine
 * instead (checked against the installed @conduction/nextcloud-vue 2.46.0).
 * The Tasks INDEX did have that choice and took it: it stayed
 * `type: "index"` and gained `entitySource: "tasks"`, which is why only one
 * of the two pages spends a unit here.
 *
 * The unit comes back when the library grows a detail-side entity source.
 * Note that hydra gate 69 has no exemption for this: its custom-page ratchet
 * compares the head count with the base and fires on any growth, with no
 * `_note` escape of the kind rule (b) offers. So this PR reds that gate by
 * exactly one finding, deliberately.
 */
const CUSTOM_PAGE_COUNT_BEFORE = 11
const CUSTOM_PAGE_COUNT_AFTER = 12

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

	it('adds no page, and spends exactly one ratchet unit on the retype', () => {
		const pages = manifest().pages
		// The pane still adds and retypes nothing: it is a WIDGET on the case
		// page. The one unit above the pre-pane count belongs to TaskDetail,
		// and to nothing else.
		const custom = pages.filter((entry) => entry.type === 'custom')
		expect(custom).toHaveLength(CUSTOM_PAGE_COUNT_AFTER)
		expect(CUSTOM_PAGE_COUNT_AFTER - CUSTOM_PAGE_COUNT_BEFORE).toBe(1)
		expect(custom.map((entry) => entry.id)).toContain('TaskDetail')

		// The retype the pane could have been given instead is a page of its
		// own, which is the thing this asserts: no page renders the case's
		// tasks.
		expect(pages.some((entry) => entry.id === 'CaseTasks')).toBe(false)

		// ONE page over `caseTask` now, not two. TaskDetail reads the engine
		// and binds no schema at all. `Tasks` is the last one and is
		// remove-casetask 2.2, which is blocked on two nextcloud-vue PRs and
		// a dossiq bump, so it is deliberately still here.
		expect(
			pages
				.filter(
					(entry) => entry.config && entry.config.schema === 'caseTask',
				)
				.map((entry) => entry.id)
				.sort(),
		).toEqual(['Tasks'])
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

describe('the TaskDetail page after the retype (remove-casetask 2.1)', () => {
	it('keeps its route and its id, so every deep link still resolves', () => {
		// This is the whole constraint of task 2.1 and the only one that can
		// break a bookmark. The manifest `deepLinks` entry publishes
		// /apps/dossiq/tasks/{uuid}, notifications point there, and the SPA
		// resolves it by ROUTE. A route resolves at request time, so a change
		// here fails silently for everyone holding an old link.
		const detail = page('TaskDetail')
		expect(detail).toBeDefined()
		expect(detail.route).toBe('/tasks/:id')

		const deepLink = manifest().deepLinks.find(
			(entry) => entry.urlTemplate === '/apps/dossiq/tasks/{uuid}',
		)
		expect(deepLink, 'the task deep link must still be declared').toBeDefined()
	})

	it('is a custom page bound to a component the registry answers', () => {
		// `type: "custom"` with no `component` is the one shape the manifest
		// schema rejects outright; a `component` no registry key answers is
		// the shape that renders a console warning and an empty page, which
		// is the failure this catches.
		const detail = page('TaskDetail')
		expect(detail.type).toBe('custom')
		expect(detail.component).toBe('TaskDetailView')

		const entry = registryEntry('TaskDetailView')
		expect(entry).toContain("kind: 'page'")
		expect(entry).toContain('component: TaskDetailView')
		expect(entry).toContain('_note:')

		const match = registrySource().match(/^import TaskDetailView from '(.+)'$/m)
		expect(match, 'TaskDetailView must be imported').not.toBeNull()
		expect(
			fs.existsSync(path.join(ROOT, 'src', match[1].replace(/^\.\//, ''))),
		).toBe(true)
	})

	it('binds no register and no schema, which is the point of the retype', () => {
		// A detail page binds a register and a schema, and the schema was
		// `caseTask`. The engine is not an OpenRegister object, so there is
		// nothing to bind and binding anything would put the object store
		// back in the read path.
		const config = page('TaskDetail').config ?? {}
		expect(config).not.toHaveProperty('register')
		expect(config).not.toHaveProperty('schema')
		expect(page('TaskDetail')).not.toHaveProperty('slots')
	})

	it('mounts every widget the detail page carried', () => {
		// The widgets are no longer manifest entries, so the check moves to
		// the component that renders them. All five are here: the case card,
		// the waiting-case section, the notes and appointment leaves and the
		// history that used to be a sidebar tab.
		const view = fs.readFileSync(
			path.join(ROOT, 'src/views/tasks/TaskDetailView.vue'),
			'utf8',
		)
		for (const child of [
			'TaskCaseCard',
			'TaskWaitingCaseSection',
			'TaskNotesLeaf',
			'TaskEventsLeaf',
			'TaskAuditLeaf',
		]) {
			expect(view, `${child} must be mounted`).toContain(`<${child}`)
		}
	})

	it('drives the lifecycle with the engine verbs, not CnLifecycleActions', () => {
		// 🔴 The mistake this pins down has been made once already, on the
		// case pane. CnLifecycleActions asks OpenRegister for an OBJECT's
		// available transitions (/api/objects/{uuid}/available-actions); an
		// engine task is not an object, so it 404s and no button renders. It
		// only shows in a browser, because every unit test stubs the
		// component away.
		const view = fs.readFileSync(
			path.join(ROOT, 'src/views/tasks/TaskDetailView.vue'),
			'utf8',
		)
		//
		// Matched on the IMPORT and the TAG, not on the bare name: the
		// component's own header comment explains at length why the strip is
		// not used, and a substring check would fail on the explanation.
		expect(view).not.toMatch(/^import .*CnLifecycleActions/m)
		expect(view).not.toContain('<CnLifecycleActions')
		expect(view).toContain('engineTasks.invoke(')
	})

	it('leaves the two card components in place, as plain children', () => {
		// They are no longer registry entries: nothing in the manifest names
		// them any more, and a registry key no manifest resolves is dead
		// configuration. The FILES stay, because the page still mounts them.
		expect(registrySource()).not.toMatch(/^\tTaskCaseCard: \{/m)
		expect(registrySource()).not.toMatch(/^\tTaskWaitingCaseSection: \{/m)
		expect(
			fs.existsSync(path.join(ROOT, 'src/components/tasks/TaskCaseCard.vue')),
		).toBe(true)
		expect(
			fs.existsSync(
				path.join(ROOT, 'src/components/flow/TaskWaitingCaseSection.vue'),
			),
		).toBe(true)
	})
})
