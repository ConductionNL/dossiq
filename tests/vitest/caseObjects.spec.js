/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The things a case is about: the `caseObject` schema, the Objects tab on
 * `CaseDetail`, the Link object action and the `CaseObjects` index.
 *
 * All four are declarations no build step compares. A widget named as a tab
 * child resolves by registry TYPE, so a `type` the library does not know
 * renders nothing and logs nothing; a property that is not `facetable` gives
 * the sidebar nothing to group on; and a schema property added without a
 * version bump is fast-skipped by the OpenRegister importer, so the column
 * exists in git and nowhere else. This spec asserts the four declarations
 * against each other rather than trusting them.
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const REGISTER_PATH = path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json')
const MANIFEST_PATH = path.join(ROOT, 'src', 'manifest.json')
const ICONS_PATH = path.join(ROOT, 'src', 'icons.js')
const FORMATTERS_PATH = path.join(ROOT, 'src', 'services', 'formatters.js')

const register = JSON.parse(fs.readFileSync(REGISTER_PATH, 'utf8'))
const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const iconsSource = fs.readFileSync(ICONS_PATH, 'utf8')
const formattersSource = fs.readFileSync(FORMATTERS_PATH, 'utf8')
const panels = require('./helpers/casePanels.js')

/** The `caseObject` schema as the register declares it. @return {object} The schema. */
const caseObject = () => register.components.schemas.caseObject

/** The CaseDetail page. @return {object} The page. */
const caseDetail = () => manifest.pages.find((page) => page.id === 'CaseDetail')

/**
 * One widget of the CaseDetail page.
 *
 * @param {string} id The manifest widget id.
 * @return {object|undefined} The widget entry.
 */
function caseWidget(id) {
	// Through the shared helper, not `config.widgets`: since the strip came
	// down from fourteen tabs to six this widget is a SECTION of the Objects
	// and locations tab, and a top-level `find` returns undefined for it.
	return panels.caseWidget(id)
}

/** The tab children of the `case-panels` strip. @return {Array<object>} The tabs. */
function panelTabs() {
	return caseDetail().config.widgets.find((entry) => entry.id === 'case-panels')
		.content.tabs
}

/** One header action of the CaseDetail page. @param {string} id The action id. @return {object|undefined} The action. */
function headerAction(id) {
	return caseDetail().config.headerActions.find((entry) => entry.id === id)
}

/** The column keys of a widget's object list. @param {object} w The widget. @return {Array<string>} The keys. */
const columnKeys = (w) => w.content.columns.map((column) => column.key)

describe('the caseObject schema', () => {
	it('makes objectType a facet so the index sidebar can group on it', () => {
		// `facetable` is the ONLY spelling anything reads: the index sidebar
		// builds its filters from this flag on the schema property. The change's
		// design named `x-openregister-facet`, which no code in OpenRegister or
		// nextcloud-vue looks at, so it would have shipped a sidebar with no
		// object-type group and a green gate run.
		expect(caseObject().properties.objectType.facetable).toBe(true)
	})

	it('bumps the schema version so the importer does not fast-skip it', () => {
		// OpenRegister compares the incoming schema's `version` with the stored
		// one and skips the whole schema when they are equal. A property added
		// at the old version is inert on every existing instance.
		expect(caseObject().version).toBe('1.1.0')
	})

	it('still requires the case and the object type', () => {
		expect(caseObject().required).toEqual(
			expect.arrayContaining(['case', 'objectType']),
		)
	})

	it('keeps every field the Objects surfaces read visible and writable', () => {
		// `visible: false` makes a property unreadable on every surface and
		// `readOnly` makes the form builder drop the field before any override
		// is read — either would render an empty grid or a form that cannot
		// save. Asserted rather than assumed, because both fail silently.
		for (const key of [
			'case',
			'objectType',
			'objectIdentification',
			'objectUrl',
			'description',
		]) {
			const property = caseObject().properties[key]
			expect(property, `caseObject.${key} must exist`).toBeDefined()
			expect(
				property.visible,
				`caseObject.${key} must not be hidden`,
			).not.toBe(false)
			expect(
				property.readOnly,
				`caseObject.${key} must not be read-only`,
			).not.toBe(true)
		}
	})
})

