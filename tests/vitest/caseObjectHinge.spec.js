/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case object as a hinge: what it borrows from the object it names,
 * how it lists, what names it in the reverse view, and what a case location
 * inherits.
 *
 * ALL OF IT IS A DECLARATION, WHICH IS EXACTLY WHY IT IS ASSERTED HERE.
 * OpenRegister reports a malformed hinge annotation as a WARNING in the log
 * and then ignores it, so a lens looking through a property nobody declared
 * renders an empty column for ever and nothing in CI says a word. These
 * assertions are the reader those declarations would otherwise lack.
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const register = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'dossiq_register.json'), 'utf8'),
)
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const cellWidgetsSource = fs.readFileSync(
	path.join(ROOT, 'src', 'services', 'cellWidgets.js'), 'utf8',
)
const panels = require('./helpers/casePanels.js')

/** The `caseObject` schema. @return {object} The schema. */
const caseObject = () => register.components.schemas.caseObject

/** The `case-location` schema. @return {object} The schema. */
const caseLocation = () => register.components.schemas['case-location']

/** The declared lenses of `caseObject`. @return {object} The lens map. */
const lenses = () => caseObject().configuration['x-openregister-lenses']

/** The declared list surface of `caseObject`. @return {object} The list block. */
const listSurface = () => caseObject().configuration['x-openregister-list']

/** The Objects tab widget. @return {object} The widget. */
const objectsWidget = () => panels.caseWidget('case-objects')

/** The Objects index page. @return {object} The page. */
const objectsIndex = () => manifest.pages.find((page) => page.id === 'CaseObjects')

describe('the lenses on caseObject', () => {
	it('declares a lens onto the linked object title and status', () => {
		expect(Object.keys(lenses()).sort()).toEqual(['objectStatus', 'objectTitle'])
	})

	it('looks through a property the schema actually declares', () => {
		// OpenRegister's HingeAnnotationValidator reports `lens-unknown-through`
		// as a log warning and drops the lens. The column is then empty on every
		// row, which is indistinguishable from an object with no title.
		for (const [name, lens] of Object.entries(lenses())) {
			expect(
				caseObject().properties[lens.through],
				`the ${name} lens looks through ${lens.through}, which is not a property`,
			).toBeDefined()
		}
	})

	it('names a property to read on the linked object', () => {
		for (const [name, lens] of Object.entries(lenses())) {
			expect(lens.property, `the ${name} lens reads no property`).toBeTruthy()
		}
	})

	it('shadows no stored property, or every read would overwrite one', () => {
		// `lens-shadows-property`: a lens is applied over the rendered data, so a
		// lens named after a stored field replaces that field's value on every
		// read and the stored one becomes unreachable.
		for (const name of Object.keys(lenses())) {
			expect(
				caseObject().properties[name],
				`the ${name} lens has the name of a stored property`,
			).toBeUndefined()
		}
	})

	it('is shown on the Objects tab through the withheld-aware cell', () => {
		// The marker is an object. The default cell prints `[object Object]` and
		// an empty cell states that the case is about nothing, so both defaults
		// are wrong in the same direction.
		const columns = objectsWidget().content.columns
		for (const name of Object.keys(lenses())) {
			const column = columns.find((entry) => entry.key === name)
			expect(column, `the ${name} lens is not a column on the Objects tab`).toBeDefined()
			expect(column.widget).toBe('lensedValue')
		}
	})

	it('names a cell widget the app registers', () => {
		expect(cellWidgetsSource).toMatch(/\blensedValue:/)
	})
})

describe('the declared list surface of caseObject', () => {
	it('declares columns and search fields', () => {
		expect(listSurface().columns.length).toBeGreaterThan(0)
		expect(listSurface().searchFields.length).toBeGreaterThan(0)
	})

	it('names only properties the schema declares', () => {
		// `list-column-unknown` and `list-search-field-unknown` are warnings in
		// OpenRegister, so a mistyped column ships a generic list with a heading
		// over an empty column.
		const known = (name) => caseObject().properties[String(name).split('.')[0]]
		for (const column of listSurface().columns) {
			expect(known(column.property), `list column ${column.property}`).toBeDefined()
		}
		for (const field of listSurface().searchFields) {
			expect(known(field), `search field ${field}`).toBeDefined()
		}
	})

	it('is what the Objects index shows, in the declared order', () => {
		// A declaration nothing reads is the shape this app keeps being caught
		// by. The index has one column of its own, the case, and it comes last.
		const declared = listSurface().columns.map((column) => column.property)
		const shown = objectsIndex().config.columns.map((column) => column.key)
		expect(shown.slice(0, declared.length)).toEqual(declared)
	})
})

describe('what names a case object', () => {
	it('is the case, so the reverse view on the object reads as a list of cases', () => {
		expect(caseObject().configuration.objectNameField).toBe('caseTitle')
	})

	it('declares caseTitle as a property, or the name field points at nothing', () => {
		expect(caseObject().properties.caseTitle).toBeDefined()
		expect(caseObject().properties.caseTitle.readOnly).toBe(true)
	})

	it('calculates it from the case, through a declared reference', () => {
		const references = caseObject().configuration['x-openregister-references']
		expect(references.case.schema).toBe('case')
		expect(references.case.field).toBe('case')
		expect(caseObject().properties[references.case.field]).toBeDefined()
	})

	it('materialises it, because a name is read by a list and not by a render', () => {
		const calculation = caseObject().configuration['x-openregister-calculations'].caseTitle
		expect(calculation.materialise).toBe(true)
	})

	it('coalesces to a property the schema requires, so no row is unnamed', () => {
		// A name field pointing at a calculation that resolves to nothing is a
		// blank name on every row, which is worse than the uuid it replaced.
		const operands = caseObject().configuration['x-openregister-calculations']
			.caseTitle.expression.coalesce.map((operand) => operand.prop)
		expect(operands[0]).toBe('@ref.case.title')
		expect(caseObject().required).toContain(operands.at(-1))
	})
})

describe('what a case location inherits', () => {
	it('declares the reference it inherits map features through', () => {
		const sources = caseLocation().configuration['x-openregister-geo-inheritance'].from
		expect(sources.length).toBeGreaterThan(0)
		for (const source of sources) {
			expect(
				caseLocation().properties[source.through],
				`geo inheritance reads through ${source.through}, which is not a property`,
			).toBeDefined()
		}
	})

	it('labels the relation, so an inherited pin says where it came from', () => {
		for (const source of caseLocation().configuration['x-openregister-geo-inheritance'].from) {
			expect(source.label).toBeTruthy()
		}
	})

	it('points at the object itself, not at the link row', () => {
		// The collector reads the referenced record's OWN features and does not
		// recurse, so a declaration pointing at the caseObject link row would
		// collect the link row's geometry, which is empty, and say nothing.
		const sources = caseLocation().configuration['x-openregister-geo-inheritance'].from
		const property = caseLocation().properties[sources[0].through]
		expect(property.$ref).toBeUndefined()
		expect(property.format).toBe('uri')
	})

	it('bumps both schema versions, or the importer fast-skips them', () => {
		expect(caseObject().version).toBe('1.2.0')
		expect(caseLocation().version).toBe('1.1.0')
	})
})
