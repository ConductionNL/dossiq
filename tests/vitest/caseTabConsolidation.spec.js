/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page holds SEVEN tabs, and keeps holding seven.
 *
 * The strip grew from ten tabs to fourteen over one programme while the app
 * menu held at four, because the menu had a stated ceiling and the strip had
 * nothing counting it. This file is the thing that counts it. Every assertion
 * here is about config, and config fails quietly: a tab added to the strip
 * raises nothing, breaks no build and passes `check:manifest`, because a
 * fourteen-tab manifest is a perfectly valid manifest.
 *
 * WHY THE LIBRARY IS ASSERTED FROM `node_modules`. Two properties of the
 * INSTALLED `@conduction/nextcloud-vue` are load-bearing for this change, and
 * both fail silently when they change. `CnDetailWidgetHost` hands the context
 * props only to a widget whose type the SHARED catalog marks `container`, so a
 * registration in the wrong map renders every section blank with nothing in
 * the console. And `CnTabsWidget` does not forward `availableWidgets`, which
 * is the only reason the sections carry their widget inline instead of naming
 * an id. A library upgrade that changes either should redden here rather than
 * blank a panel in production, and the second one changing is an invitation to
 * delete code.
 *
 * @spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const MANIFEST_PATH = path.join(ROOT, 'src/manifest.json')
const REGISTER_MODULE = path.join(
	ROOT,
	'src/components/case/registerCaseSections.js',
)
const LIB = path.join(ROOT, 'node_modules/@conduction/nextcloud-vue/src')

const manifest = () => JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const read = (file) => fs.readFileSync(file, 'utf8')

/**
 * The CaseDetail page config.
 *
 * @return {object} The page's `config` object.
 */
function caseDetail() {
	return manifest().pages.find((page) => page.id === 'CaseDetail').config
}

/**
 * One widget of the CaseDetail page, by id.
 *
 * @param {string} id The widget id.
 * @return {object|undefined} The widget definition.
 */
function widget(id) {
	return caseDetail().widgets.find((entry) => entry.id === id)
}

/** The tab entries of the `case-panels` strip. */
const tabs = () => widget('case-panels').content.tabs

/**
 * The ceiling the placement work set, and the number this change delivers.
 *
 * Six is not a round number picked for tidiness. It is the count row A33
 * asked for, and the same ceiling the app menu is held to.
 */
// Seven since 2026-09-12: the Notes tab joined the strip beside Documents.
const TAB_CEILING = 7

/** The seven labels, in the order a handler reads them. */
const EXPECTED_TABS = [
	['case-core', 'Data'],
	['case-documents-panel', 'Documents'],
	['case-notes-panel', 'Notes'],
	['case-people-panel', 'People'],
	['case-work-panel', 'Work'],
	['case-related-panel', 'Related'],
	['case-objects-panel', 'Objects and locations'],
]

/**
 * Every panel that used to be a tab of its own, and where it lives now.
 *
 * This is the half of the change that could regress invisibly. A fold that
 * drops a widget instead of moving it leaves a shorter strip and a passing
 * count, so the count alone is not enough: each of these must still render
 * somewhere on the page.
 */
const FOLDED = [
	['case-documents-panel', 'case-documents'],
	['case-documents-panel', 'case-files'],
	['case-people-panel', 'case-roles'],
	['case-people-panel', 'case-communication'],
	['case-work-panel', 'case-tasks'],
	['case-work-panel', 'case-calendar'],
	['case-related-panel', 'case-related'],
	['case-related-panel', 'case-sub-cases'],
	['case-objects-panel', 'case-objects'],
	['case-objects-panel', 'case-locaties'],
]

/**
 * The three body tabs that were REMOVED rather than folded, each with the
 * sidebar tab that already carried the same thing.
 *
 * Removing a duplicate is the cheapest four tabs in the change and the
 * easiest to undo by accident, because re-adding one looks like adding a
 * feature. Each of these asserts the sidebar half is still there: dropping
 * the body tab is only correct while the sidebar tab exists.
 */
const DEDUPLICATED = [
	['case-notes', 'notes'],
	['case-email', 'email'],
	['case-decidesk-decisions', 'besluitvorming'],
]

