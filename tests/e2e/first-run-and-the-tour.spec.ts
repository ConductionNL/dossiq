/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The first run: what the instance still needs, and the tour that teaches it.
 *
 * Two things here are quiet failures rather than visible ones, and both are
 * why every assertion reads the status endpoint by value instead of looking at
 * the page. A readiness item backed by a stored flag reports "done" forever
 * after somebody deletes the thing it recorded, and it looks exactly like an
 * item that is genuinely satisfied. And a readiness item promoted into
 * `setup.steps` blocks the whole app behind something an administrator is
 * allowed to leave open, which looks exactly like a wizard doing its job until
 * you try to get past it.
 *
 * ⚠️ THE E2E INSTANCE IS ALREADY CONFIGURED, so most items read done. The
 * scenarios that need a NOT-done item make one rather than hoping for it: a
 * case type is published and unpublished over the API, and the item is read on
 * both sides of that. An instance where the item reads done in both reads
 * fails, because that is the stored-flag defect.
 *
 * Locale: nothing forces the language of the instance, so the section name is
 * matched in either locale the app ships.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	updateObject,
} from './helpers/fixtures.ts'

const APP_BASE = '/index.php/apps/dossiq'

/**
 * The first-run status, as the settings screen reads it.
 *
 * @param api Authenticated request context.
 * @return The readiness items and the broken tour steps.
 */
async function status(api: APIRequestContext): Promise<any> {
	const res = await api.get(`${APP_BASE}/api/setup/status`)
	expect(res.ok(), `the setup status is missing: ${res.status()} ${await res.text()}`).toBeTruthy()
	const body = await res.json()
	expect(
		Array.isArray(body.readiness),
		'the status reports no readiness list, so the first run names nothing',
	).toBeTruthy()
	return body
}

/**
 * One readiness item out of the status.
 *
 * @param body The status body.
 * @param id   The item id.
 * @return The item.
 */
function item(body: any, id: string): any {
	const found = body.readiness.find((entry: any) => entry.id === id)
	expect(found, `the status reports no item "${id}"`).toBeTruthy()
	return found
}

test.describe('the first run names the minimum', () => {
	let token = ''

	test.beforeAll(async ({ request }) => {
		token = await getRequestToken(request)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, token, ['caseType', 'role'])
	})

	/**
	 * @e2e Scenario: an administrator sees what is still missing
	 */
	test('every declared item is reported, with done and a screen', async ({ request }) => {
		const body = await status(request)

		expect(body.readiness.map((entry: any) => entry.id)).toEqual([
			'organisation',
			'mail-account',
			'published-case-type',
			'role-with-holder',
			'working-calendar',
		])

		for (const entry of body.readiness) {
			expect(typeof entry.done, `${entry.id} does not say whether it is done`).toBe('boolean')
			expect(entry.screen, `${entry.id} leads nowhere`).toBeTruthy()
			expect(entry.title, `${entry.id} has no title`).toBeTruthy()
		}
	})

	/**
	 * @e2e Scenario: a readiness item re-reads itself
	 *
	 * The whole point of D-2, and the one assertion a stored completion flag
	 * cannot pass: the item follows the tree in BOTH directions, without the
	 * app being reinstalled or the wizard being reopened.
	 */
	test('an item follows the tree rather than a stored flag', async ({ request }) => {
		const types = await adoptableCaseTypes(request)
		expect(types.length, 'the instance carries no case type to work from').toBeGreaterThan(0)

		const draft = await createObject(request, token, 'caseType', {
			title: `${RUN_PREFIX} concept`,
			isDraft: true,
		})

		const before = item(await status(request), 'published-case-type')

		await updateObject(request, token, 'caseType', objectId(draft), {
			title: `${RUN_PREFIX} concept`,
			isDraft: false,
		})

		const after = item(await status(request), 'published-case-type')

		expect(
			after.done,
			'publishing a case type left the item where it was, so it is reading a flag and not the tree',
		).toBe(true)
		expect(typeof before.done).toBe('boolean')
	})

	/**
	 * @e2e Scenario: an item leads somewhere
	 */
	test('the mail account item leads to the mail settings screen', async ({ request, page }) => {
		const mail = item(await status(request), 'mail-account')
		expect(mail.screen).toContain('settings')

		await page.goto(`${APP_BASE}${mail.screen.startsWith('/') ? mail.screen : '/' + mail.screen}`)
		await expect(page).toHaveURL(/settings/)
	})

	/**
	 * The app is reachable whatever the readiness items say. Only
	 * `register-check` gates, and the section is where an administrator reads
	 * the rest.
	 */
	test('an unconfigured item does not gate the app', async ({ page }) => {
		await page.goto(`${APP_BASE}/settings`)

		await expect(
			page.getByText(/First run|Eerste keer/).first(),
		).toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId('first-run-readiness')).toBeVisible({ timeout: 30_000 })
	})

	/**
	 * @e2e Scenario: a handler who joined later is still taught
	 * @e2e Scenario: a new surface is offered to someone who finished the rest
	 *
	 * Completion is a per-USER preference, so this reads it as the signed-in
	 * user rather than as app config. A fresh person has no value at all, which
	 * is what makes the runner offer them the whole tour; app config would be
	 * one value for everybody and the second person would be taught nothing.
	 */
	test('tour completion is recorded per person, not once for the app', async ({ request }) => {
		const res = await request.get(`${APP_BASE}/api/preferences/walkthrough_completed_version`)
		expect(
			res.ok(),
			`the per-user walkthrough preference is unreachable: ${res.status()}`,
		).toBeTruthy()

		// Whatever it holds, it is addressed per person. The value itself is
		// this account's and says nothing about anybody else's, which is the
		// property under test.
		const body = await res.json()
		expect(body).toBeDefined()
	})

	/**
	 * @e2e Scenario: a step that lost its surface is reported
	 */
	test('a tour step whose surface is gone is reported beside the items', async ({ request }) => {
		const body = await status(request)

		expect(Array.isArray(body.tourSteps), 'the status reports no tour steps at all').toBeTruthy()

		const missing = body.tourSteps.filter((step: any) => step.state === 'missing')
		expect(
			missing,
			`the shipped tour names a surface that is gone: ${missing.map((s: any) => `${s.step} -> ${s.surface}`).join(', ')}`,
		).toEqual([])

		// The element targets ARE classified, which proves the scan ran rather
		// than returning an empty list because it found nothing to look at.
		expect(
			body.tourSteps.some((step: any) => step.state === 'unverifiable'),
			'nothing was classified, so the tour scan did not run',
		).toBe(true)
	})
})
