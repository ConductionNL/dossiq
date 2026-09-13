/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page holds NINE tabs, and keeps holding nine.
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
 * The ceiling the placement work set, and the number the strip holds today.
 *
 * Six was not a round number picked for tidiness: it was the count row A33
 * asked for, and the same ceiling the app menu is held to. It has moved twice,
 * both times on purpose, and this is the record of both:
 *
 *   6 -> 7  2026-09-12  the Notes tab joined the strip beside Files
 *   7 -> 9  2026-09-13  Communication left People, and Email and Decisions
 *                       left the SIDEBAR
 *
 * The second move is not the growth this ceiling guards against. Nothing was
 * added to the page: the sidebar lost exactly the three tabs the strip gained
 * (notes, email, besluitvorming), so counted together the page holds what it
 * held. What the ceiling is for is UNWATCHED growth, the strip going from ten
 * to fourteen with nothing counting it, and an exact assertion somebody has to
 * edit on purpose is the thing that stops that.
 */
const TAB_CEILING = 9

/** The nine labels, in the order a handler reads them. */
const EXPECTED_TABS = [
	['case-data-panel', 'Data'],
	['case-files', 'Files'],
	['case-notes-panel', 'Notes'],
	['case-people-panel', 'People'],
	['case-communication-panel', 'Communication'],
	['case-email-panel', 'Email'],
	['case-work-panel', 'Work'],
	['case-decisions-panel', 'Decisions'],
	['case-related-panel', 'Related'],
]

/**
 * Every panel that used to be a tab of its own, and where it lives now.
 *
 * This is the half of the change that could regress invisibly. A fold that
 * drops a widget instead of moving it leaves a shorter strip and a passing
 * count, so the count alone is not enough: each of these must still render
 * somewhere on the page.
 */
// The Documents group is gone (2026-09-13): its Files half is the Files tab
// itself now, and its dossier list left the page. See the Files tab test.
//
// The Objects and locations group went the same day. Its Locations half is the
// map on the Data tab, because `case-location` already carries latitude and
// longitude and the list was a table of coordinates nobody could picture; its
// Objects half moved to Related, an object linked to a case being a relation
// like any other. `case-locaties` is absent from this list ON PURPOSE, and the
// caseObjects spec asserts the map exists so that absence is not a deletion.
const FOLDED = [
	['case-data-panel', 'case-core'],
	['case-data-panel', 'case-location-map'],
	['case-people-panel', 'case-roles'],
	['case-communication-panel', 'case-communication'],
	['case-work-panel', 'case-tasks'],
	['case-work-panel', 'case-calendar'],
	['case-related-panel', 'case-related'],
	['case-related-panel', 'case-sub-cases'],
	['case-related-panel', 'case-objects'],
]

/**
 * The three surfaces that were a body tab AND a sidebar tab, and the strip tab
 * that is the single home for each now.
 *
 * This list used to run the other way: the body tab was deleted and the
 * sidebar tab asserted to survive, because one log in two places is
 * duplication rather than coverage (REQ-CDV-17). The rule held; on 2026-09-13
 * the choice of WHICH copy to keep was reversed. The strip is where a handler
 * works and the sidebar is a shelf beside it, and Notes had drifted into being
 * both at once.
 *
 * The assertion guards the same mistake in the same way: each surface reads in
 * exactly ONE chrome. A change that puts a sidebar tab back without removing
 * the strip tab reddens, which is what a half-finished revert looks like.
 */
const SINGLE_HOME = [
	['case-notes-panel', 'notes'],
	['case-email-panel', 'email'],
	['case-decisions-panel', 'besluitvorming'],
]

describe('the case page tab strip', () => {
	it(`holds no more than ${TAB_CEILING} tabs`, () => {
		expect(tabs()).toHaveLength(TAB_CEILING)
	})

	it('names the nine tabs, in order', () => {
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

describe('the surfaces that read in exactly one chrome', () => {
	it.each(SINGLE_HOME)(
		'%s is a strip tab, and the %s sidebar tab is gone',
		(widgetId, sidebarTabId) => {
			const ids = caseDetail().widgets.map((entry) => entry.id)
			expect(
				ids,
				`${widgetId} is not on the page, so dropping the ${sidebarTabId} sidebar tab deleted the surface instead of moving it`,
			).toContain(widgetId)

			const sidebar = caseDetail().sidebar.tabs.map((tab) => tab.id)
			expect(
				sidebar,
				`${sidebarTabId} is still in the sidebar while ${widgetId} is in the strip, which is the duplication REQ-CDV-17 bars`,
			).not.toContain(sidebarTabId)
		},
	)

	it('leaves the sidebar the three tabs that have no strip counterpart', () => {
		// History, Sharing and Tags duplicate nothing, so they stay. Asserted
		// exactly: a later change that empties the sidebar, or refills it, has to
		// say so here rather than drift.
		expect(caseDetail().sidebar.tabs.map((tab) => tab.id)).toEqual([
			'audit',
			'sharing',
			'tags',
		])
	})
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
		// Four tabs are a single surface rather than a group of sections: Files
		// (the case folder through the `files` leaf) and the three panes that
		// came out of the sidebar. Every other tab is a group and declares
		// `case-sections`.
		//
		// The three panes are keyed by TYPE in registry.js, not by component
		// name. That distinction is the whole bug behind an empty Notes tab:
		// CnDetailWidgetHost resolves a tab child from `cnRegistry[widget.type]`
		// and renders nothing, silently, when the key is missing, and these
		// components were registered only as sidebar `component:` entries.
		const PANES = {
			'case-notes-panel': 'case-notes-pane',
			'case-email-panel': 'case-email-pane',
			'case-decisions-panel': 'case-decisions-pane',
		}
		const registry = read(path.join(ROOT, 'src/registry.js'))

		for (const { widgetId } of tabs()) {
			if (widgetId === 'case-files') {
				expect(widget(widgetId).type).toBe('integration')
				expect(widget(widgetId).integrationId).toBe('files')
				continue
			}
			if (PANES[widgetId]) {
				const type = PANES[widgetId]
				expect(widget(widgetId).type).toBe(type)
				// And the type actually resolves. Without this the assertion
				// above passes on a manifest naming a renderer nobody wrote.
				expect(
					registry,
					`${type} is named by the manifest but registered nowhere, so the ${widgetId} tab renders nothing`,
				).toContain(`'${type}': {`)
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
