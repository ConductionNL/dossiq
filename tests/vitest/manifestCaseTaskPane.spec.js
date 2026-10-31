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
 *
 * Moved 12 -> 13 on 2026-09-14 by `CasesDeleted` (case-recycle-window 1.2),
 * and this one had no choice either. A `type: "index"` page binds a register
 * and a schema and fetches the objects endpoint, and that endpoint excludes
 * soft-deleted rows BY DESIGN: the deleted cases live in OpenRegister's trash
 * and answer on their own door. An index page pointed at the case schema
 * would therefore render an empty list and look like a working lens over an
 * empty trash, which is the exact failure the lens exists to prevent. The
 * unit comes back the day CnIndexPage grows an `entitySource` that can name
 * the trash, the same condition the two entries above are waiting on.
 *
 * Moved 13 -> 15 on 2026-09-16 by `one-personal-queue` (#2842), which adds
 * PersonalQueue and EndOfDay. Both raise BOTH numbers, because neither has
 * anything to do with the case-tasks retype this file measures: the delta
 * between them is the one unit the retype spends, and that is what the
 * assertion below reads. An index page can name one register and one schema;
 * the personal queue spans cases, engine tasks, consultations, advice
 * requests, mentions, covered work and calendar items, and the end-of-day
 * screen reads OpenRegister's per-reader read state. No manifest key names
 * either set.
 *
 * Moved 15 -> 14 by the retirement of the WorkflowBoard page, which gives a
 * unit back: the workflow board is the Cases index page in board view. Both
 * numbers drop, for the same reason as above.
 *
 * Moved 14 -> 13 when FeaturesRoadmap became the library's `type: "roadmap"`
 * page. Both numbers drop, for the same reason as above.
 *
 * Moved 13 -> 14 by the redaction page of a case (DqLakken), a custom page:
 * the panel reads its own redaction routes, which no typed page can. Both
 * numbers rise, for the same reason as above.
 *
 * Moved 14 -> 13 by the retirement of the CaseMap page: the case map is the
 * Cases index page in map view. Both numbers drop.
 *
 * Moved 13 -> 12 by `lists-are-views`: the custom MyWork page is a view of
 * the Cases list. Both numbers drop, for the same reason as above.
 *
 * Moved 12 -> 10 by the retirement of the custom DeelzaakList and
 * DeelzaakDetail pages. Both numbers drop.
 *
 * The unit TaskDetail spent comes back with task-page-on-the-manifest: the
 * task page is a `type: "detail"` page on `entitySource: "tasks"` again, so
 * the count after the pane equals the count before it.
 */
const CUSTOM_PAGE_COUNT_BEFORE = 9
const CUSTOM_PAGE_COUNT_AFTER = 9
// Custom pages moved onto manifest config since, each one unit off the ratchet
// (end-of-day-manifest: Close out your day is a dashboard; the personal queue
// is library config; deleted cases is an endpoint index; the store is a
// library `type: "store"` page; substitution admin lives in the admin
// settings; the redaction page of a case is the library `redaction` page type).
const CUSTOM_PAGES_RETIRED_SINCE = ['EndOfDay', 'PersonalQueue', 'CasesDeleted', 'Store', 'SubstitutionAdmin', 'CaseRedaction']

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

describe('the case-tasks widget on the library task widget', () => {
	it('has no dossiq component or registry key behind it any more', () => {
		// CaseTaskPane drew the first open task, its verbs and its form with
		// dossiq's own component; the library CnTasksWidget does it now
		// (`variant: focus`), so the key and the file are gone.
		expect(registrySource()).not.toMatch(/^\t'case-task-pane': \{/m)
		expect(fs.existsSync(path.join(ROOT, 'src/components/tasks/CaseTaskPane.vue'))).toBe(false)
		expect(fs.existsSync(path.join(ROOT, 'src/components/tasks/TaskFormFields.vue'))).toBe(false)
		expect(fs.existsSync(path.join(ROOT, 'src/utils/caseTaskPaneHelpers.js'))).toBe(false)
	})

	it('keeps its id and title, and declares the first open task with its verbs', () => {
		const pane = widget('CaseDetail', 'case-tasks')
		expect(pane).toBeDefined()
		expect(pane.type).toBe('tasks')
		expect(pane.title).toBe('Tasks')
		expect(pane.icon).toBeUndefined()
		expect(pane.content).toMatchObject({
			variant: 'focus',
			objectUuid: '@objectId',
			cancel: true,
			rowRoute: 'TaskDetail',
			viewAllRoute: { name: 'Tasks', query: { case: '@objectId' } },
			emptyText: 'No open tasks on this case',
		})
		// Every task on the case, not the reader's: the library reads `scope:
		// all` for a record, so no `scope` is declared.
		expect(pane.content.scope).toBeUndefined()
		expect(pane.content.register).toBeUndefined()
		expect(pane.content.schema).toBeUndefined()
	})

	it('completes through dossiq and claims only where the engine can', () => {
		// dossiq's completion route applies the case type's form and effect
		// refusals before the engine completes; the capability probe keeps
		// Claim off where the engine has no claim act.
		const pane = widget('CaseDetail', 'case-tasks')
		expect(pane.content.completeUrl).toBe('/apps/dossiq/api/case-tasks/{id}/complete')
		expect(pane.content.claimCapabilityUrl).toBe('/apps/dossiq/api/case-tasks/capabilities')
	})

	it('drops the stale note that pointed at nextcloud-vue#1033', () => {
		expect(JSON.stringify(widget('CaseDetail', 'case-tasks'))).not.toContain('1033')
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

	it('adds no page, and spends no ratchet unit', () => {
		const pages = manifest().pages
		// The pane adds and retypes nothing: it is a WIDGET on the case page.
		// TaskDetail, which spent one unit while it was a custom view, is a
		// typed detail page again (task-page-on-the-manifest).
		const custom = pages.filter((entry) => entry.type === 'custom')
		expect(custom).toHaveLength(CUSTOM_PAGE_COUNT_AFTER - CUSTOM_PAGES_RETIRED_SINCE.length)
		expect(custom.map((entry) => entry.id)).not.toEqual(expect.arrayContaining(CUSTOM_PAGES_RETIRED_SINCE))
		expect(CUSTOM_PAGE_COUNT_AFTER - CUSTOM_PAGE_COUNT_BEFORE).toBe(0)
		expect(custom.map((entry) => entry.id)).not.toContain('TaskDetail')

		// The retype the pane could have been given instead is a page of its
		// own, which is the thing this asserts: no page renders the case's
		// tasks.
		expect(pages.some((entry) => entry.id === 'CaseTasks')).toBe(false)

		// NO page binds `caseTask` any more. The Tasks index moved to
		// `entitySource: "tasks"` and reads the engine's inbox; TaskDetail
		// is a detail page on the same source and reads the engine too. Both bind no schema at all, which is what remove-casetask
		// needs before the schema can be deleted.
		expect(
			pages
				.filter(
					(entry) => entry.config && entry.config.schema === 'caseTask',
				)
				.map((entry) => entry.id)
				.sort(),
		).toEqual([])
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
	it('keeps its route and its id, so every existing link still resolves', () => {
		// This is the whole constraint of task 2.1 and the only one that can
		// break a bookmark. Notifications and bookmarks point at
		// /apps/dossiq/tasks/{uuid} and the SPA resolves it by ROUTE. A route
		// resolves at request time, so a change here fails silently for
		// everyone holding an old link.
		const detail = page('TaskDetail')
		expect(detail).toBeDefined()
		expect(detail.route).toBe('/tasks/:id')
	})

	it('publishes no deepLinks entry, because a deep link needs a schema', () => {
		// 🔴 THE ENTRY IS GONE AND ITS ABSENCE IS THE ASSERTION.
		// `deepLinks` is keyed on `registerSlug` + `schemaSlug`, and it feeds
		// exactly one consumer: OpenRegister's schema-scoped unified search,
		// which resolves a hit on an OBJECT of that schema to a URL.
		// remove-casetask deleted the schema, so the entry addressed a schema
		// nothing declares and could match no object. Keeping it would have
		// published a link nothing can ever produce.
		//
		// The cost is real and is recorded rather than papered over: an engine
		// task has NO unified-search provider today. That is an OpenRegister
		// gap, not a dossiq one. The engine's tasks live in their own table,
		// outside the object index the search reads, and no `deepLinks` shape
		// addresses them. The route above is unchanged, so the moment such a
		// shape exists this page is ready for it.
		const taskLinks = manifest().deepLinks.filter(
			(entry) =>
				entry.schemaSlug === 'caseTask'
				|| entry.urlTemplate === '/apps/dossiq/tasks/{uuid}',
		)
		expect(taskLinks).toEqual([])
	})

	it('is a detail page on the tasks entity source', () => {
		// task-page-on-the-manifest: the library reads one task, so the page is
		// typed again and no custom view or registry page stands behind it.
		const detail = page('TaskDetail')
		expect(detail.type).toBe('detail')
		expect(detail.config.entitySource).toBe('tasks')
		expect(detail).not.toHaveProperty('component')
		expect(registrySource()).not.toMatch(/^\tTaskDetailView: \{/m)
		expect(fs.existsSync(path.join(ROOT, 'src/views/tasks/TaskDetailView.vue'))).toBe(false)
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

	it('carries every widget the custom view mounted', () => {
		// The notes, appointments and history are the library cards reading
		// the task's engine endpoints, the waiting case is a facts card on the
		// case's next status (task-holds-up-next-status); the case card is the
		// library facts widget reading the case.
		const config = page('TaskDetail').config
		const types = [...config.widgets, ...config.sideColumn].map((widget) => widget.type)
		for (const type of ['integration', 'events', 'audit-trail', 'facts', 'checklist']) {
			expect(types, `${type} must be on the page`).toContain(type)
		}
		const caseCard = config.sideColumn.find((widget) => widget.id === 'side-case')
		expect(caseCard.type).toBe('facts')
		expect(caseCard.content.source).toEqual({ register: 'dossiq', schema: 'case', id: '@object.objectUuid' })
	})

	it('drives the lifecycle with the engine verbs, not CnLifecycleActions', () => {
		// 🔴 CnLifecycleActions asks OpenRegister for an OBJECT's available
		// transitions; an engine task is not an object, so it 404s and no
		// button renders. The verbs come from the tasks source; Finish goes
		// through dossiq's own completion route.
		const config = page('TaskDetail').config
		expect(config).not.toHaveProperty('lifecycleActions')
		expect(config.verbEndpoints).toEqual({ complete: '/apps/dossiq/api/case-tasks/{id}/complete' })
	})

	it('needs no dossiq widget for the waiting case or the case card', () => {
		// TaskCaseCard is gone: the card is CnFactsWidget over the case.
		expect(registrySource()).not.toMatch(/^\tTaskCaseCard: \{/m)
		expect(registrySource()).not.toMatch(/^\t'task-waiting-case': \{/m)
		expect(fs.existsSync(path.join(ROOT, 'src/components/flow/TaskWaitingCaseSection.vue'))).toBe(false)
		expect(
			fs.existsSync(path.join(ROOT, 'src/components/tasks/TaskCaseCard.vue')),
		).toBe(false)
	})
})
