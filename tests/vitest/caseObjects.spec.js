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

const register = JSON.parse(fs.readFileSync(REGISTER_PATH, 'utf8'))
const manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const iconsSource = fs.readFileSync(ICONS_PATH, 'utf8')

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
const caseWidget = (id) =>
	caseDetail().config.widgets.find((entry) => entry.id === id)

/** The tab children of the `case-panels` strip. @return {Array<object>} The tabs. */
const panelTabs = () => caseWidget('case-panels').content.tabs

/** One header action of the CaseDetail page. @param {string} id The action id. @return {object|undefined} The action. */
const headerAction = (id) =>
	caseDetail().config.headerActions.find((entry) => entry.id === id)

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
			expect(property.visible, `caseObject.${key} must not be hidden`).not.toBe(
				false,
			)
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

	it('joins the case-panels strip as Objects, after Locations', () => {
		const labels = panelTabs().map((tab) => tab.label)
		expect(labels).toContain('Objects')
		expect(labels.indexOf('Objects')).toBe(labels.indexOf('Locations') + 1)
		expect(panelTabs().at(-1).widgetId).toBe('case-objects')
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
