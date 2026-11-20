/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The administrator's switch for the "Support Dossiq" note, read on a new
 * user's second load: the first load belongs to the walkthrough, so the note
 * can only appear from the second load on.
 */

import type { APIRequestContext, Browser } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ensureUser, login } from './helpers/auth.ts'
import { getRequestToken, RUN_PREFIX } from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'
import { occRun } from './helpers/occ.ts'

const PASSWORD = 'support-note-e2e-Pass-1'
const users: string[] = []
let api: APIRequestContext
let token = ''
let previous = ''

/**
 * A new account's second load of dossiq, after the walkthrough of its first.
 *
 * @param browser The launched browser.
 * @param baseURL The instance under test.
 * @param uid     The new account.
 * @return Whether the support note opened on the second load.
 */
async function noteOnSecondLoad(browser: Browser, baseURL: string, uid: string): Promise<boolean> {
	await ensureUser(api, token, uid, PASSWORD)
	users.push(uid)

	const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await login(page, uid, PASSWORD)
	await page.goto('/apps/dossiq/', PAGE_LOAD)
	await page.waitForLoadState('networkidle')

	// What the walkthrough records when the user finishes it.
	const marked = await occRun(['user:setting', uid, 'dossiq', 'pref_walkthrough_completed_version', '1'])
	expect(marked.code, marked.output).toBe(0)

	await page.reload(PAGE_LOAD)
	await page.waitForLoadState('networkidle')
	const note = page.locator('[data-testid-modal="cn-support-dialog"]')
	const shown = await note.waitFor({ state: 'visible', timeout: 15_000 }).then(() => true, () => false)
	await context.close()
	return shown
}

test.describe('The support note follows the administrator switch', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		const read = await occRun(['config:app:get', 'dossiq', 'show_support_note'])
		previous = read.code === 0 ? read.output.trim() : ''
	})

	test.afterAll(async () => {
		if (previous === '') {
			await occRun(['config:app:delete', 'dossiq', 'show_support_note'])
		} else {
			await occRun(['config:app:set', 'dossiq', 'show_support_note', `--value=${previous}`])
		}
		for (const uid of users) {
			await occRun(['user:delete', uid])
		}
		await api.dispose()
	})

	test('switched on, a new user sees the note on the second load', async ({ browser, baseURL }) => {
		await occRun(['config:app:set', 'dossiq', 'show_support_note', '--value=true'])
		const uid = `${RUN_PREFIX.toLowerCase()}-note-on`.replace(/[^a-z0-9-]/g, '')
		expect(await noteOnSecondLoad(browser, String(baseURL), uid)).toBe(true)
	})

	test('switched off, a new user sees no note on the second load', async ({ browser, baseURL }) => {
		await occRun(['config:app:set', 'dossiq', 'show_support_note', '--value=false'])
		const uid = `${RUN_PREFIX.toLowerCase()}-note-off`.replace(/[^a-z0-9-]/g, '')
		expect(await noteOnSecondLoad(browser, String(baseURL), uid)).toBe(false)
	})
})
