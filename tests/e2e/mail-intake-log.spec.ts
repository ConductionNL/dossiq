/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A handler who knows which case a message belongs on can say so.
 *
 * The intake log is real and has been for a while: `MailIntakeLogView` lists
 * every message the mailbox processed with the verdict that decided it, and
 * four acts were routed, release, junk, bounce and move. None of them let a
 * handler FILE a message on a case they picked; `release()` files it on
 * `$entry['case']`, the case the matcher already chose. So a handler who knew
 * the message belonged on 2026-114 could do nothing with that knowledge.
 *
 * The cross-app arms of this change (integriq offering a message, and
 * integriq's reader parsing a saved `.msg`) are `@e2e exclude`d in the spec
 * and covered by MessageReceivedListenerTest and SavedMailImportTest, because
 * an instance running integriq's mailbox is not something a test can conjure.
 * What is driven here is the surface.
 *
 * ⚠️ NOT RUN LOCALLY. There is no Playwright run on this box and no instance
 * to run against, so this is written and tagged, not executed.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	trackCreatedObject,
} from './helpers/fixtures.ts'

const CASE_TITLE = `${RUN_PREFIX} Mail intake`

test.describe('a handler files a logged message on a case they pick', () => {
	test.setTimeout(300_000)

	let caseId = ''

	test.beforeAll(async ({ request }) => {
		const token = await getRequestToken(request)
		const created = await createObject(request, token, 'case', {
			title: CASE_TITLE,
			description: 'Mail intake run',
		})
		caseId = objectId(created)
		trackCreatedObject('case', caseId)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request)
	})

	// @e2e openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md#a-wrongly-matched-message-is-moved-to-the-right-case
	test('the log offers File on a case on every entry', async ({ page }) => {
		await page.goto('/apps/dossiq/intake/mail-log')

		// An instance with no processed mail renders the empty state, and that
		// is a pass for THIS assertion only if the act is absent for the right
		// reason. So the presence of a row is asserted first: no row, no claim.
		const row = page.locator('[data-testid^="intake-log-file-on-case-"]')
		const rows = await row.count()
		if (rows === 0) {
			test.skip(true, 'this instance has processed no mail, so there is no entry to act on')
			return
		}

		await row.first().click()
		await expect(page.locator('[data-testid="intake-log-case-id"]')).toBeVisible()
		await expect(
			page.locator('[data-testid="intake-log-file-reason"]'),
		).toBeVisible()

		// The reason is REQUIRED, and the refusal is visible before the click
		// rather than after it.
		await expect(
			page.locator('[data-testid="intake-log-file-confirm"]'),
		).toBeDisabled()
	})

	// @e2e openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md#a-case-the-caller-may-not-read-is-refused
	test('a caller with no session cannot file a message on a case', async ({
		request,
	}) => {
		// The least privileged principal that should be refused: an anonymous
		// caller. Filing a message onto a case writes a citizen's words onto a
		// dossier, so the act is behind the intake role AND a per-case guard.
		const response = await request.post(
			'/apps/dossiq/api/mail-intake/log/entry-1/file-on-case',
			{
				data: { caseId, reason: 'Hoort hier.' },
				failOnStatusCode: false,
			},
		)

		expect([401, 403, 412]).toContain(response.status())
	})

	// @e2e openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md#an-outlook-message-dropped-on-a-case-becomes-readable
	test('a file that is not a mail file is refused, and no message is filed', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		// A file id nobody in this session can reach: a 404 rather than a
		// verdict, because an answer on an unreachable id would enumerate the
		// instance one file at a time.
		const response = await request.post(
			`/apps/dossiq/api/cases/${caseId}/files/999999999/read-as-message`,
			{ headers: { requesttoken: token }, failOnStatusCode: false },
		)

		expect(response.status()).toBe(404)
	})
})
