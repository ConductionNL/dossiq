/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case homed in another application, and the handover gesture beside it.
 *
 * The central list has to answer for both kinds of case, which is the whole
 * claim behind "the zaak lives centrally and the work happens elsewhere". On
 * the front end that is three pieces of config: the declaration renders, the
 * index carries both kinds because it filters on neither field, and the
 * handover action is on the page at all.
 *
 * 🔑 THE DISABLING IS NOT ASSERTED HERE, AND THAT IS ON PURPOSE. Whether the
 * lifecycle acts come back blocked is CaseActionProvider's answer, asserted in
 * tests/Unit/Service/ExternallyHomedCaseTest.php against the provider itself
 * (`testTheLifecycleActsAreDisabledAndSayWhy` and
 * `testTakingADisabledActAnywayIsRefused`). A manifest test that claimed it
 * would be a claim about behaviour nothing here runs.
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

const REGISTER_PATH = path.resolve(
	__dirname,
	'../../lib/Settings/dossiq_register.json',
)

const caseSchema = () =>
	JSON.parse(fs.readFileSync(REGISTER_PATH, 'utf8')).components.schemas.case

/**
 * One header action of the CaseDetail page.
 *
 * @param {string} id The action id.
 * @return {object|undefined} The action entry.
 */
function action(id) {
	return panels
		.pageConfig('CaseDetail')
		.headerActions.find((entry) => entry.id === id)
}

describe('the externally homed declaration', () => {
	it('names the application, the identifier there and the link', () => {
		const section = panels.caseWidget('case-external-home')

		expect(section).toBeDefined()
		expect(section.content.include).toEqual([
			'externalApplication',
			'externalIdentifier',
			'externalUrl',
		])
	})

	it('binds all three to properties the case schema declares', () => {
		const properties = caseSchema().properties

		for (const field of ['externalApplication', 'externalIdentifier', 'externalUrl']) {
			expect(properties[field], `case.${field} must exist`).toBeDefined()
		}
	})

	it('offers the link as a link rather than as a string nobody can click', () => {
		expect(panels.caseWidget('case-external-home').content.overrides.externalUrl.widget).toBe('link')
	})

	it('stays out of the way on a case dossiq handles itself', () => {
		// hideEmpty ON, the opposite of the Seats section. Most cases are
		// handled here, and an empty block on every one of them is noise.
		expect(panels.caseWidget('case-external-home').content.hideEmpty).toBe(true)
	})

	it('sits on the case page, so the central list leads to it', () => {
		expect(panels.caseTabOf('case-external-home')).toEqual({
			tab: 'Data',
			label: 'Handled elsewhere',
		})
	})
})

describe('the Cases index', () => {
	it('carries both kinds, because no lens filters on the external home', () => {
		const chips = panels.pageConfig('Cases').quickFilters

		for (const entry of chips) {
			expect(Object.keys(entry.filter)).not.toContain('externalApplication')
		}
	})
})

describe('the handover gesture', () => {
	it('is on the case page and opens the dialog that asks for a reason', () => {
		const hand = action('case-hand-over')

		expect(hand).toBeDefined()
		expect(hand.type).toBe('open-modal')
		expect(hand.target).toBe('CaseHandoverDialog')
	})

	it('is not offered on a closed case', () => {
		expect(action('case-hand-over').visibleWhen).toEqual({
			field: 'isFinalStatus',
			op: 'neq',
			value: true,
		})
	})

	it('is registered, so the button opens something', () => {
		// A target nothing declares renders no modal and no error, which is
		// the silent-modal trap: the gesture would be a button that does
		// nothing on a page that looks finished.
		const registry = fs.readFileSync(
			path.resolve(__dirname, '../../src/registry.js'),
			'utf8',
		)

		expect(registry).toContain('CaseHandoverDialog: {')
		expect(registry).toContain("import CaseHandoverDialog from './dialogs/CaseHandoverDialog.vue'")
	})
})