describe('the Objects tab on the case page', () => {
	it('lists the case objects of the open case only', () => {
		const w = caseWidget('case-objects')
		expect(w).toBeDefined()
		expect(w.content.register).toBe('dossiq')
		expect(w.content.schema).toBe('caseObject')
		expect(w.content.filter).toEqual({ case: '@objectId' })
	})

	it('is an object-list, because a tab child resolves by registry type', () => {
		// A `type: "custom"` widget named as a tab child renders nothing and
		// logs nothing: the strip looks its child up by TYPE, not by slot.
		expect(caseWidget('case-objects').type).toBe('object-list')
	})

	it('shows the object type, identification, description and link', () => {
		expect(columnKeys(caseWidget('case-objects'))).toEqual([
			'objectType',
			'objectIdentification',
			'description',
			'objectUrl',
		])
	})

	it('names every column against a property the schema declares', () => {
		for (const key of columnKeys(caseWidget('case-objects'))) {
			expect(
				caseObject().properties[key],
				`caseObject has no property ${key}`,
			).toBeDefined()
		}
	})

	it('sorts on the object type and carries an empty state', () => {
		const w = caseWidget('case-objects')
		expect(w.content.sort).toEqual({ field: 'objectType', dir: 'asc' })
		expect(w.content.limit).toBe(25)
		expect(w.content.emptyText).toBe('No objects linked to this case yet')
	})

	it('opens no detail page: a case object is a link row', () => {
		expect(caseWidget('case-objects').content.rowRoute).toBeUndefined()
	})

	it('is a section of the Related tab, and the last one', () => {
		// It shared a tab with Locations until 2026-09-13, on the claim that a
		// caseObject and a case-location are both registry objects the case is
		// about. Locations is a map on the Data tab now, because
		// `case-location` already carries latitude and longitude and the list
		// was a table of coordinates nobody could picture. That left the tab
		// holding one collection, so the objects moved to Related: an object
		// linked to a case is a relation like any other.
		const where = panels.caseTabOf('case-objects')
		expect(where, 'case-objects is not reachable from the strip').toBeTruthy()
		expect(where.tab).toBe('Related')
		expect(where.label).toBe('Objects')

		// Last of Related's sections, after the cases. The two case collections
		// are what a handler opens Related for; the objects are the tail.
		const sections = panels
			.caseWidget('case-related-panel')
			.content.sections.map((section) => section.widget.id)
		expect(sections.at(-1)).toBe('case-objects')

		// And the tab it came from is gone rather than left standing empty.
		expect(panelTabs().map((tab) => tab.label)).not.toContain(
			'Objects and locations',
		)
	})

	it('leaves the locations to the map, not to a list nobody deleted', () => {
		// Retiring a surface has two halves and only the first one is visible:
		// the map exists, AND the list it replaced is gone. A `case-locaties`
		// object-list left behind anywhere would print the same rows in a
		// second place, which is the duplication REQ-CDV-17 exists to stop.
		expect(panels.caseWidget('case-locaties')).toBeUndefined()

		const map = panels.caseWidget('case-location-map')
		expect(map, 'the locations map is not on the page').toBeTruthy()
		expect(map.type).toBe('case-location-map')
		expect(panels.caseTabOf('case-location-map').tab).toBe('Data')
	})

	it('stays out of layout, or the strip would render it twice', () => {
		const cells = caseDetail().config.layout.filter(
			(cell) => cell.widgetId === 'case-objects',
		)
		expect(cells).toHaveLength(0)
	})

	it('names an icon that src/icons.js registers', () => {
		// hydra gate-60: an unregistered name renders NO icon, silently.
		expect(iconsSource).toMatch(/\bCubeOutline\b/)
	})
})

