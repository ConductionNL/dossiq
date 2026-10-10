/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case types in my menu (board DqPersoonlijkeInstellingen): the section of
 * Nextcloud's personal settings that decides what stands under My case types
 * in dossiq's sidebar.
 *
 * Scenario: A case handler adds, orders and removes case types.
 *
 * THE CHOICE IS REAL STATE FOR THE SIGNED-IN USER. The test writes the list it
 * found back at the end, so a later run starts where this one did.
 *
 * Which case types exist depends on the instance, so the test picks whatever
 * the picker offers first and never names one.
 *
 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md
 */

import { expect, test } from '@playwright/test'

const PERSONAL_SETTINGS = '/settings/user/dossiq'
const API = '/apps/dossiq/api/menu-case-types'

test.describe('case types in my menu', () => {
	let original: string[] = []

	test.beforeEach(async ({ page }) => {
		const response = await page.request.get(`/index.php${API}`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		const body = await response.json()
		original = (body.chosen ?? []).map((caseType: { id: string }) => caseType.id)
	})

	test.afterEach(async ({ page }) => {
		const token = await page.evaluate(
			() =>
				(window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken ?? '',
		)
		await page.request.put(`/index.php${API}`, {
			data: { ids: original },
			headers: { requesttoken: token },
		})
	})

	test('a case handler adds, orders and removes case types', async ({ page }) => {
		await page.goto(PERSONAL_SETTINGS)
		const section = page.getByTestId('menu-case-types')
		await expect(section).toBeVisible()

		// Start from an empty list so the positions below are known.
		const remove = section.getByTestId('menu-case-types-remove')
		while ((await remove.count()) > 0) {
			await remove.first().click()
		}

		// Add two: each goes to the bottom.
		const picker = section.getByTestId('menu-case-types-add')
		for (let i = 0; i < 2; i++) {
			await picker.click()
			const option = page.getByRole('option').first()
			test.skip(
				(await option.count()) === 0,
				'This instance offers fewer than two case types to this user',
			)
			await option.click()
		}
		const rows = section.locator('[data-testid^="menu-case-types-row-"]')
		await expect(rows).toHaveCount(2)
		const first = (await rows.nth(0).innerText()).trim()
		const second = (await rows.nth(1).innerText()).trim()

		// Arrow up on the second handle moves it to the top and says so.
		await section.getByTestId('menu-case-types-move').nth(1).focus()
		await page.keyboard.press('ArrowUp')
		await expect(rows.nth(0)).toContainText(second)
		await expect(section.getByTestId('menu-case-types-status')).toContainText(
			second,
		)

		// The sidebar shows them under My case types, in that order.
		await page.goto('/apps/dossiq/')
		const caption = page.getByTestId('cn-nav-caption-MyCaseTypesCaption')
		await expect(caption).toBeVisible()
		const nav = page.locator('#app-navigation-vue, nav').first()
		await expect(nav.getByRole('link', { name: second })).toBeVisible()
		await expect(nav.getByRole('link', { name: first })).toBeVisible()

		// Removing one takes it off the list and off the sidebar.
		await page.goto(PERSONAL_SETTINGS)
		await section.getByTestId('menu-case-types-remove').first().click()
		await expect(rows).toHaveCount(1)
		await page.goto('/apps/dossiq/')
		await expect(
			page.getByTestId('cn-nav-caption-MyCaseTypesCaption'),
		).toBeVisible()
		await expect(nav.getByRole('link', { name: second })).toHaveCount(0)
	})
})
