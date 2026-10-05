/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The simple structure, in a browser: nine entries under three captions, and
 * the pages that left the menu one link away.
 *
 * The CI instance runs on the full structure (tests/e2e/ci-seed.sh sets it),
 * so this spec turns the setting to `simple` through the same endpoint the
 * admin tab uses, and puts back what it found. The suite runs one worker,
 * serially, so no other spec sees the menu change under it.
 *
 * WHAT WOULD MAKE THIS PASS FOR THE WRONG REASON, and what stops it. A menu
 * that failed to build renders nothing, and "the retired entries are absent"
 * holds on an empty navigation. So the nine entries are asserted PRESENT and
 * in order first, and absence is only read after that.
 */

import { expect, test } from '@playwright/test'
import { getRequestToken } from './helpers/fixtures.ts'

const SETTINGS_API = '/index.php/apps/dossiq/api/settings'

/** The nine entries, in order, each in English and in Dutch. */
const ENTRIES: RegExp[] = [
	/^Dashboard$/i,
	/^(My work|Mijn werk)$/i,
	/^(Team queue|Wachtrij)$/i,
	/^(All cases|Alle zaken)$/i,
	/^(Board|Werkbord)$/i,
	/^(Tasks|Taken)$/i,
	/^(Woo requests|Woo-verzoeken)$/i,
	/^(Contacts|Contacten)$/i,
	/^(Organisations|Organisaties)$/i,
]

test.describe('The simple structure', () => {
	test.setTimeout(300_000)

	let before = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({
			baseURL,
			storageState: 'tests/e2e/.auth/user.json',
		})
		const current = await api.get(SETTINGS_API)
		expect(current.status(), 'GET /api/settings').toBe(200)
		const body = await current.json()
		before = String((body.config ?? body).menu_structure ?? '')

		const saved = await api.post(SETTINGS_API, {
			headers: {
				requesttoken: await getRequestToken(api),
				'Content-Type': 'application/json',
			},
			data: { menu_structure: 'simple' },
		})
		expect(saved.status(), 'POST /api/settings menu_structure=simple').toBe(200)
		expect((await saved.json()).config.menu_structure).toBe('simple')
		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({
			baseURL,
			storageState: 'tests/e2e/.auth/user.json',
		})
		await api.post(SETTINGS_API, {
			headers: {
				requesttoken: await getRequestToken(api),
				'Content-Type': 'application/json',
			},
			// An instance that never set the key gets `simple` back, which is
			// what an unset key reads as.
			data: { menu_structure: before === 'full' ? 'full' : 'simple' },
		})
		await api.dispose()
	})

	// @e2e openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md#a-case-handler-opens-dossiq-on-a-new-instance
	test('the menu shows nine entries under three captions', async ({ page }) => {
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })

		for (const id of ['StartCaption', 'CasesCaption', 'RelationsCaption']) {
			await expect(
				nav.getByTestId(`cn-nav-caption-${id}`),
				`the caption ${id} must be in the menu`,
			).toBeVisible({ timeout: 30_000 })
		}

		// The main list only: the footer and the settings foldout are separate
		// regions and keep their own entries.
		const names = await nav
			.locator('.app-navigation__list')
			.first()
			.evaluate((list) =>
				Array.from(list.querySelectorAll('.app-navigation-entry__name')).map(
					(node) => (node.textContent ?? '').trim(),
				),
			)
		expect(
			names,
			'the main menu must hold exactly the nine entries',
		).toHaveLength(ENTRIES.length)
		ENTRIES.forEach((pattern, index) => {
			expect(names[index], `entry ${index + 1}`).toMatch(pattern)
		})
	})

	// @e2e openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md#a-page-that-left-the-menu-is-one-link-away
	test('your queue is one link away from My work, and its page still opens by address', async ({
		page,
	}) => {
		await page.goto('/apps/dossiq/')
		const link = page.getByRole('button', {
			name: /^(Your queue|Jouw wachtrij)$/i,
		})
		await expect(link.first()).toBeVisible({ timeout: 60_000 })
		await link.first().click()
		await expect(page).toHaveURL(/\/my-queue/, { timeout: 30_000 })

		// The deep link works without the menu entry.
		await page.goto('/apps/dossiq/end-of-day')
		await expect(page).toHaveURL(/\/end-of-day/)
		await expect(
			page.getByText(/Close out your day|Sluit je dag af/i).first(),
		).toBeVisible({ timeout: 60_000 })
	})
})
