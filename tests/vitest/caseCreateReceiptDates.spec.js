// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * No create surface asks when the request came in.
 *
 * Ruben's decision of 10 October 2026: dossiq sets the start date and the
 * receipt date when a case is created (REQ-TERM-042), from the moment the
 * request arrived. A create form asking for either gives a second answer to a
 * question the case already answers, and the term counts from neither typed
 * one. The case page keeps the start date, so a handler can still correct it
 * afterwards.
 *
 * The surfaces are FOUND, not listed: every `includeFields` on a case form in
 * the manifest. The count is asserted too, so a walk that stops finding them
 * cannot pass by checking nothing.
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md
 */
import { describe, expect, it } from 'vitest'
import wooFragment from '../../lib/Settings/register.d/81-woo-verzoek.json'
import wooTemplate from '../../lib/Settings/templates/woo-verzoek.json'
import manifest from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import simpleMenuLayout from '../../src/menu-layout.simple.json'
import { caseCreateFormProps } from '../../src/utils/caseCreateForm.js'

/** The page whose form edits an existing case rather than creating one. */
const EDIT_PAGE = 'CaseDetail'

/**
 * Every case form in the manifest, with where it sits.
 *
 * @return {Array<{where: string, page: string, includeFields: string[]}>} The forms.
 */
function caseForms() {
	const forms = []
	for (const page of manifest.pages) {
		const config = page.config || {}
		if (config.schema === 'case' && Array.isArray(config.includeFields)) {
			forms.push({
				where: `${page.id}.config`,
				page: page.id,
				includeFields: config.includeFields,
			})
		}
		for (const action of config.headerActions || []) {
			const schema = action.schema || config.schema
			if (
				schema === 'case'
				&& action.type === 'open-form'
				&& Array.isArray(action.includeFields)
			) {
				forms.push({
					where: `${page.id}.headerActions.${action.id}`,
					page: page.id,
					includeFields: action.includeFields,
				})
			}
		}
	}
	return forms
}

/**
 * Every value inside a nested structure that is an object with these keys.
 *
 * @param {unknown} node The structure.
 * @param {Function} match Whether an object is wanted.
 * @param {Array<object>} found Collected so far.
 * @return {Array<object>} The matching objects.
 */
function collect(node, match, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => collect(child, match, found))
	} else if (node && typeof node === 'object') {
		if (match(node)) {
			found.push(node)
		}
		Object.values(node).forEach((child) => collect(child, match, found))
	}
	return found
}

describe('the create forms leave the receipt to dossiq', () => {
	const createForms = caseForms().filter((form) => form.page !== EDIT_PAGE)

	it('finds the five create surfaces', () => {
		expect(createForms.map((form) => form.where).sort()).toEqual([
			'Cases.config',
			'ContactDetail.headerActions.new-case-for-contact',
			'Dashboard.headerActions.new-case',
			'MyWorkHome.headerActions.new-case',
			'OrganisationDetail.headerActions.new-case-for-contact',
		])
	})

	it('asks for no start date on any of them', () => {
		const asking = createForms
			.filter((form) => form.includeFields.includes('startDate'))
			.map((form) => form.where)
		expect(asking).toEqual([])
	})

	it('asks for no start date on a New case form a menu layout declares', () => {
		const navForms = [menuLayout, simpleMenuLayout].flatMap((layout) =>
			collect(
				layout,
				(node) =>
					node.type === 'open-form'
					&& Array.isArray(node.includeFields)
					&& node.includeFields.includes('caseType'),
			),
		)
		// The simple profile's sidebar button; a walk finding none checks nothing.
		expect(navForms.length).toBeGreaterThanOrEqual(1)
		for (const form of navForms) {
			expect(form.includeFields).not.toContain('startDate')
		}
	})

	it('asks for no start date on the forms configured from JS', () => {
		expect(caseCreateFormProps().includeFields).not.toContain('startDate')
	})

	it('keeps the start date editable on the case itself', () => {
		const edit = caseForms().find((form) => form.page === EDIT_PAGE)
		expect(edit.includeFields).toContain('startDate')
	})

	it('does not require the Woo receipt question, in the seed or the template', () => {
		const definitions = [
			...collect(
				wooFragment,
				(node) => node.name === 'receiptDate' && 'propertyType' in node,
			),
			...collect(
				wooTemplate,
				(node) => node.name === 'receiptDate' && 'propertyType' in node,
			),
		]
		expect(definitions).toHaveLength(2)
		for (const definition of definitions) {
			expect(definition.isRequired).toBe(false)
		}
	})
})
