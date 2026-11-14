/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * admin-message-and-portal-texts: the Texts and access block of the admin
 * settings asks for the sender of messages and the text above My cases, and
 * only points at the allowed websites (CORS), which the portal app and
 * Nextcloud own.
 *
 * WHY THIS IS E2E AND NOT ONLY A UNIT TEST. `adminGeneralFields.spec.js`
 * proves the field list declares the CORS entry as a `note`. It cannot prove
 * that the settings page renders a note as a line of text rather than as an
 * input: that is the library's field renderer, which a vitest of the list
 * never reaches. A field rendered as an input here would invite a value that
 * nothing reads.
 *
 * The other two scenarios of the requirement carry a reasoned `@e2e exclude`
 * in the spec: the portal page is drawn by portaliq, and sending a message
 * has no screen in dossiq.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
 */

import { expect, test } from '@playwright/test'
import { PAGE_LOAD } from './helpers/nav.ts'

/** The dossiq admin settings page. */
const SETTINGS = '/index.php/settings/admin/dossiq'

test.describe('Texts and access in the admin settings', () => {
	// @e2e openspec/changes/admin-message-and-portal-texts/specs/admin-settings/spec.md#scenario-cors-is-pointed-elsewhere
	test('the allowed websites are a pointer to the portal app, with no field', async ({
		page,
	}) => {
		await page.goto(SETTINGS, PAGE_LOAD)

		const block = page.locator('#section-texts-and-access')
		await expect(block).toBeVisible({ timeout: 30_000 })

		// The two texts the block does ask for are inputs.
		await expect(block.getByLabel('Sender of messages')).toBeEditable()
		await expect(block.getByLabel('Text above My cases in the portal')).toBeEditable()

		// The allowed websites are named, and sent elsewhere.
		await expect(block).toContainText('Allowed websites (CORS)')
		await expect(block).toContainText('Set in the portal app (portaliq) and in Nextcloud, not here.')

		// And nothing in the block takes a value for them.
		await expect(
			block.getByLabel('Allowed websites (CORS)'),
			'the CORS entry must be a line of text, not a field',
		).toHaveCount(0)
		await expect(block.getByRole('textbox', { name: /CORS|websites/i })).toHaveCount(0)
	})
})
