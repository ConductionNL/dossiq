/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case page strip holds the board's five tabs; the other panels go under Acties.
 *
 * The strip grew from ten tabs to fifteen over one programme while the app
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
 * The board's strip (DqZaak, DqZaakMeer): five tabs a handler sees, and every
 * other panel flagged `overflow`, which the board lists under Acties beside
 * the strip. The ceiling counts the five, because the strip is what grows
 * unwatched; a panel that goes under Acties adds no width to it.
 *
 * Fourteen panels in all. Review is no longer a tab of its own: it stands
 * under the files on the Files tab, since the documents are what it reviews.
 */
const STRIP_CEILING = 5

/** The tabs in the strip, in the order the board draws them. */
const EXPECTED_STRIP = [
	['case-data-panel', 'Overview'],
	['case-files', 'Files'],
	['case-communication-panel', 'Contact'],
	['case-work-panel', 'Tasks'],
	['case-timeline-panel', 'Case history'],
]

/** The panels under Acties, in the order the board lists them. */
const EXPECTED_OVERFLOW = [
	['case-notes-panel', 'Notes'],
	['case-people-panel', 'People'],
	['case-email-panel', 'Email'],
	['case-decisions-panel', 'Decisions'],
	['case-related-panel', 'Related'],
	['case-custody-panel', 'Custody'],
	['case-archival-panel', 'Archiving'],
	['case-knowledge-panel', 'Knowledge'],
	['case-change-proposals', 'Proposals'],
]

/**
 * The widget ids a tab renders: a stack's `widgetIds`, else its `widgetId`.
 *
 * @param {object} tab A tab entry.
 * @return {string[]} The ids.
 */
