/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the kcc-werkplek-zaaksysteem-bridge spec.
 *
 * Scope here is strictly the dossiq-authored UI surface this change ships:
 * the KCC-werkplek integration admin settings panel (KccIntegrationSettings.vue,
 * rendered inside Nextcloud's admin settings at /settings/admin/dossiq), which
 * configures burger identification method/threshold, case-voorblad limits,
 * sentiment trigger words and belplan overflow thresholds.
 *
 * The contactmoment-capture API, case-voorblad resolution, quick-actions,
 * sentiment scoring and belplan routing are backend concerns covered by PHPUnit
 * + Newman. DigiD authentication (OpenConnector), the telephony SIP transfer
 * and the contact-center screen-pop UI are delivered by OpenConnector and
 * pipelinq respectively — those scenarios are @e2e-excluded at the spec level
 * as cross-app, not exercisable from the dossiq UI.
 *
 * Each test changes two of the four controls on the page, saves through the
 * page's own Save button, and reads the values back from GET /api/settings,
 * then puts the originals back. Rendering alone proves only half the scenario:
 * a save that silently drops a key leaves every field on screen.
 *
 * Watched failing on the CLIENT half only. With the served settings bundle
 * rewritten so the form posts only `identification_method` and the trigger
 * words, both tests redden on their "the ... the admin typed is the one
 * stored" assertion. The SERVER half has not been seen red: that mutation
 * needs a change to the shared dev instance still awaiting approval. The
 * point is lib/Service/SettingsService.php, updateSettings(): skip the four
 * keys inside the CONFIG_KEYS loop, and the same assertions must redden.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { getRequestToken, RUN_PREFIX } from '../helpers/fixtures.ts'
import { loadAllAdminSections } from '../helpers/nav.ts'

const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'
const SETTINGS_API = '/index.php/apps/dossiq/api/settings'

/**
 * Every key the KCC form sends on Save. The form posts its WHOLE block, not
 * only the fields the admin touched, so restoring just the two a test changed
 * would leave the rest rewritten (an empty trigger-word list is stored as `[]`,
 * which switches off the defaults the empty string falls back to).
 */
const KCC_FORM_KEYS = [
	'identification_method',
	'identification_score_threshold',
	'sentiment_polling_interval',
	'specialist_availability_polling_interval',
	'max_zaken_voorblad',
	'max_contactmomenten_history',
	'belplan_overflow_threshold_wachttijd',
	'belplan_overflow_threshold_wachtrij_lengte',
	'sentiment_trigger_words',
]

/**
 * Snapshot the stored KCC block so the test can put it back.
 *
 * @param page The page whose session reads it.
 * @return every KCC form key and its stored value.
 */
async function kccSnapshot(page: Page): Promise<Record<string, string>> {
	const config = await storedConfig(page)
	return Object.fromEntries(
		KCC_FORM_KEYS.map((key) => [key, String(config[key] ?? '')]),
	)
}

/**
 * Read dossiq's stored settings, the way every consumer of them does.
 *
 * @param page The page whose session reads them.
 * @return the `config` map from GET /api/settings.
 */
async function storedConfig(page: Page): Promise<Record<string, any>> {
	// The endpoint is CSRF-checked: without the OCS header a GET is a 412.
	const res = await page.request.get(SETTINGS_API, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(res.status(), 'GET /api/settings').toBe(200)
	return (await res.json()).config ?? {}
}

/**
 * Put the named settings back to what they were before the test.
 *
 * @param page     The page whose session writes them.
 * @param original The values read before the test changed anything.
 */
async function restore(page: Page, original: Record<string, string>): Promise<void> {
	const token = await getRequestToken(page.request)
	const res = await page.request.post(SETTINGS_API, {
		headers: {
			requesttoken: token,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
		data: original,
	})
	expect(res.status(), 'restoring the KCC settings').toBe(200)
}

/**
 * Open the admin settings page with the KCC section mounted.
 *
 * @param page The page under test.
 */
async function openKccSection(page: Page): Promise<void> {
	// The dossiq admin settings page mounts many heavy sections (ZGW, VTH,
	// Map Layers, AI, …); waiting for the full `load` event races past the
	// test timeout. `domcontentloaded` is enough: the KCC fields are
	// asserted explicitly.
	await page.goto(ADMIN_SETTINGS_URL, {
		waitUntil: 'domcontentloaded',
		timeout: 60_000,
	})
	await expect(page).not.toHaveURL(/login/, { timeout: 10000 })

	// The admin settings page renders its sections progressively; the KCC
	// section is near the bottom and only mounts once scrolled near, so load
	// every section into the DOM before asserting.
	await loadAllAdminSections(page)
	const heading = page
		.getByRole('heading', { name: /KCC-werkplek Integration/i })
		.first()
	await heading.scrollIntoViewIfNeeded({ timeout: 15000 }).catch(() => {})
	await expect(heading).toBeVisible({ timeout: 15000 })
}

/**
 * Click Save and wait for the POST it sends.
 *
 * @param page The page under test.
 */
async function saveKcc(page: Page): Promise<void> {
	const posted = page.waitForResponse(
		(res) =>
			res.url().includes('/apps/dossiq/api/settings')
			&& res.request().method() === 'POST',
		{ timeout: 30_000 },
	)
	await page.getByRole('button', { name: 'Save KCC settings' }).click()
	expect((await posted).status(), 'the KCC save POST /api/settings').toBe(200)
}

test.describe('kcc-werkplek-zaaksysteem-bridge spec coverage', () => {
	// The dossiq admin settings page is very heavy (1.9MB DOM, 20+ sections
	// with maps + forms) and renders sections progressively, so triple the
	// per-test budget: the default 30s is not enough to load + scroll it.
	test.slow()

	// @e2e openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#kcc-integration-settings-render-and-persist
	test('the identification threshold and sentiment trigger words save through /api/settings', async ({
		page,
	}) => {
		// The scenario has two halves, render AND save. Seeing the fields, which
		// is all this test used to assert, stays green when the save drops them.
		// So it changes both values on the page, saves through the page's own
		// button, and reads them back from the stored settings.
		const original = await kccSnapshot(page)
		const threshold = `0.${60 + Math.floor(Math.random() * 30)}`
		const word = `${RUN_PREFIX}-klacht`

		await openKccSection(page)
		const thresholdField = page.locator('#kcc_identification_score_threshold')
		const wordsField = page.locator('#kcc_sentiment_trigger_words')
		await expect(thresholdField).toBeVisible({ timeout: 10000 })
		await expect(wordsField).toBeVisible({ timeout: 10000 })
		await expect(thresholdField).toBeEnabled({ timeout: 15000 })

		try {
			await thresholdField.fill(threshold)
			await wordsField.fill(`${word}\nadvocaat`)
			await saveKcc(page)

			const after = await storedConfig(page)
			expect(
				after.identification_score_threshold,
				'the identification threshold the admin typed is the one stored',
			).toBe(threshold)
			expect(
				JSON.parse(String(after.sentiment_trigger_words || '[]')),
				'the trigger words the admin typed are the ones stored, one per line',
			).toEqual([word, 'advocaat'])
		} finally {
			await restore(page, original)
		}
	})

	// @e2e openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#kcc-integration-settings-render-and-persist
	test('the voorblad limit and belplan overflow threshold save through /api/settings', async ({
		page,
	}) => {
		const original = await kccSnapshot(page)
		const limit = String(11 + Math.floor(Math.random() * 20))
		const wachttijd = String(200 + Math.floor(Math.random() * 100))

		await openKccSection(page)
		const limitField = page.locator('#kcc_max_zaken_voorblad')
		const overflowField = page.locator(
			'#kcc_belplan_overflow_threshold_wachttijd',
		)
		await expect(limitField).toBeVisible({ timeout: 10000 })
		await expect(overflowField).toBeVisible({ timeout: 10000 })
		await expect(limitField).toBeEnabled({ timeout: 15000 })

		try {
			await limitField.fill(limit)
			await overflowField.fill(wachttijd)
			await saveKcc(page)

			const after = await storedConfig(page)
			expect(
				after.max_zaken_voorblad,
				'the voorblad limit the admin typed is the one stored',
			).toBe(limit)
			expect(
				after.belplan_overflow_threshold_wachttijd,
				'the belplan overflow threshold the admin typed is the one stored',
			).toBe(wachttijd)
		} finally {
			await restore(page, original)
		}
	})
})
