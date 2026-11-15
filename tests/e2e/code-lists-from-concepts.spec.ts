/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A choice list is authored once and bound, not retyped per case type.
 *
 * Every choice list used to be typed into the attribute that uses it, so two
 * case types both asking for a wijk each carried their own copy and the two
 * drifted. OpenRegister holds those lists as SKOS concept schemes; a property
 * points at one with `conceptScheme` and the scheme rules over any inline
 * list still on the field.
 *
 * These tests drive the case type's field form, the manifest's
 * `case-type-fields-editor` form (see helpers/field-form.ts), and read the
 * stored definition back through the API rather than trusting the form,
 * because what a case type ends up holding is the whole point of the binding.
 *
 * ⚠️ NOT RUN LOCALLY. There is no Playwright run on this box, so the
 * selectors are unverified until the first run.
 */

import { expect, test } from '@playwright/test'
import {
	choiceListOn,
	create,
	field,
	fillChoices,
	openAddField,
	openFieldsTab,
} from './helpers/field-form.ts'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	trackCreatedObject,
} from './helpers/fixtures.ts'

const CASE_TYPE_TITLE = `${RUN_PREFIX} Wijkmelding`
const SCHEME = 'wijken'

test.describe('code lists from concepts', () => {
	test.setTimeout(300_000)

	let caseTypeId = ''

	test.beforeAll(async ({ request }) => {
		const token = await getRequestToken(request)
		const caseType = await createObject(request, token, 'caseType', {
			title: CASE_TYPE_TITLE,
			description: 'Concept scheme binding run',
			isDraft: true,
		})
		caseTypeId = objectId(caseType)
		trackCreatedObject('caseType', caseTypeId)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, await getRequestToken(request))
	})

	/**
	 * Open the Add field form on the seeded case type, with the name filled.
	 *
	 * @param page The Playwright page.
	 * @param name The field name.
	 * @return The field list and the open form.
	 */
	async function startField(page, name: string) {
		const list = await openFieldsTab(page, CASE_TYPE_TITLE)
		const dialog = await openAddField(page, list)
		await field(dialog, 'name').locator('input').first().fill(name)
		return { list, dialog }
	}

	/**
	 * The stored definition with this name, read back through the API.
	 *
	 * @param request The Playwright request context.
	 * @param name The definition name.
	 * @return The stored definition.
	 */
	async function storedDefinition(request, name: string) {
		const rows = await listObjects(request, 'propertyDefinition', {
			caseType: caseTypeId,
		})
		const stored = rows.find((row: any) => row.name === name)
		if (!stored) {
			throw new Error(`no stored definition named ${name}`)
		}
		return stored
	}

	// @e2e openspec/specs/property-definition-management/spec.md#options-come-from-the-scheme
	test('options come from the scheme', async ({ page, request }) => {
		const name = `${RUN_PREFIX} Wijk`
		const { dialog } = await startField(page, name)
		// The scheme sits with the choices, behind the Choice list switch, and
		// makes the choices optional.
		await choiceListOn(dialog)
		await field(dialog, 'conceptScheme').locator('input').first().fill(SCHEME)
		await create(dialog)

		const stored = await storedDefinition(request, name)
		expect(stored.conceptScheme).toBe(SCHEME)
		expect(stored.enumValues ?? []).toEqual([])
	})

	// @e2e openspec/specs/property-definition-management/spec.md#inline-lists-still-work
	test('inline lists still work', async ({ page, request }) => {
		const name = `${RUN_PREFIX} Kanaal`
		const { dialog } = await startField(page, name)
		await choiceListOn(dialog)
		await fillChoices(dialog, ['Balie', 'Telefoon'])
		await create(dialog)

		const stored = await storedDefinition(request, name)
		expect(stored.enumValues).toEqual(['Balie', 'Telefoon'])
		expect(stored.conceptScheme ?? '').toBe('')
	})

	// @e2e openspec/specs/property-definition-management/spec.md#options-come-from-the-scheme
	test('a field carrying both is told which one wins', async ({ page }) => {
		const { dialog } = await startField(page, `${RUN_PREFIX} Reden`)
		await choiceListOn(dialog)
		await fillChoices(dialog, ['Te laat'])
		const scheme = field(dialog, 'conceptScheme').locator('input').first()
		await scheme.fill('redenen')
		await scheme.press('Tab')

		await expect(field(dialog, 'enumValues')).toContainText(
			/The concept scheme wins over these choices|Het begrippenkader gaat voor deze keuzes/,
		)
	})
})
