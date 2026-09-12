/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the case-email-integration spec.
 *
 * Scope here is strictly the dossiq-authored UI surfaces this change ships:
 * the shared-mailbox admin settings panel (EmailSettings.vue, rendered inside
 * Nextcloud's admin settings at /settings/admin/dossiq) and the absence of
 * any per-user SMTP-send credential fields on it. Leaf display/linking,
 * IMAP ingest, Docudesk PDF archival and NC Mail draft-open are backend /
 * cross-app concerns covered by PHPUnit + Newman + the email leaf, and are
 *
 * @e2e-excluded at the spec level.
 *
 * Tests are defensive: the admin settings SPA is data-independent chrome, so
 * they assert the shared-mailbox fields render and that no SMTP-send field is
 * present, guarding against a 5xx render.
 */

import { expect, test } from '@playwright/test'
import { loadAllAdminSections } from '../helpers/nav.ts'

const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'

test.describe('case-email-integration spec coverage', () => {
	// The dossiq admin settings page is very heavy (1.9MB DOM, 20+ sections)
	// and renders sections progressively, so triple the per-test budget — the
	// default 30s is not enough to load + scroll it.
	test.slow()

	// @e2e openspec/specs/case-email-integration/spec.md#no-per-user-smtp-send-configuration-is-exposed
	test('shared-mailbox settings render without per-user SMTP send fields', async ({
		page,
	}) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })

		// The admin settings page renders its sections progressively; the Case
		// Email — Shared Mailbox section is near the bottom, so load all
		// sections into the DOM before asserting.
		await loadAllAdminSections(page)
		const heading = page.getByRole('heading', {
			name: /Case Email|Shared Mailbox/i,
		})
		await heading
			.first()
			.scrollIntoViewIfNeeded({ timeout: 15000 })
			.catch(() => {})
		await expect(heading.first()).toBeVisible({ timeout: 15000 })

		// Shared-mailbox IMAP host field is present.
		await expect(page.locator('#email_imap_host')).toBeVisible({
			timeout: 10000,
		})

		// There MUST be no SMTP-send credential FIELDS — outbound mail is NC
		// Mail's. (We assert on the input fields, not on any "SMTP" text: the
		// section's own help copy explains that per-user SMTP send is NOT
		// exposed, so a blanket getByText(/SMTP/i)=0 would wrongly match that
		// explanatory sentence.)
		await expect(page.locator('#email_smtp_host')).toHaveCount(0)
		await expect(page.locator('#email_smtp_password')).toHaveCount(0)
		await expect(
			page.locator('input[id*="smtp" i], input[name*="smtp" i]'),
		).toHaveCount(0)
	})

	// UNPARKED, MEASURED RATHER THAN ARGUED. The old FIXME(#719) said the
	// "Test connection" button exists in EmailSettings.vue but does not render
	// on the admin page even after every section has been scrolled in. Run
	// unparked on CI run 34578033755 it passed on the first attempt. Nothing in
	// the body below changed, so what moved was the app, not the test.
	//
	// @e2e exclude This used to cite "Composer is the leaf / NC Mail, not a
	// dossiq component", which is about the case compose flow opening an NC Mail
	// draft. This test reads admin-settings buttons and never opens a case. That
	// scenario is now excluded in the spec because the Mail draft handoff is not
	// built: prefillDraft returns a subject and body, and nothing opens a draft.
	test('settings expose a Test connection control, not an outbound composer', async ({
		page,
	}) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })

		await loadAllAdminSections(page)
		const heading = page.getByRole('heading', {
			name: /Case Email|Shared Mailbox/i,
		})
		await heading
			.first()
			.scrollIntoViewIfNeeded({ timeout: 15000 })
			.catch(() => {})
		await expect(heading.first()).toBeVisible({ timeout: 15000 })

		// The shared-mailbox panel offers a Test-connection action (IMAP smoke
		// test) — dossiq never ships a send/compose control here. The actions
		// sit below the section heading, so scroll them into view before
		// asserting on the heavy admin page.
		const testConn = page.getByRole('button', { name: 'Test connection' })
		await testConn.scrollIntoViewIfNeeded({ timeout: 15000 }).catch(() => {})
		await expect(testConn).toBeVisible({ timeout: 10000 })
		await expect(
			page.getByRole('button', { name: 'Save mailbox settings' }),
		).toBeVisible({ timeout: 10000 })
	})
})