const tabWidgetIds = (tab) => (Array.isArray(tab.widgetIds) ? tab.widgetIds : [tab.widgetId])

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
	['case-people-panel', 'case-parties'],
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
	it(`holds no more than ${STRIP_CEILING} tabs in the strip`, () => {
		expect(tabs().filter((tab) => tab.overflow !== true)).toHaveLength(STRIP_CEILING)
	})

	it('names the strip tabs, in order', () => {
		const strip = tabs().filter((tab) => tab.overflow !== true)
		expect(strip.map((tab) => [tabWidgetIds(tab)[0], tab.label])).toEqual(EXPECTED_STRIP)
	})

	it('puts every other panel under Acties, in order', () => {
		const overflow = tabs().filter((tab) => tab.overflow === true)
		expect(overflow.map((tab) => [tabWidgetIds(tab)[0], tab.label])).toEqual(EXPECTED_OVERFLOW)
	})

	it('keeps the document review on the Files tab', () => {
		const files = tabs().find((tab) => tab.label === 'Files')
		expect(tabWidgetIds(files)).toEqual(['case-files', 'case-document-review'])
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
		for (const widgetId of tabs().flatMap(tabWidgetIds)) {
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
		for (const widgetId of tabs().flatMap(tabWidgetIds)) {
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

	it('leaves the sidebar the tabs that have no strip counterpart', () => {
		// History, Terms, Access, Sharing and Tags duplicate nothing, so they
		// stay. Asserted exactly: a later change that empties the sidebar, or
		// refills it, has to say so here rather than drift.
		//
		// `access` arrived with case-grants-name-their-source: who holds which
		// right on this case and where each grant came from, read from
		// OpenRegister. It is saying so here, which is what this assertion is
		// for. It has no strip counterpart and duplicates nothing: the strip
		// carries what has HAPPENED to the case, and this carries who may act
		// on it.
		//
		// `terms` arrived with phase-terms-and-the-internal-target: the four
		// clocks on this case read apart, the statutory term beside the planned
		// end, the internal target and the phase term. It has no strip
		// counterpart either. The strip says what happened; this says what is
		// still owed and by when.
		//
		// `avg` arrived with data-subject-requests-drive-the-platform: what
		// the platform reported about this case's data subject, and the acts
		// dossiq drives on it. It is declared on every case and reads its own
		// empty state when the case is not a data subject request, so it has
		// no strip counterpart and duplicates nothing. Saying so here is the
		// price of the tab, and it is the price on purpose.
		//
		// `assistant` arrived with ai-features-on-the-case-consume-hermiq
		// (board DqZaakAssistent): the AI features the case type switched on,
		// a run on a case document with a refusal in words, and the report
		// group. No strip widget reads any of that, so it duplicates nothing.
		expect(caseDetail().sidebar.tabs.map((tab) => tab.id)).toEqual([
			'audit',
			'terms',
			'assistant',
			'access',
			'sharing',
			'avg',
			'tags',
		])
	})
})

describe('the container type this change depends on', () => {
	it("is the library's `sections` container, registered in the SHARED catalog", () => {
		// nextcloud-vue registers `sections` itself (CnSectionsWidget) with
		// container: true, the flag CnDetailWidgetHost reads to hand a widget
		// the schema, integration context and registry its children need.
		const source = read(path.join(LIB, 'components/CnSectionsWidget/index.js'))
		expect(source).toContain("registerDashboardWidget('sections'")
		expect(source).toContain('container: true')
		// The app no longer carries its own copy of the container.
		expect(
			fs.existsSync(
				path.join(ROOT, 'src/components/case/CaseSectionsWidget.vue'),
			),
		).toBe(false)
		expect(read(path.join(ROOT, 'src/registry.js'))).not.toContain(
			'case-sections',
		)
	})

	it('is the type every group widget declares', () => {
		// Six tabs are a single surface rather than a group of sections: Files
		// (the case folder through the `files` leaf), the three panes that came
		// out of the sidebar, Timeline, and Archiving. Every other tab is a
		// group and declares `sections`.
		//
		// Archiving is `custom` on purpose and the manifest says why: every
		// value on it is read off `@self._retention`, which openregister wrote
		// at closure. A data widget would build its fields from the schema's own
		// properties instead, which is a second derivation of the same answer.
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
			// Timeline arrived with #2846: every note, call and message on the
			// case in one order. A single surface, keyed by TYPE like the three
			// above it.
			'case-timeline-panel': 'case-timeline-pane',
			// Archiving arrived with #2850 as `type: "custom"` plus a
			// `widget-case-archival` slot on the page, and this file carried a
			// branch that asserted exactly that. The slot name did not match
			// the widget id,
			// and a page slot is read by the widget GRID and never reaches a
			// widget inside a tab panel, so the tab drew nothing while this
			// test stayed green. It is keyed by TYPE now, like the five above.
			'case-archival-panel': 'case-archival-pane',
			// Custody arrived with #2940: who has held this case, and who is
			// asking for it. Keyed by TYPE for the same reason as the six
			// above, and a custom pane rather than a data widget because a
			// holding is only meaningful as a JOIN between the moment one
			// ended and the moment the next began; a row of values per
			// holding would look complete on a chain with a gap in it.
			'case-custody-panel': 'case-custody-pane',
			// Review arrived with woo-review-triage: where a document review
			// stands. A single surface keyed by TYPE (registry.js), like the rest.
			'case-document-review': 'document-review',
		}

		// The tabs the LIBRARY draws from another app's leaf. Files is the
		// only one left: Knowledge was here too until the case type's work
		// instruction joined the collectives leaf under it, which made it a
		// GROUP and so a `sections` like every other group. Its leaf did
		// not go anywhere, it just moved one level down, and the assertion
		// below follows it there rather than being deleted.
		//
		// A map rather than one id, because each entry has to name the leaf it
		// draws: an `integration` widget with no `integrationId` renders an
		// empty tab and logs nothing.
		const LEAVES = {
			'case-files': 'files',
			'case-change-proposals': 'portaliq-change-proposal-queue',
		}

		// A leaf that lives inside a group, as `{ widgetId: [sectionWidgetId,
		// integrationId] }`. Same failure it guards against, one level down.
		const NESTED_LEAVES = {
			'case-knowledge-panel': ['case-knowledge-pages', 'collectives'],
		}
		const registry = read(path.join(ROOT, 'src/registry.js'))

		for (const widgetId of tabs().flatMap(tabWidgetIds)) {
			if (LEAVES[widgetId]) {
				expect(widget(widgetId).type).toBe('integration')
				expect(widget(widgetId).integrationId).toBe(LEAVES[widgetId])
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
			expect(widget(widgetId).type).toBe('sections')

			if (NESTED_LEAVES[widgetId]) {
				const [sectionId, integrationId] = NESTED_LEAVES[widgetId]
				const section = (widget(widgetId).content.sections || [])
					.map((entry) => entry.widget)
					.find((child) => child && child.id === sectionId)
				expect(
					section,
					`${widgetId} no longer holds the ${integrationId} leaf, so the tab draws no pages at all`,
				).toBeTruthy()
				expect(section.type).toBe('integration')
				expect(section.integrationId).toBe(integrationId)
			}
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

	it('can now name its sections by id, because the tabs widget forwards the sibling list', () => {
		// This was the alarm for a library change: since nextcloud-vue 2.80.0
		// CnTabsWidget forwards `availableWidgets` to its CnDetailWidgetHost, so
		// `content.sections[].widget` could become `content.sections[].widgetId`
		// with the inline definitions back in the page's `widgets[]`. That
		// simplification is a separate change; this pins that the library
		// supports it.
		const strip = read(
			path.join(LIB, 'components/CnTabsWidget/CnTabsWidget.vue'),
		)
		expect(strip).toContain('<CnDetailWidgetHost')
		expect(strip).toMatch(/:available-widgets|:availableWidgets/)
	})
})
