/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Communication tab's manifest-to-schema contract.
 *
 * An `open-form` header action saves straight to
 * /apps/openregister/api/objects: no dossiq service runs on that path, so the
 * required properties a handler is not asked for have to arrive from the
 * action's `props`. Miss one and OpenRegister answers 400 with the required
 * list — at runtime, on a button nobody clicks in CI. Nothing else in the
 * build compares the two files, so this is the check that turns that into a
 * failing test the moment the schema gains a required property.
 *
 * Both files are read from disk rather than imported, so the assertions cover
 * the on-disk config the importer and the manifest renderer actually read.
 *
 * @spec openspec/changes/contact-moments/specs/kcc-werkplek-zaaksysteem-bridge/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

const MANIFEST_PATH = path.resolve(__dirname, '../../src/manifest.json')
const KCC_FRAGMENT_PATH = path.resolve(
	__dirname,
	'../../lib/Settings/register.d/40-kcc-werkplek.json',
)

const loadJson = (filePath) => JSON.parse(fs.readFileSync(filePath, 'utf8'))

/**
 * The CaseDetail page as the manifest declares it.
 *
 * @return {object} The page entry.
 */
function caseDetail() {
	return loadJson(MANIFEST_PATH).pages.find((page) => page.id === 'CaseDetail')
}

/**
 * The contactmoment schema as the register fragment declares it.
 *
 * @return {object} The schema entry.
 */
function contactmoment() {
	return loadJson(KCC_FRAGMENT_PATH).components.schemas.contactmoment
}

/**
 * One widget of the CaseDetail page.
 *
 * @param {string} id The manifest widget id.
 * @return {object} The widget entry.
 */
function widget(id) {
	// Through the shared helper and not `config.widgets`: since the strip came
	// down from fourteen tabs to six, this widget is a SECTION of a tab, so a
	// top-level `find` returns undefined and every assertion reads as "the
	// widget was deleted".
	return panels.caseWidget(id)
}

/**
 * One header action of the CaseDetail page.
 *
 * @param {string} id The manifest action id.
 * @return {object} The action entry.
 */
function action(id) {
	return caseDetail().config.headerActions.find((entry) => entry.id === id)
}

describe('the Communication widget', () => {
	it('lists contact moments filtered on the open case', () => {
		const communication = widget('case-communication')

		expect(communication).toBeTruthy()
		expect(communication.type).toBe('object-list')
		expect(communication.content.register).toBe('dossiq')
		expect(communication.content.schema).toBe('contactmoment')
		expect(communication.content.filter).toEqual({ case: '@objectId' })
		expect(communication.content.sort).toEqual({
			field: 'startTime',
			dir: 'desc',
		})
	})

	it('reads only properties the contactmoment schema declares', () => {
		const properties = Object.keys(contactmoment().properties)
		const communication = widget('case-communication')

		for (const key of Object.keys(communication.content.filter)) {
			expect(properties, `filter key ${key}`).toContain(key)
		}
		for (const column of communication.content.columns) {
			expect(properties, `column ${column.key}`).toContain(column.key)
		}
		expect(properties, 'the sort field').toContain(
			communication.content.sort.field,
		)
	})

	it('is the contact half of the People tab, never a layout cell of its own', () => {
		const detail = caseDetail()
		const where = panels.caseTabOf('case-communication')

		// A contactmoment records a channel, a direction and a summary against a
		// person, so it sits with the parties rather than in a tab of its own.
		expect(
			where,
			'case-communication is not reachable from the strip',
		).toBeTruthy()
		expect(where.tab).toBe('People')
		expect(where.label).toBe('Communication')
		expect(panels.caseTabOf('case-roles').tab).toBe(where.tab)

		// A widget rendered by the tabs widget AND placed in `layout` renders
		// twice, which is why its siblings are absent from `layout` too.
		expect(
			(detail.config.layout || []).map((cell) => cell.widgetId),
		).not.toContain('case-communication')
	})
})

describe('the Log contact action', () => {
	it('opens a contactmoment form asking for the handler fields only', () => {
		const logContact = action('log-contact')

		expect(logContact).toBeTruthy()
		expect(logContact.type).toBe('open-form')
		expect(logContact.register).toBe('dossiq')
		expect(logContact.schema).toBe('contactmoment')
		expect(logContact.includeFields).toEqual([
			'notificationChannel',
			'direction',
			'startTime',
			'summary',
			'callerIdentification',
		])
	})

	it('seeds the case it was opened from', () => {
		expect(action('log-contact').props.case).toBe('@objectId')
		expect(action('log-contact').props.relatedCases).toEqual(['@objectId'])
	})

	it('seeds every required property the form does not ask for', () => {
		const logContact = action('log-contact')
		const required = contactmoment().required
		const asked = new Set(logContact.includeFields)
		const seeded = new Set(Object.keys(logContact.props))

		const missing = required.filter(
			(key) => asked.has(key) === false && seeded.has(key) === false,
		)
		expect(
			missing,
			'a required property that is neither asked nor seeded makes every save from this button a 400',
		).toEqual([])
	})

	it('asks for or seeds nothing the schema does not declare', () => {
		const logContact = action('log-contact')
		const properties = Object.keys(contactmoment().properties)

		for (const key of [
			...logContact.includeFields,
			...Object.keys(logContact.props),
		]) {
			expect(properties, `${key} is not a contactmoment property`).toContain(
				key,
			)
		}
	})
})
