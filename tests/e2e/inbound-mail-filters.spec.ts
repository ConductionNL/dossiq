/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a person can do with the mail intake log, and what the mail settings
 * ask an administrator for.
 *
 * 🔴 WHAT THIS LAYER CAN AND CANNOT PROVE. The pipeline itself needs a mail
 * server: an auto-reply has to arrive, a bounce has to be generated, a forged
 * `In-Reply-To` has to be delivered. None of that exists on an e2e instance, so
 * those scenarios carry an `@e2e exclude` in the spec naming the unit test that
 * covers them, and nothing here pretends to run the pipeline. Seeding a log
 * entry and then asserting the log shows it would answer a question about the
 * SURFACE while reading as an answer about INTAKE.
 *
 * So the anchors here are on the acts a person performs in a browser: picking
 * the account, finding a message by its sender, reading why a message was
 * junked, correcting that, and releasing a held message. Each of those runs a
 * real MailIntakeController endpoint, and each assertion that matters reads the
 * STORED entry back over the API rather than a toast.
 *
 * PLAYWRIGHT SIGNS IN AS ADMIN, and `IntakePolicy::mayRunIntake()` lets an
 * administrator through whatever the intake role is. The refusal for a reader
 * without the role therefore belongs to IntakePolicyTest, not here: an admin
 * cannot take a lesser role in a browser.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	showObject,
} from './helpers/fixtures.ts'
import { navToRoute, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** The dossiq admin settings page, where the account is picked. */
const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'

/** The in-app route the manifest gives the log. */
const LOG_ROUTE = '/mail-intake-log'

/** The schema the intake log stores an entry in. */
const ENTRY_SCHEMA = 'mailIntakeEntry'

/** A sender nobody else's fixture uses, so a search by it is unambiguous. */
const SENDER = `${RUN_PREFIX}-aanvrager@voorbeeld.nl`.toLowerCase()

/** The entries this spec seeded, by the name the tests know them under. */
const entries: Record<string, string> = {}

/**
 * One intake log entry, as the log itself would have written it.
 *
 * @param overrides The fields this entry needs.
 */
function entryBody(overrides: Record<string, unknown>): Record<string, unknown> {
	return {
		mailMessageId: `<${RUN_PREFIX}-${Math.random().toString(36).slice(2)}@voorbeeld.nl>`,
		accountId: 1,
		mailbox: 'INBOX',
		uid: 1,
		sender: SENDER,
		recipient: 'postbus@gemeente.nl',
		subject: `${RUN_PREFIX} Bezwaar tegen het besluit`,
		receivedAt: new Date().toISOString(),
		spfResult: 'unavailable',
		dkimResult: 'unavailable',
		dmarcResult: 'unavailable',
		threadingResult: 'none',
		original: 'From: aanvrager@voorbeeld.nl\r\nSubject: Bezwaar\r\n\r\nIk maak bezwaar.',
		...overrides,
	}
}

test.describe('Inbound mail filters', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const seed = async (key: string, overrides: Record<string, unknown>) => {
			const row = await createObject(api, token, ENTRY_SCHEMA, entryBody(overrides))
			entries[key] = objectId(row)
		}

		await seed('found', {
			outcome: 'inbox',
			decidingFilter: '',
			reason: 'No case and no case type claimed this message, so it is waiting in the intake inbox.',
		})
		await seed('junked', {
			outcome: 'refused',
			decidingFilter: 'junk',
			junkRule: 'spam-header',
			reason: 'Refused as junk.',
		})
		await seed('held', {
			outcome: 'quarantined',
			decidingFilter: '',
			reason: 'The case type holds a message whose sender could not be authenticated.',
		})

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token, [ENTRY_SCHEMA])
		await api.dispose()
	})

	// @e2e openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#an-administrator-picks-the-account-instead-of-typing-a-password
	test('the mail settings offer an account, not a password', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		await page.goto(ADMIN_SETTINGS_URL, PAGE_LOAD)

		const picker = page.locator('[data-testid="email-mail-account"]')
		await picker.scrollIntoViewIfNeeded()
		await expect(picker).toBeVisible()

		// The whole point of D12: the credential left dossiq. A password field
		// anywhere on this page means it came back.
		await expect(page.locator('.email-settings input[type="password"]')).toHaveCount(0)
		await expect(page.locator('.email-settings #email_imap_password')).toHaveCount(0)

		expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([])
	})

	// @e2e openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#somebody-says-they-mailed-us
	test('a message that became no case is found by its sender', async ({ page }) => {
		await navToRoute(page, LOG_ROUTE)

		await page.locator('input[data-testid="intake-log-sender"]').fill(SENDER)

		const row = page.locator(`[data-testid="intake-log-row-${entries.found}"]`)
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row).toContainText('waiting in the intake inbox')

		// A check nobody made is its own word. Rendering it as a blank cell
		// would let a reader take an absent SPF header for a pass.
		await expect(row).toContainText('not checked')
		await expect(row).not.toContainText('passed')
	})

	// @e2e openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#an-administrator-reads-why-a-message-was-junked
	test('a junk verdict names the rule behind it', async ({ page }) => {
		await navToRoute(page, LOG_ROUTE)
		await page.locator('input[data-testid="intake-log-sender"]').fill(SENDER)

		const row = page.locator(`[data-testid="intake-log-row-${entries.junked}"]`)
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row).toContainText('spam-header')
	})

	// @e2e openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#a-person-corrects-a-wrong-junk-verdict
	test('a wrong junk verdict is corrected, and the correction is recorded', async ({
		page,
		playwright,
		baseURL,
	}) => {
		await navToRoute(page, LOG_ROUTE)
		await page.locator('input[data-testid="intake-log-sender"]').fill(SENDER)

		const button = page.locator(`[data-testid="intake-log-not-junk-${entries.junked}"]`)
		await expect(button).toBeVisible({ timeout: 30_000 })
		await button.click()

		// Asserted on the STORED entry: the toast is translated, the row may
		// have moved, and neither says whether anything was written.
		const api = await playwright.request.newContext({ baseURL })
		await expect
			.poll(
				async () => {
					const entry = await showObject(api, ENTRY_SCHEMA, entries.junked)
					return String(entry.junkRule ?? '')
				},
				{ timeout: 30_000 },
			)
			.toBe('')

		const entry = await showObject(api, ENTRY_SCHEMA, entries.junked)
		expect(entry.reason, 'the correction names who made it').toContain('not junk')
		await api.dispose()
	})

	// @e2e openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md#a-released-message-becomes-a-case
	test('a held message is released by hand, and the release is recorded', async ({
		page,
		playwright,
		baseURL,
	}) => {
		await navToRoute(page, LOG_ROUTE)
		await page.locator('input[data-testid="intake-log-sender"]').fill(SENDER)

		const button = page.locator(`[data-testid="intake-log-release-${entries.held}"]`)
		await expect(button).toBeVisible({ timeout: 30_000 })
		await button.click()

		const api = await playwright.request.newContext({ baseURL })
		await expect
			.poll(
				async () => {
					const entry = await showObject(api, ENTRY_SCHEMA, entries.held)
					return String(entry.outcome ?? '')
				},
				{ timeout: 30_000 },
			)
			.toBe('released')

		const entry = await showObject(api, ENTRY_SCHEMA, entries.held)
		expect(
			String(entry.releasedBy ?? ''),
			'a release records the person who made it',
		).not.toBe('')
		expect(String(entry.releasedAt ?? ''), 'and when').not.toBe('')
		await api.dispose()
	})
})
