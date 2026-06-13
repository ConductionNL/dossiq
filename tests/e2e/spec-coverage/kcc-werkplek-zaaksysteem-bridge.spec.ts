/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 spec-coverage tests for the kcc-werkplek-zaaksysteem-bridge spec.
 *
 * Scope here is strictly the procest-authored UI surface this change ships:
 * the KCC-werkplek integration admin settings panel (KccIntegrationSettings.vue,
 * rendered inside Nextcloud's admin settings at /settings/admin/procest), which
 * configures burger identification method/threshold, case-voorblad limits,
 * sentiment trigger words and belplan overflow thresholds.
 *
 * The contactmoment-capture API, case-voorblad resolution, quick-actions,
 * sentiment scoring and belplan routing are backend concerns covered by PHPUnit
 * + Newman. DigiD authentication (OpenConnector), the telephony SIP transfer
 * and the contact-center screen-pop UI are delivered by OpenConnector and
 * pipelinq respectively — those scenarios are @e2e-excluded at the spec level
 * as cross-app, not exercisable from the procest UI.
 *
 * Tests are defensive: the admin settings SPA is data-independent chrome, so
 * they assert the KCC fields render, guarding against a 5xx render.
 */

import { test, expect } from '@playwright/test'

const ADMIN_SETTINGS_URL = '/settings/admin/procest'

test.describe('kcc-werkplek-zaaksysteem-bridge spec coverage', () => {

	// @e2e openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#identification-scoring
	test('KCC integration settings render the identification + sentiment controls', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })

		// The KCC-werkplek Integration section heading renders inside AdminRoot.
		const heading = page.getByRole('heading', { name: /KCC-werkplek Integration/i })
		await expect(heading.first()).toBeVisible({ timeout: 15000 })

		// Identification score threshold and sentiment trigger-word fields are present.
		await expect(page.locator('#kcc_identification_score_threshold')).toBeVisible({ timeout: 10000 })
		await expect(page.locator('#kcc_sentiment_trigger_words')).toBeVisible({ timeout: 10000 })
	})

	// @e2e openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#belplan-configuration
	test('KCC integration settings expose belplan overflow + voorblad-limit controls', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })

		await expect(page.getByRole('heading', { name: /KCC-werkplek Integration/i }).first())
			.toBeVisible({ timeout: 15000 })

		await expect(page.locator('#kcc_max_zaken_voorblad')).toBeVisible({ timeout: 10000 })
		await expect(page.locator('#kcc_belplan_overflow_threshold_wachttijd')).toBeVisible({ timeout: 10000 })
	})
})