describe('the case page tab strip', () => {
	it(`holds no more than ${TAB_CEILING} tabs`, () => {
		expect(tabs()).toHaveLength(TAB_CEILING)
	})

	it('names the seven tabs, in order', () => {
		expect(tabs().map((tab) => [tab.widgetId, tab.label])).toEqual(EXPECTED_TABS)
	})

	it('labels every tab in sentence case, with no em-dash', () => {
		// The most-read strings on the page. `CnTabsWidget` renders `label`
		// verbatim, with no `t()` anywhere on the path, so what is written in
		// the manifest is exactly what a handler sees.
		for (const { label } of tabs()) {
			expect(label, `${label} carries a dash the voice bans`).not.toMatch(
				/[—–]|--/,
			)
			expect(label[0], `${label} does not start with a capital`).toBe(
				label[0].toUpperCase(),
			)
			const rest = label.split(' ').slice(1)
			expect(
				rest.filter((word) => word !== word.toLowerCase()),
				`${label} is Title Case`,
			).toEqual([])
		}
	})

	it('gives every tab a widget that exists on the page', () => {
		const ids = caseDetail().widgets.map((entry) => entry.id)
		for (const { widgetId } of tabs()) {
			expect(ids, `tab widget ${widgetId} is not declared`).toContain(widgetId)
		}
	})
})

describe('the folds', () => {
	it.each(FOLDED)('%s still renders %s', (groupId, childId) => {
		const sections = widget(groupId).content.sections
		const found = sections.find((section) => section.widget.id === childId)
		expect(found, `${childId} is gone from ${groupId}`).toBeTruthy()
		expect(found.widget.type).toBeTruthy()
		expect(found.label).toBeTruthy()
	})

	it('renders no folded widget twice', () => {
		// A child left in `widgets[]` as well as inside a group, or listed in
		// `layout`, renders twice. Nothing raises when it does.
		const topLevel = caseDetail().widgets.map((entry) => entry.id)
		const laidOut = caseDetail().layout.map((item) => item.widgetId)
		for (const [, childId] of FOLDED) {
			expect(topLevel).not.toContain(childId)
			expect(laidOut).not.toContain(childId)
		}
	})

	it('lays out no group widget, because the strip renders it', () => {
		const laidOut = caseDetail().layout.map((item) => item.widgetId)
		for (const { widgetId } of tabs()) {
			if (widgetId === 'case-core') continue
			expect(laidOut).not.toContain(widgetId)
		}
	})
})

describe('the tabs that were removed rather than folded', () => {
	it.each(DEDUPLICATED)(
		'%s is gone from the body, and the %s sidebar tab still carries it',
		(widgetId, sidebarTabId) => {
			const ids = caseDetail().widgets.map((entry) => entry.id)
			expect(ids).not.toContain(widgetId)

			const sidebar = caseDetail().sidebar.tabs.map((tab) => tab.id)
			expect(
				sidebar,
				`${widgetId} was dropped but ${sidebarTabId} does not exist, so the surface is simply gone`,
			).toContain(sidebarTabId)
		},
	)
})

describe('the container type this change depends on', () => {
	it('is registered in the SHARED catalog, with container: true', () => {
		const source = read(REGISTER_MODULE)
		expect(source).toContain("registerDashboardWidget('case-sections'")
		expect(source).toContain('container: true')
		// `registry.js` is the wrong map: a renderer resolves from it, but
		// `isContainer` does not read it, so the sections would render blank.
		expect(read(path.join(ROOT, 'src/registry.js'))).not.toContain(
			'case-sections',
		)
	})

	it('is the type every group widget declares', () => {
		// Two tabs are single surfaces rather than groups of sections: the
		// Data tab (the schema-driven data widget) and the Notes tab (one
		// notes pane, the same surface the sidebar offers). Every other tab
		// is a group and declares `case-sections`.
		for (const { widgetId } of tabs()) {
			if (widgetId === 'case-core') continue
			if (widgetId === 'case-notes-panel') {
				expect(widget(widgetId).type).toBe('case-notes-pane')
				continue
			}
			expect(widget(widgetId).type).toBe('case-sections')
		}
	})

	it('is handed the context props only because the library gates on the catalog', () => {
		// If this stops being true, the registration may move and the comment
		// explaining why it cannot is wrong.
		const host = read(
			path.join(LIB, 'components/CnDetailWidgetHost/CnDetailWidgetHost.vue'),
		)
		expect(host).toMatch(/isContainer\(\)\s*\{[\s\S]*getWidgetTypeEntry\(/)
		expect(host).toMatch(/if \(this\.isContainer\) \{[\s\S]*cnRegistry,/)
	})

	it('cannot name its sections by id, because the tabs widget forwards no sibling list', () => {
		// The day this fails, `content.sections[].widget` can become
		// `content.sections[].widgetId` and the inline definitions go back to
		// the page's `widgets[]`. That is a simplification waiting on the
		// library, and this is the alarm for it.
		const strip = read(
			path.join(LIB, 'components/CnTabsWidget/CnTabsWidget.vue'),
		)
		expect(strip).toContain('<CnDetailWidgetHost')
		expect(strip).not.toContain(':available-widgets')
		expect(strip).not.toContain(':availableWidgets')
	})
})
