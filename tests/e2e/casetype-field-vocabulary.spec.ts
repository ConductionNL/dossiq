/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case-type field can be what the engine says it can be.
 *
 * The Properties tab offered eight types. OpenRegister validates nineteen and
 * publishes the list at `/apps/openregister/api/schemas/property-vocabulary`,
 * so what an administrator may declare is now read from the instance rather
 * than typed into dossiq. These tests declare a field of each kind an
 * administrator could not declare before, then read the stored definition back
 * through the API, because the point is what the case type ends up holding.
 *
 * ⚠️ NOT RUN LOCALLY. There is no Playwright run on this box and no instance
 * to run it against, so these are written and tagged, not executed, and no
 * mutation check backs them yet. Treat the selectors as unverified until the
 * first CI run; the ids they target (`#pd-add-type`, `#pd-add-format`,
 * `#pd-add-items`, `#pd-add-enum`) are introduced by this same change in
 * `PropertyDefinitionFields.vue`, which is what makes them targetable at all.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	trackCreatedObject,
} from './helpers/fixtures.ts'

const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'
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
		await cleanupRunObjects(request)
	})

	/**
	 * Open the Properties tab of the seeded case type.
	 *
	 * @param page The Playwright page.
	 * @return The Properties tab root.
	 */
	async function openProperties(page) {
		await page.goto(ADMIN_SETTINGS_URL)
		const admin = page.locator('.case-type-admin')
		await admin
			.locator('[data-testid="cn-object-row"]', { hasText: CASE_TYPE_TITLE })
			.first()
			.click()
		const detail = page.locator('.case-type-detail')
		await expect(detail).toBeVisible({ timeout: 30_000 })
		await detail
			.locator('.case-type-detail__tab', { hasText: /^Properties$/ })
			.click()
		const tab = page.locator('.properties-tab')
		await expect(tab).toBeVisible({ timeout: 30_000 })
		return tab
	}

	/**
	 * Fill the add form and save one definition.
	 *
	 * @param tab The Properties tab root.
	 * @param name The definition name.
	 * @param fill What to do with the form before saving.
	 */
	async function addDefinition(tab, name: string, fill: () => Promise<void>) {
		await tab.locator('.pd-fields .NcTextField').first().fill(name)
		await fill()
		await tab.getByRole('button', { name: /^Add$/ }).click()
		await expect(tab.locator('.property-row', { hasText: name })).toBeVisible({
			timeout: 30_000,
		})
	}

	// @e2e openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md#an-administrator-declares-a-set-valued-answer
	test('an administrator declares a set-valued answer', async ({
		page,
		request,
	}) => {
		const tab = await openProperties(page)
		const name = `${RUN_PREFIX} Bijlagen`
		await addDefinition(tab, name, async () => {
			await tab.locator('#pd-add-type').selectOption('array')
			await tab.locator('#pd-add-items').selectOption('string')
		})

		const token = await getRequestToken(request)
		const stored = (
			await listObjects(request, token, 'propertyDefinition', {
				caseType: caseTypeId,
			})
		).find((row: any) => row.name === name)
		expect(stored.propertyType).toBe('array')
		expect(stored.items).toEqual({ type: 'string' })
	})

	// @e2e openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md#a-document-is-the-value-of-a-field
	test('a document is the value of a field', async ({ page, request }) => {
		const tab = await openProperties(page)
		const name = `${RUN_PREFIX} Situatietekening`
		await addDefinition(tab, name, async () => {
			await tab.locator('#pd-add-type').selectOption('file')
		})

		const token = await getRequestToken(request)
		const stored = (
			await listObjects(request, token, 'propertyDefinition', {
				caseType: caseTypeId,
			})
		).find((row: any) => row.name === name)
		expect(stored.propertyType).toBe('file')
	})

	// @e2e openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md#a-multi-line-text-field-is-declared
	test('a multi-line text field is declared', async ({ page, request }) => {
		const tab = await openProperties(page)
		const name = `${RUN_PREFIX} Toelichting`
		await addDefinition(tab, name, async () => {
			await tab.locator('#pd-add-type').selectOption('string')
			await tab.locator('#pd-add-format').selectOption('markdown')
		})

		const token = await getRequestToken(request)
		const stored = (
			await listObjects(request, token, 'propertyDefinition', {
				caseType: caseTypeId,
			})
		).find((row: any) => row.name === name)
		expect(stored.propertyType).toBe('string')
		expect(stored.format).toBe('markdown')
	})

	// @e2e openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md#a-value-outside-the-declared-range-is-refused
	test('a value outside the declared range is refused by the engine', async ({
		page,
		request,
	}) => {
		const tab = await openProperties(page)
		const name = `${RUN_PREFIX} Aantal woningen`
		await addDefinition(tab, name, async () => {
			await tab.locator('#pd-add-type').selectOption('integer')
			await tab.locator('.pd-fields__field--small input').nth(0).fill('1')
			await tab.locator('.pd-fields__field--small input').nth(1).fill('10')
		})

		const token = await getRequestToken(request)
		const stored = (
			await listObjects(request, token, 'propertyDefinition', {
				caseType: caseTypeId,
			})
		).find((row: any) => row.name === name)
		expect(stored.minimum).toBe(1)
		expect(stored.maximum).toBe(10)
		// The refusal itself is OpenRegister's: dossiq declares the range and
		// writes no validator, so a dossiq-side assertion here would be
		// asserting a check dossiq does not perform.
	})

	// @e2e openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md#an-administrator-fills-the-choice-list
	// @e2e openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md#an-empty-choice-list-is-refused-rather-than-shipped
	test('a choice list is filled, and an empty one is refused', async ({
		page,
		request,
	}) => {
		const tab = await openProperties(page)
		const name = `${RUN_PREFIX} Cadans`

		// An empty list is refused before anything is saved.
		await tab.locator('.pd-fields .NcTextField').first().fill(name)
		await tab.locator('.NcCheckboxRadioSwitch').first().click()
		await tab.getByRole('button', { name: /^Add$/ }).click()
		await expect(tab.locator('.field-error')).toContainText(
			'choice list needs values',
		)

		await tab
			.locator('#pd-add-enum')
			.fill('wekelijks\nmaandelijks\nper kwartaal')
		await tab.getByRole('button', { name: /^Add$/ }).click()
		await expect(tab.locator('.property-row', { hasText: name })).toBeVisible({
			timeout: 30_000,
		})

		const token = await getRequestToken(request)
		const stored = (
			await listObjects(request, token, 'propertyDefinition', {
				caseType: caseTypeId,
			})
		).find((row: any) => row.name === name)
		expect(stored.enumValues).toEqual([
			'wekelijks',
			'maandelijks',
			'per kwartaal',
		])
	})

	// @e2e openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md#a-fee-is-computed-from-two-other-fields
	test('a fee declares a calculation the engine evaluates', async ({
		page,
		request,
	}) => {
		const tab = await openProperties(page)
		const name = `${RUN_PREFIX} Leges`
		await addDefinition(tab, name, async () => {
			await tab.locator('#pd-add-type').selectOption('number')
			await tab.locator('.pd-fields__more summary').click()
			await tab
				.locator('#pd-add-calculation')
				.fill('{"op":"multiply","left":"aantal","right":"tarief"}')
		})

		const token = await getRequestToken(request)
		const stored = (
			await listObjects(request, token, 'propertyDefinition', {
				caseType: caseTypeId,
			})
		).find((row: any) => row.name === name)
		expect(stored.calculation).toEqual({
			op: 'multiply',
			left: 'aantal',
			right: 'tarief',
		})
	})

	// @e2e openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md#a-case-type-declares-a-second-address-field
	test('a second address field declares its register, and dossiq resolves nothing', async ({
		page,
		request,
	}) => {
		const tab = await openProperties(page)
		const name = `${RUN_PREFIX} Tweede adres`
		await addDefinition(tab, name, async () => {
			await tab.locator('.pd-fields__more summary').click()
			await tab
				.locator('.pd-fields__more .NcTextField')
				.filter({ hasText: '' })
				.last()
				.fill('bag')
		})

		const token = await getRequestToken(request)
		const stored = (
			await listObjects(request, token, 'propertyDefinition', {
				caseType: caseTypeId,
			})
		).find((row: any) => row.name === name)
		expect(stored.propertySource).toBe('bag')
	})

	// @e2e openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md#a-case-type-authored-on-a-wider-vocabulary-opens-safely
	test('a type this instance does not know keeps its value', async ({
		page,
		request,
	}) => {
		const token = await getRequestToken(request)
		const name = `${RUN_PREFIX} Route`
		const created = await createObject(request, token, 'propertyDefinition', {
			name,
			caseType: caseTypeId,
			propertyType: 'polyline',
			defaultValue: '52.1,5.3',
		})
		trackCreatedObject('propertyDefinition', objectId(created))

		const tab = await openProperties(page)
		const row = tab.locator('.property-row', { hasText: name })
		await expect(row).toContainText('polyline')
		await row.getByRole('button', { name: new RegExp(`Edit ${name}`) }).click()
		await expect(tab.locator('.properties-tab__error')).toContainText('polyline')

		const stored = (
			await listObjects(request, token, 'propertyDefinition', {
				caseType: caseTypeId,
			})
		).find((entry: any) => entry.name === name)
		expect(stored.propertyType).toBe('polyline')
		expect(stored.defaultValue).toBe('52.1,5.3')
	})
})
