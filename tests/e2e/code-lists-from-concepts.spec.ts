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
 * These tests read the stored definition back through the API rather than
 * trusting the form, because what a case type ends up holding is the whole
 * point of the binding.
 *
 * ⚠️ NOT RUN LOCALLY. There is no Playwright run on this box and no instance
 * to run against, so these are written and tagged, not executed, and no
 * mutation check backs them yet. The ids they target (`#pd-add-concept-scheme`,
 * `#pd-add-enum`) and the testids (`concept-scheme-warning`,
 * `property-scheme`) are introduced by this same change in
 * `PropertyDefinitionFields.vue` and `PropertiesTab.vue`, which is what makes
 * them targetable at all.
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

	/**
	 * The stored definition with this name, read back through the API.
	 *
	 * @param request The Playwright request context.
	 * @param name The definition name.
	 * @return The stored definition.
	 */
	async function storedDefinition(request, name: string) {
		const token = await getRequestToken(request)
		const rows = await listObjects(request, token, 'propertyDefinition', {
			caseType: caseTypeId,
		})
		const stored = rows.find((row: any) => row.name === name)
		if (!stored) {
			throw new Error(`no stored definition named ${name}`)
		}
		return stored
	}

	// @e2e openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md#options-come-from-the-scheme
	test('options come from the scheme', async ({ page, request }) => {
		const tab = await openProperties(page)
		const name = `${RUN_PREFIX} Wijk`
		await addDefinition(tab, name, async () => {
			await tab.locator('#pd-add-concept-scheme').fill(SCHEME)
		})

		const stored = await storedDefinition(request, name)
		expect(stored.conceptScheme).toBe(SCHEME)
		expect(stored.enumValues ?? []).toEqual([])

		const row = tab.locator('.property-row', { hasText: name })
		await expect(row.locator('[data-testid="property-scheme"]')).toContainText(
			SCHEME,
		)
	})

	// @e2e openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md#inline-lists-still-work
	test('inline lists still work', async ({ page, request }) => {
		const tab = await openProperties(page)
		const name = `${RUN_PREFIX} Kanaal`
		await addDefinition(tab, name, async () => {
			await tab.getByText('Limit answers to a list').click()
			await tab.locator('#pd-add-enum').fill('Balie\nTelefoon')
		})

		const stored = await storedDefinition(request, name)
		expect(stored.enumValues).toEqual(['Balie', 'Telefoon'])
		expect(stored.conceptScheme ?? '').toBe('')

		const row = tab.locator('.property-row', { hasText: name })
		await expect(
			row.locator('[data-testid="property-scheme"]'),
		).toHaveCount(0)
	})

	// @e2e openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md#options-come-from-the-scheme
	test('a field carrying both is told which one wins', async ({ page }) => {
		const tab = await openProperties(page)
		await tab.locator('.pd-fields .NcTextField').first().fill(
			`${RUN_PREFIX} Reden`,
		)
		await tab.getByText('Limit answers to a list').click()
		await tab.locator('#pd-add-enum').fill('Te laat')
		await tab.locator('#pd-add-concept-scheme').fill('redenen')

		await expect(
			tab.locator('[data-testid="concept-scheme-warning"]'),
		).toContainText('The scheme wins')
	})
})