describe('the Link object action', () => {
	it('opens a form over caseObject from the case header', () => {
		const action = headerAction('link-object')
		expect(action).toBeDefined()
		expect(action.type).toBe('open-form')
		expect(action.register).toBe('dossiq')
		expect(action.schema).toBe('caseObject')
	})

	it('asks for the object type, identification, link and description', () => {
		expect(headerAction('link-object').includeFields).toEqual([
			'objectType',
			'objectIdentification',
			'objectUrl',
			'description',
		])
	})

	it('seeds every required property the form does not ask', () => {
		// An `open-form` action saves straight to the object API. A required
		// property that is neither asked nor seeded is refused on save, with a
		// validation error on a field the form never showed.
		const action = headerAction('link-object')
		const asked = new Set(action.includeFields)
		const seeded = new Set(Object.keys(action.props || {}))
		for (const required of caseObject().required) {
			expect(
				asked.has(required) || seeded.has(required),
				`caseObject.${required} is required but neither asked nor seeded`,
			).toBe(true)
		}
	})

	it('carries the open case through props', () => {
		expect(headerAction('link-object').props).toEqual({ case: '@objectId' })
	})

	it('confirms the save in words a handler reads', () => {
		expect(headerAction('link-object').successMessage).toBe(
			'Object linked to this case.',
		)
	})

	it('names an icon that src/icons.js registers', () => {
		expect(iconsSource).toMatch(
			new RegExp(`\\b${headerAction('link-object').icon}\\b`),
		)
	})
})

describe('the Objects index', () => {
	/** The CaseObjects page. @return {object} The page. */
	const page = () => manifest.pages.find((entry) => entry.id === 'CaseObjects')

	/** The Case column of that page. @return {object} The column. */
	const caseColumn = () =>
		page().config.columns.find((column) => column.key === 'case')

	it('reads the caseObject schema of the dossiq register', () => {
		expect(page()).toBeDefined()
		expect(page().type).toBe('index')
		expect(page().route).toBe('/case-objects')
		expect(page().config.register).toBe('dossiq')
		expect(page().config.schema).toBe('caseObject')
	})

	it('groups the sidebar on the object type', () => {
		expect(page().config.folderSidebar.field).toBe('objectType')
		expect(page().config.folderSidebar.allLabel).toBe('All objects')
	})

	it('names a folder source CnFolderSidebar accepts', () => {
		// The validator takes `custom`, `field` or `files`. The design's
		// `facet` renders no sidebar and warns only in the console; the facet
		// FILTER comes from `facetable` on the schema property instead.
		expect(['custom', 'field', 'files']).toContain(
			page().config.folderSidebar.source,
		)
	})

	it('groups on a property the schema marks facetable', () => {
		const field = page().config.folderSidebar.field
		expect(caseObject().properties[field].facetable).toBe(true)
	})

	it('shows the object type, identification, description and case', () => {
		expect(page().config.columns.map((column) => column.key)).toEqual([
			'objectType',
			'objectIdentification',
			'description',
			'case',
		])
	})

	it('renders the case by label, through a formatter the app registers', () => {
		expect(caseColumn().formatter).toBe('caseTitle')
		expect(formattersSource).toMatch(/\bcaseTitle:/)
	})

	it('leaves the case reference unexpanded, or View case pushes an object', () => {
		// `extend` would replace `row.case` with the expanded case object and
		// the row-token lookup below is FLAT, so the action would hand
		// vue-router an object and open nothing, without an error.
		expect(page().config.extend).toBeUndefined()
	})

	it("opens the row's case, not the row", () => {
		const actions = page().config.actions
		expect(actions).toHaveLength(1)
		expect(actions[0].id).toBe('view-case')
		expect(actions[0].handler).toBe('navigate')
		expect(actions[0].route).toBe('CaseDetail')
		expect(actions[0].params).toEqual({ id: '{case}' })
		// A case object has no page of its own, so the built-in View is off.
		expect(page().config.showViewAction).toBe(false)
	})

	it('navigates to a route the manifest declares', () => {
		const target = manifest.pages.find(
			(entry) => entry.id === page().config.actions[0].route,
		)
		expect(target).toBeDefined()
		expect(target.route).toBe('/cases/:id')
	})

	it('sits in the menu as Objects, after All cases and before Tasks', () => {
		const entry = manifest.menu.find((item) => item.route === 'CaseObjects')
		expect(entry).toBeDefined()
		expect(entry.label).toBe('Objects')
		const orderOf = (route) =>
			manifest.menu.find((item) => item.route === route).order
		expect(entry.order).toBeGreaterThan(orderOf('Cases'))
		expect(entry.order).toBeLessThan(orderOf('Tasks'))
	})

	it('names an icon that src/icons.js registers', () => {
		const entry = manifest.menu.find((item) => item.route === 'CaseObjects')
		expect(iconsSource).toMatch(new RegExp(`\\b${entry.icon}\\b`))
		for (const action of page().config.actions) {
			expect(iconsSource).toMatch(new RegExp(`\\b${action.icon}\\b`))
		}
	})
})
