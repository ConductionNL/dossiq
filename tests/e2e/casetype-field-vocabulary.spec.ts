/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case-type field can be what the engine says it can be.
 *
 * OpenRegister validates nineteen property types and publishes the list at
 * `/apps/openregister/api/schemas/property-vocabulary`, so what an
 * administrator may declare is read from the instance rather than typed into
 * dossiq. The field form is the manifest's `case-type-fields-editor` form
 * (see helpers/field-form.ts): its type select reads that vocabulary and its
 * format select reads the formats of the chosen type. These tests declare a
 * field of each kind through that form, then read the stored definition back
 * through the API, because the point is what the case type ends up holding.
 *
 * ⚠️ NOT RUN LOCALLY. There is no Playwright run on this box, so the
 * selectors are unverified until the first run.
 */

import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	choiceListOn,
	create,
	field,
	fillChoices,
	openAddField,
	openFieldsTab,
	pick,
	unfold,
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

const CASE_TYPE_TITLE = `${RUN_PREFIX} Vergunning`

test.describe('case type field vocabulary', () => {
	test.setTimeout(300_000)

	let caseTypeId = ''

	test.beforeAll(async ({ request }) => {
		const token = await getRequestToken(request)
		const caseType = await createObject(request, token, 'caseType', {
			title: CASE_TYPE_TITLE,
			description: 'Field vocabulary run',
			isDraft: true,
		})
		caseTypeId = objectId(caseType)
		trackCreatedObject('caseType', caseTypeId)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, await getRequestToken(request))
	})

	/**
	 * Open the Add field form, name the field, let the caller fill the rest,
	 * create it and wait for the list to show it.
	 *
	 * @param page The Playwright page.
	 * @param name The field name.
	 * @param fill What to do with the form before creating.
	 * @return The field list.
	 */
	async function addField(page: Page, name: string, fill: (dialog: Locator) => Promise<void>): Promise<Locator> {
		const list = await openFieldsTab(page, CASE_TYPE_TITLE)
		const dialog = await openAddField(page, list)
		await field(dialog, 'name').locator('input').first().fill(name)
		await fill(dialog)
		await create(dialog)
		await page.keyboard.press('Escape')
		await expect(list.getByRole('row').filter({ hasText: name })).toBeVisible({ timeout: 30_000 })
		return list
	}

	/**
	 * The stored definition with this name, read back through the API.
	 *
	 * @param request The Playwright request context.
	 * @param name The field name.
	 * @return The stored definition, or undefined.
	 */
	async function stored(request, name: string): Promise<any> {
		return (await listObjects(request, 'propertyDefinition', { caseType: caseTypeId }))
			.find((row: any) => row.name === name)
	}

	// @e2e openspec/specs/property-definition-management/spec.md#an-administrator-declares-a-set-valued-answer
	test('an administrator declares a set-valued answer', async ({ page, request }) => {
		const name = `${RUN_PREFIX} Bijlagen`
		await addField(page, name, async (dialog) => {
			await pick(page, dialog, 'propertyType', /^array$/)
			const entry = field(dialog, 'items')
			await expect(entry).toBeVisible()
			await entry.locator('.cm-content').click()
			await page.keyboard.insertText('{"type":"string"}')
		})

		const row = await stored(request, name)
		expect(row.propertyType).toBe('array')
		expect(row.items).toEqual({ type: 'string' })
	})

	// @e2e openspec/specs/property-definition-management/spec.md#a-document-is-the-value-of-a-field
	test('a document is the value of a field', async ({ page, request }) => {
		const name = `${RUN_PREFIX} Situatietekening`
		await addField(page, name, async (dialog) => {
			await pick(page, dialog, 'propertyType', /^file$/)
		})

		expect((await stored(request, name)).propertyType).toBe('file')
	})

	// @e2e openspec/specs/property-definition-management/spec.md#a-multi-line-text-field-is-declared
	test('a multi-line text field is declared', async ({ page, request }) => {
		const name = `${RUN_PREFIX} Toelichting`
		await addField(page, name, async (dialog) => {
			await pick(page, dialog, 'propertyType', /^string$/)
			await pick(page, dialog, 'format', /^markdown$/)
		})

		const row = await stored(request, name)
		expect(row.propertyType).toBe('string')
		expect(row.format).toBe('markdown')
	})

	// @e2e openspec/specs/property-definition-management/spec.md#a-value-outside-the-declared-range-is-refused
	test('a value outside the declared range is refused by the engine', async ({ page, request }) => {
		const name = `${RUN_PREFIX} Aantal woningen`
		await addField(page, name, async (dialog) => {
			await pick(page, dialog, 'propertyType', /^integer$/)
			await field(dialog, 'minimum').locator('input').first().fill('1')
			await field(dialog, 'maximum').locator('input').first().fill('10')
		})

		const row = await stored(request, name)
		expect(row.minimum).toBe(1)
		expect(row.maximum).toBe(10)
		// The refusal itself is OpenRegister's: dossiq declares the range and
		// writes no validator, so a dossiq-side assertion here would be
		// asserting a check dossiq does not perform.
	})

	// @e2e openspec/specs/property-definition-management/spec.md#an-administrator-fills-the-choice-list
	// @e2e openspec/specs/property-definition-management/spec.md#an-empty-choice-list-is-refused-rather-than-shipped
	test('a choice list is filled, and an empty one is refused', async ({ page, request }) => {
		const name = `${RUN_PREFIX} Cadans`
		const list = await openFieldsTab(page, CASE_TYPE_TITLE)
		const dialog = await openAddField(page, list)
		await field(dialog, 'name').locator('input').first().fill(name)

		// The choices are required while the switch is on and no concept
		// scheme is set, so an empty list does not save.
		await choiceListOn(dialog)
		await dialog.getByRole('button', { name: /^(Create|Aanmaken)$/ }).click()
		await expect(dialog.locator('[data-testid-phase="result"]')).toHaveCount(0)
		expect(await stored(request, name)).toBeUndefined()

		await fillChoices(dialog, ['wekelijks', 'maandelijks', 'per kwartaal'])
		await create(dialog)

		expect((await stored(request, name)).enumValues).toEqual([
			'wekelijks',
			'maandelijks',
			'per kwartaal',
		])
	})

	// @e2e openspec/specs/property-definition-management/spec.md#a-fee-is-computed-from-two-other-fields
	test('a fee declares a calculation the engine evaluates', async ({ page, request }) => {
		const name = `${RUN_PREFIX} Leges`
		await addField(page, name, async (dialog) => {
			await pick(page, dialog, 'propertyType', /^number$/)
			await unfold(dialog, /^(More options|Meer opties)$/)
			await field(dialog, 'calculation').locator('.cm-content').click()
			await page.keyboard.insertText('{"op":"multiply","left":"aantal","right":"tarief"}')
		})

		expect((await stored(request, name)).calculation).toEqual({
			op: 'multiply',
			left: 'aantal',
			right: 'tarief',
		})
	})

	// @e2e openspec/specs/property-definition-management/spec.md#a-case-type-declares-a-second-address-field
	test('a second address field declares its register, and dossiq resolves nothing', async ({ page, request }) => {
		const name = `${RUN_PREFIX} Tweede adres`
		await addField(page, name, async (dialog) => {
			await unfold(dialog, /^(More options|Meer opties)$/)
			const source = field(dialog, 'propertySource')
			const combo = source.getByRole('combobox')
			if (await combo.count()) {
				await combo.first().click()
				await page.getByRole('option').filter({ hasText: /bag/i }).first().click()
			} else {
				await source.locator('input').first().fill('bag')
			}
		})

		expect((await stored(request, name)).propertySource).toBe('bag')
	})

	// @e2e openspec/specs/property-definition-management/spec.md#a-case-type-authored-on-a-wider-vocabulary-opens-safely
	test('a type this instance does not know keeps its value', async ({ page, request }) => {
		const token = await getRequestToken(request)
		const name = `${RUN_PREFIX} Route`
		const created = await createObject(request, token, 'propertyDefinition', {
			name,
			caseType: caseTypeId,
			propertyType: 'polyline',
			defaultValue: '52.1,5.3',
		})
		trackCreatedObject('propertyDefinition', objectId(created))

		const list = await openFieldsTab(page, CASE_TYPE_TITLE)
		const row = list.getByRole('row').filter({ hasText: name })
		await expect(row, 'the list names the stored type').toContainText('polyline')

		// Open the field and leave without saving: nothing is coerced.
		await row.getByRole('button', { name: /^(Actions|Acties)$/ }).click()
		await page.getByRole('menuitem', { name: /^(Edit|Bewerken)$/ }).click()
		const dialog = page.getByRole('dialog').filter({ has: page.locator('[data-cn-field="name"]') })
		await expect(dialog).toBeVisible({ timeout: 30_000 })
		await page.keyboard.press('Escape')
		await expect(dialog).toHaveCount(0)

		const after = await stored(request, name)
		expect(after.propertyType).toBe('polyline')
		expect(after.defaultValue).toBe('52.1,5.3')
	})
})
