/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 spec-coverage tests for the case-email-integration spec.
 *
 * Scope here is strictly the procest-authored UI surfaces this change ships:
 * the shared-mailbox admin settings panel (EmailSettings.vue, rendered inside
 * Nextcloud's admin settings at /settings/admin/procest) and the absence of
 * any per-user SMTP-send credential fields on it. Leaf display/linking,
 * IMAP ingest, Docudesk PDF archival and NC Mail draft-open are backend /
 * cross-app concerns covered by PHPUnit + Newman + the email leaf, and are
 * @e2e-excluded at the spec level.
 *
 * Tests are defensive: the admin settings SPA is data-independent chrome, so
 * they assert the shared-mailbox fields render and that no SMTP-send field is
 * present, guarding against a 5xx render.
 */

import { test, expect } from '@playwright/test'

const ADMIN_SETTINGS_URL = '/settings/admin/procest'

test.describe('case-email-integration spec coverage', () => {

	// @e2e openspec/specs/case-email-integration/spec.md#no-per-user-smtp-send-configuration-is-exposed
	test('shared-mailbox settings render without per-user SMTP send fields', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })

		// The Case Email shared-mailbox section heading renders inside AdminRoot.
		const heading = page.getByRole('heading', { name: /Case Email|Shared Mailbox/i })
		await expect(heading.first()).toBeVisible({ timeout: 15000 })

		// Shared-mailbox IMAP host field is present.
		await expect(page.locator('#email_imap_host')).toBeVisible({ timeout: 10000 })

		// There MUST be no SMTP-send credential fields — outbound mail is NC Mail's.
		await expect(page.locator('#email_smtp_host')).toHaveCount(0)
		await expect(page.locator('#email_smtp_password')).toHaveCount(0)
		await expect(page.getByText(/SMTP/i)).toHaveCount(0)
	})

	// @e2e openspec/specs/case-email-integration/spec.md#composer-is-the-leaf-nc-mail-not-a-procest-component
	test('settings expose a Test connection control, not an outbound composer', async ({ page }) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })

		const heading = page.getByRole('heading', { name: /Case Email|Shared Mailbox/i })
		await expect(heading.first()).toBeVisible({ timeout: 15000 })

		// The shared-mailbox panel offers a Test-connection action (IMAP smoke
		// test) — procest never ships a send/compose control here.
		await expect(page.getByRole('button', { name: 'Test connection' })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Save mailbox settings' })).toBeVisible({ timeout: 10000 })
	})

})
