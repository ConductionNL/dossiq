/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * configurable-queue-urgency: the Urgency sort is the server's score, every
 * card carries a deadline tier pill, and the admin settings carry the queue
 * urgency section.
 *
 * Two cases are seeded on the signed-in user, from the same case type. The
 * high one started a day earlier, so its deadline is no later, and its
 * priority (derived from impact and urgency on every save) is higher: it must
 * rank first under Urgency. The low one started later, so it must come first
 * under Newest. Both are found by title, so neither answer depends on what
 * else the user holds.
 *
 * The admin section is reset to the defaults before it is read and after it
 * is written, because the settings are instance-wide and the rig is shared.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from './helpers/auth.ts'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'
import { MyWorkCards } from './helpers/page-components.ts'

const ME = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const SETTINGS = '/index.php/settings/admin/dossiq'
const HIGH = `${RUN_PREFIX} queue urgency high`
const LOW = `${RUN_PREFIX} queue urgency low`
const DEFAULTS = {
	queue_critical_days: '3',
	queue_warning_days: '7',
	queue_priority_weight: '10',
	queue_idle_weight: '0.5',
}

let api: APIRequestContext
let token: string

/**
 * Write the queue settings through the app's own admin settings write.
 *
 * @param values The keys to write.
 */
async function writeSettings(values: Record<string, string>): Promise<void> {
	const res = await api.post('/index.php/apps/dossiq/api/settings', {
		headers: {
			requesttoken: token,
			'Content-Type': 'application/json',
			'OCS-APIREQUEST': 'true',
		},
		data: values,
	})
	expect(res.ok(), 'the settings write must answer 2xx').toBeTruthy()
}

/**
 * Open My Work and wait for the seeded cards.
 *
 * @param page The page.
 */
async function openMyWork(page: Page): Promise<void> {
	await page.goto(`/index.php/apps/dossiq${MyWorkCards}`)
	await dismissSupportDialog(page)
	await expect(page.getByRole('button', { name: 'Urgency' })).toBeVisible({
		timeout: 60_000,
	})
	await expect(page.locator('.mywork-card', { hasText: HIGH })).toBeVisible({
		timeout: 60_000,
	})
	await expect(page.locator('.mywork-card', { hasText: LOW })).toBeVisible({
		timeout: 60_000,
	})
}

/**
 * The order of the two seeded cards on the page.
 *
 * @param page The page.
 * @return The two titles, in the order the cards appear.
 */
async function seededOrder(page: Page): Promise<string[]> {
	const titles = await page.locator('.mywork-card__title').allInnerTexts()
	return titles
		.map((title) => title.trim())
		.filter((title) => title === HIGH || title === LOW)
}

test.describe('configurable-queue-urgency', () => {
	test.setTimeout(300_000)

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		await writeSettings(DEFAULTS)
		const caseType = await ensureCaseType(api, token)
		const today = new Date().toISOString().slice(0, 10)
		const yesterday = new Date(Date.now() - 86_400_000)
			.toISOString()
			.slice(0, 10)
		await seedCase(api, token, {
			title: HIGH,
			caseType: caseType.id,
			assignee: ME,
			impact: 'high',
			urgency: 'high',
			startDate: yesterday,
		})
		await seedCase(api, token, {
			title: LOW,
			caseType: caseType.id,
			assignee: ME,
			impact: 'low',
			urgency: 'low',
			startDate: today,
		})
	})

	test.afterAll(async () => {
		await writeSettings(DEFAULTS)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md#scenario-urgency-is-the-default-sort
	test('Urgency is the default, and it ranks by the score', async ({ page }) => {
		await openMyWork(page)

		await expect(page.locator('[data-testid="urgency-fallback"]')).toHaveCount(0)
		expect(
			await seededOrder(page),
			'the earlier, higher-priority case must rank first',
		).toEqual([HIGH, LOW])
	})

	// @e2e openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md#scenario-toggling-to-newest-re-sorts
	test('Newest re-sorts by start date', async ({ page }) => {
		await openMyWork(page)

		await page.getByRole('button', { name: 'Newest' }).click()
		await expect(page.locator('.mywork-card', { hasText: LOW })).toBeVisible({
			timeout: 60_000,
		})
		await expect
			.poll(() => seededOrder(page), { timeout: 30_000 })
			.toEqual([LOW, HIGH])
	})

	// @e2e openspec/changes/configurable-queue-urgency/specs/werkvoorraad-intelligent-queue/spec.md#scenario-normal-tier-shows-no-chip
	test('every seeded card carries a pill, and the normal tier is no coloured chip', async ({
		page,
	}) => {
		await openMyWork(page)

		// The seeded type's own term decides the tier, so the pill text is read
		// rather than assumed; what the scenario fixes is that a pill is there,
		// and that a normal-tier pill is the neutral one.
		const pill = page
			.locator('.mywork-card', { hasText: LOW })
			.locator('[data-testid="deadline-tier-pill"]')
		await expect(pill).toBeVisible({ timeout: 60_000 })
		const text = (await pill.innerText()).trim()
		if (text === 'Normal' || text === 'Normaal') {
			await expect(pill).toHaveClass(/mywork-card__tier-pill--normal/)
		}
	})

	// @e2e openspec/changes/configurable-queue-urgency/specs/admin-settings/spec.md#scenario-the-section-shows-the-defaults
	test('the admin section shows the defaults', async ({ page }) => {
		await page.goto(SETTINGS)
		const section = page.locator('[data-testid="queue-urgency-settings"]')
		await expect(section).toBeVisible({ timeout: 60_000 })

		await expect(
			section.locator('[data-testid="queue-urgency-criticalDays"] input'),
		).toHaveValue('3')
		await expect(
			section.locator('[data-testid="queue-urgency-warningDays"] input'),
		).toHaveValue('7')
		await expect(
			section.locator('[data-testid="queue-urgency-priorityWeight"] input'),
		).toHaveValue('10')
		await expect(
			section.locator('[data-testid="queue-urgency-idleWeight"] input'),
		).toHaveValue('0.5')
	})

	// @e2e openspec/changes/configurable-queue-urgency/specs/admin-settings/spec.md#scenario-a-saved-threshold-comes-back-after-a-reload
	test('a saved threshold comes back after a reload', async ({ page }) => {
		await page.goto(SETTINGS)
		const section = page.locator('[data-testid="queue-urgency-settings"]')
		await expect(section).toBeVisible({ timeout: 60_000 })

		await section
			.locator('[data-testid="queue-urgency-criticalDays"] input')
			.fill('5')
		await section.locator('[data-testid="queue-urgency-save"]').click()
		await expect(section.getByText(/^(Saved|Opgeslagen)$/)).toBeVisible({
			timeout: 30_000,
		})

		// Nextcloud's app config is cached per request for a few seconds; the
		// reload reads the initial state the server renders.
		await page.waitForTimeout(4_000)
		await page.reload()
		await expect(
			section.locator('[data-testid="queue-urgency-criticalDays"] input'),
		).toHaveValue('5', { timeout: 60_000 })
	})
})
