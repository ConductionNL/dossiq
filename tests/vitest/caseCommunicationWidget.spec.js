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

	it('is the Communication tab, never a layout cell of its own', () => {
		const detail = caseDetail()
		const where = panels.caseTabOf('case-communication')

		// It sat with the parties until 2026-09-13, on the reasoning that a
		// contactmoment records a channel, a direction and a summary against a
		// person. It is its own tab now, so a handler reaches the case log
		// without opening the party list first, and the two are asserted apart
		// rather than together.
		expect(
			where,
			'case-communication is not reachable from the strip',
		).toBeTruthy()
		expect(where.tab).toBe('Communication')
		expect(where.label).toBe('Communication')
		expect(panels.caseTabOf('case-parties').tab).toBe('People')

		// A widget rendered by the tabs widget AND placed in `layout` renders
		// twice, which is why its siblings are absent from `layout` too.
		expect(
			(detail.config.layout || []).map((cell) => cell.widgetId),
		).not.toContain('case-communication')
	})
})

describe('the Log contact action', () => {
	// parties-and-contact-moments-consume-pipelinq 2.5 (board
	// DqZaakContactmomenten): the action used to be an `open-form` that saved
	// straight to OpenRegister, so no dossiq service ran, the moment never
	// reached pipelinq and pipelinq's refusal could not be shown. It opens a
	// dossiq dialog that posts to the case route, where ContactMomentService
	// writes the record and seeds the KCC fields the form never asked.
	it('opens the dossiq dialog, not a form that saves past the service', () => {
		const logContact = action('log-contact')

		expect(logContact).toBeTruthy()
		expect(logContact.type).toBe('open-modal')
		expect(logContact.target).toBe('LogContactDialog')
		expect(
			logContact.register,
			'an open-modal action writes nothing itself',
		).toBeUndefined()
	})

	it('hands the dialog the case it was opened from', () => {
		expect(action('log-contact').props.caseId).toBe('@objectId')
	})

	it('posts to the case route that runs ContactMomentService', () => {
		const dialog = fs.readFileSync(
			path.resolve(__dirname, '../../src/dialogs/LogContactDialog.vue'),
			'utf8',
		)
		const api = fs.readFileSync(
			path.resolve(__dirname, '../../src/services/pipelinqCaseApi.js'),
			'utf8',
		)
		const routes = fs.readFileSync(
			path.resolve(__dirname, '../../appinfo/routes.php'),
			'utf8',
		)

		expect(dialog).toContain('logContactMoment(')
		expect(api).toContain(
			"axios.post(caseUrl(caseId, '/contact-moments'), moment)",
		)
		expect(routes).toMatch(
			/pipelinqCase#logContactMoment'[^\n]*\/api\/cases\/\{caseId\}\/pipelinq\/contact-moments'[^\n]*'POST'/,
		)
	})
})
