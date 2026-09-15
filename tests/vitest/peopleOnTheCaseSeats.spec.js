/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two seats, as the People tab declares them.
 *
 * Everything this half of the change ships on the front end is config: a
 * section, four fields and two overrides. Config fails the way config fails.
 * A field bound to a property the schema does not declare renders an em-dash
 * forever, and a section that reads `assignee` as editable when the schema
 * calls it read-only renders nothing at all. Neither raises an error anywhere
 * in the build, so this file is the check that turns them into failing tests.
 *
 * 🔑 THE ASSERTION THAT MATTERS IS THE ABSENCE. The coordinator must NOT be a
 * field on this section, because it is a role binding (D-4) and renders in
 * Parties with its role type. A second copy here would look right and drift,
 * and nothing in the build would notice.
 *
 * Both files are read from disk rather than imported, so the assertions cover
 * the on-disk config the manifest renderer actually reads.
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

const REGISTER_PATH = path.resolve(
	__dirname,
	'../../lib/Settings/dossiq_register.json',
)

const loadJson = (filePath) => JSON.parse(fs.readFileSync(filePath, 'utf8'))

/**
 * One schema of the shipped dossiq register.
 *
 * @param {string} slug The schema slug.
 * @return {object} The schema entry.
 */
function schema(slug) {
	return loadJson(REGISTER_PATH).components.schemas[slug]
}

/**
 * One section of the People panel.
 *
 * @param {string} label The section label.
 * @return {object|undefined} The section's widget.
 */
function section(label) {
	const panel = panels
		.pageConfig('CaseDetail')
		.widgets.find((entry) => entry.id === 'case-people-panel')
	return panel.content.sections.find((one) => one.label === label)?.widget
}

describe('the Seats section', () => {
	it('shows the handler and the team the case sits with', () => {
		const seats = section('Seats')

		expect(seats).toBeDefined()
		expect(seats.type).toBe('data')
		expect(seats.content.include).toEqual([
			'assignee',
			'assignedGroup',
			'handoverPending',
			'handoverTo',
		])
	})

	it('does not repeat the coordinator, which is a role binding', () => {
		const seats = section('Seats')

		expect(seats.content.include).not.toContain('coordinator')
		expect(Object.keys(schema('case').properties)).not.toContain('coordinator')
	})

	it('keeps the Parties section, where the coordinator actually renders', () => {
		const parties = section('Parties')

		expect(parties).toBeDefined()
		expect(parties.integrationId).toBe('contacts')
	})

	it('shows an empty seat rather than hiding the row', () => {
		// The absence IS the answer a teamleider is looking for, so hideEmpty
		// must stay off. A section that hid an unfilled seat would answer
		// "there is no such field" to the question "who is on this case".
		expect(section('Seats').content.hideEmpty).toBe(false)
	})

	it('shows the pending handover without offering to edit it', () => {
		const overrides = section('Seats').content.overrides

		for (const key of ['handoverPending', 'handoverTo']) {
			expect(overrides[key].editable).toBe(false)
			// fieldsFromSchema DROPS a readOnly property outright unless an
			// override re-admits it, which is the trap `identifier` records on
			// the Core case data section. Shown, never typed.
			expect(overrides[key].readOnly).toBe(false)
		}
	})

	it('binds every field to a property the case schema declares', () => {
		const properties = schema('case').properties

		for (const field of section('Seats').content.include) {
			expect(properties[field], `case.${field} must exist`).toBeDefined()
		}
	})
})

describe('the coordinator seat in the register', () => {
	it('is a role type value, not a case field', () => {
		const generic = schema('roleType').properties.genericRole

		expect(generic.enum).toContain('coordinator')
	})

	it('lets a case type ask for the second seat before signing', () => {
		const declaration
			= schema('caseType').properties.coordinatorRequiredBeforeSigning

		expect(declaration).toBeDefined()
		expect(declaration.type).toBe('boolean')
		// Default OFF. A melding openbare ruimte that insists on two people is
		// a product nobody uses, and this is the organisation's rule rather
		// than the law's.
		expect(declaration.default).toBe(false)
	})

	it('is offered on the case type page, so somebody can turn it on', () => {
		const core = panels
			.pageConfig('CaseTypeDetail')
			.widgets.find((widget) => widget.id === 'case-type-core')

		expect(core.content.include).toContain('coordinatorRequiredBeforeSigning')
	})
})
