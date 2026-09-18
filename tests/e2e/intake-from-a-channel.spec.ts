/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A message that arrived on a channel, and what a person can find out about
 * it (an-intake-message-opens-a-case).
 *
 * 🔴 WHAT THIS LAYER CAN AND CANNOT PROVE, said plainly because the gap
 * between the two is where a green test starts lying. Opening a case from a
 * channel needs integriq installed, a channel adapter, a signed inbound post
 * and a routing rule. None of that exists on a dossiq e2e instance, so the
 * opening itself, the duplicate rule and the elevated write are asserted in
 * IntakeMessageRoutedListenerTest, against a doubled object service that
 * REFUSES an unelevated write. Driving them here would need the whole other
 * app, and a spec that skipped when it was missing would be a test that
 * cannot fail.
 *
 * What this file guards is the half that lives in dossiq and only shows up in
 * a browser: a message that opened no case says so where an intake worker
 * reads, with the reason in words. Before this change the answer existed only
 * in `nextcloud.log`, and integriq held the message with "No app opened a
 * case for this message", which was true while nothing listened and is a lie
 * now that something does.
 *
 * THE ENTRY IS SEEDED AND THE ASSERTION IS ABOUT THE PAGE. Seeding a log
 * entry and then asserting the log stored it would answer a question about
 * the object API while reading as an answer about the surface, so every
 * assertion below is either a render on the page or a read back through the
 * log's own endpoint.
 *
 * PLAYWRIGHT SIGNS IN AS ADMIN, and `IntakePolicy::mayRunIntake()` lets an
 * administrator through whatever the intake role is. The refusal for a reader
 * without the role belongs to IntakePolicyTest: an admin cannot take a lesser
 * role in a browser.
 *
 * @spec openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md
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
import { navToRoute } from './helpers/nav.ts'

/** The in-app route the manifest gives the intake log. */
const LOG_ROUTE = '/mail-intake-log'

/** The schema the intake log stores an entry in, channel messages included. */
const ENTRY_SCHEMA = 'mailIntakeEntry'

/** The channel these fixtures pretend to have arrived on. */
const CHANNEL = 'teams'

/** A correspondent nobody else's fixture uses, so a search by it is unambiguous. */
const SENDER = `${RUN_PREFIX}-melder@voorbeeld.nl`.toLowerCase()

/** The reason a handler has to be able to read, in full. */
const REASON =
	'The routing rule "Meldingen via Teams" names no case type this instance has. '
	+ 'A dossiq rule maps a case type id onto `caseType`.'

/** The entries this spec seeded, by the name the tests know them under. */
const entries: Record<string, string> = {}

/**
 * One intake log entry for a channel message, as ChannelIntake writes it.
 *
 * The mail-shaped columns are absent on purpose: that absence plus a filled
 * `channel` is how a reader tells a channel message from a mail without a
 * second schema.
 *
 * @param overrides The fields this entry needs.
 */
function channelEntry(
	overrides: Record<string, unknown>,
): Record<string, unknown> {
	return {
		channel: CHANNEL,
		channelMessageId: `${RUN_PREFIX}-${Math.random().toString(36).slice(2)}`,
		sender: SENDER,
		subject: `${RUN_PREFIX} Lantaarnpaal kapot`,
		receivedAt: new Date().toISOString(),
		...overrides,
	}
}

test.describe('A message from a channel', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const seed = async (key: string, overrides: Record<string, unknown>) => {
			const row = await createObject(
				api,
				token,
				ENTRY_SCHEMA,
				channelEntry(overrides),
			)
			entries[key] = objectId(row)
		}

		await seed('refused', { outcome: 'refused', reason: REASON })
		await seed('opened', {
			outcome: 'case',
			reason: 'Opened by routing rule "Meldingen via Teams".',
		})

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token, [ENTRY_SCHEMA])
		await api.dispose()
	})

	// @e2e openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md#a-triage-refusal-holds-the-message-with-its-reason
	test('a refused channel message is on the log with its reason', async ({
		page,
	}) => {
		await navToRoute(page, LOG_ROUTE)

		await page.locator('input[data-testid="intake-log-sender"]').fill(SENDER)

		const row = page.locator(
			`[data-testid="intake-log-row-${entries.refused}"]`,
		)
		await expect(row).toBeVisible({ timeout: 30_000 })

		// The reason is the whole point. A row that shows only "refused" sends
		// the reader back to nextcloud.log, which is where this started.
		await expect(row).toContainText('names no case type')
	})

	// @e2e openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md#a-form-submission-becomes-a-case-with-its-clock-running
	test('a channel message that opened a case says which one', async ({
		page,
	}) => {
		await navToRoute(page, LOG_ROUTE)

		await page.locator('input[data-testid="intake-log-sender"]').fill(SENDER)

		const opened = page.locator(
			`[data-testid="intake-log-row-${entries.opened}"]`,
		)
		const refused = page.locator(
			`[data-testid="intake-log-row-${entries.refused}"]`,
		)

		// BOTH DIRECTIONS. One row visible proves nothing about a filter or a
		// renderer that shows every entry it is handed; the pair proves the
		// page distinguishes the two outcomes.
		await expect(opened).toBeVisible({ timeout: 30_000 })
		await expect(opened).toContainText('Meldingen via Teams')
		await expect(opened).not.toContainText('names no case type')
		await expect(refused).not.toContainText('Opened by routing rule')
	})

	// @e2e openspec/changes/an-intake-message-opens-a-case/specs/intake-from-a-channel/spec.md#the-message-is-marked-routed-not-held
	test('the stored entry names the channel and the message', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const stored = await showObject(api, ENTRY_SCHEMA, entries.refused)

		// `channel` plus `channelMessageId` is what makes a second delivery the
		// same message. An entry that lost either of them cannot answer that,
		// and the duplicate check reads "this message is new" every time.
		expect(stored.channel).toBe(CHANNEL)
		expect(String(stored.channelMessageId ?? '')).not.toBe('')
		expect(String(stored.mailMessageId ?? '')).toBe('')

		await api.dispose()
	})
})
