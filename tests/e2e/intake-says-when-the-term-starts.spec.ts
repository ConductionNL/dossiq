/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Filing on Sunday evening does not start the clock on Sunday evening.
 *
 * 🔴 THE TWO MOMENTS ARE READ FROM THE STORED RECORD, NOT FROM THE SCREEN. A
 * page can render a date it computed itself and look right while the case
 * carries nothing, which is the failure mode of every "show the deadline"
 * feature. So each scenario asserts the RECORD first and the surface second.
 *
 * 🔴 THE NEGATIVE CASE IS THE ONE THAT CATCHES THE NOISE. A confirmation that
 * always explains the first working day passes any test that only files on a
 * Sunday, and it teaches every reader to skip the paragraph. The Tuesday
 * filing asserts the sentence is absent.
 *
 * 🔴 EVERY CASE THIS SPEC SEEDS IS CLEANED UP, and each carries the run
 * prefix: an unstamped fixture left on a shared instance is indistinguishable
 * from a case the listener failed to stamp.
 *
 * Runs against the nightly instance, not in the build loop.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { cleanupRunObjects, getRequestToken, REGISTER, RUN_PREFIX, seedCase, showObject } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`

let api: APIRequestContext
let token: string

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('a case records when it arrived and when its clock starts', () => {
	test('a Sunday evening filing starts on Monday morning', async () => {
		// 2026-09-13 is a Sunday. The arrival is declared by the intake
		// channel, which is what a portal submission does: the moment the
		// citizen pressed send, not the moment the listener happened to run.
		const caseId = await seedCase(api, token, {
			title: `${RUN_PREFIX} Zondagavond`,
			receivedAt: '2026-09-13T20:41:00+02:00',
		})

		const stored = await showObject(api, token, 'case', caseId)

		expect(stored.receivedAt).toContain('2026-09-13')
		expect(stored.termStartsAt).toContain('2026-09-14')
		expect(stored.receivedOutsideWorkingHours).toBe(true)
	})

	test('a Tuesday morning filing starts at once', async () => {
		const caseId = await seedCase(api, token, {
			title: `${RUN_PREFIX} Dinsdagochtend`,
			receivedAt: '2026-09-15T10:00:00+02:00',
		})

		const stored = await showObject(api, token, 'case', caseId)

		expect(stored.termStartsAt).toBe(stored.receivedAt)
		expect(stored.receivedOutsideWorkingHours).toBe(false)
	})

	test('the stamp does not move when the case is read again', async () => {
		const caseId = await seedCase(api, token, {
			title: `${RUN_PREFIX} Blijft staan`,
			receivedAt: '2026-09-13T20:41:00+02:00',
		})

		const first = await showObject(api, token, 'case', caseId)
		// A write that touches something else must not re-stamp: what the
		// citizen was told is a fact about the day they were told it.
		await api.put(`/index.php/apps/openregister/api/objects/dossiq/case/${caseId}`, {
			headers: { requesttoken: token },
			data: { description: 'aangevuld' },
		})
		const second = await showObject(api, token, 'case', caseId)

		expect(second.termStartsAt).toBe(first.termStartsAt)
	})
})

test.describe('the confirmation says when the clock starts', () => {
	test('the case page names the arrival, the start and the deadline', async ({ page }) => {
		trackDossiqErrors(page)
		const caseId = await seedCase(api, token, {
			title: `${RUN_PREFIX} Op het scherm`,
			receivedAt: '2026-09-13T20:41:00+02:00',
		})

		await page.goto(`${APP_URL}cases/${caseId}`, { waitUntil: PAGE_LOAD })
		await dismissSupportDialog(page)

		const panel = page.getByRole('region', { name: /Terms and payment|Termijnen en betaling/ })
		await expect(panel).toBeVisible()
		await expect(panel).toContainText(/13[-/ ]0?9|2026-09-13/)
		await expect(panel).toContainText(/14[-/ ]0?9|2026-09-14/)
	})

	test('a filing inside the window is given no explanation it does not need', async ({ page }) => {
		trackDossiqErrors(page)
		const caseId = await seedCase(api, token, {
			title: `${RUN_PREFIX} Geen uitleg nodig`,
			receivedAt: '2026-09-15T10:00:00+02:00',
		})

		await page.goto(`${APP_URL}cases/${caseId}`, { waitUntil: PAGE_LOAD })
		await dismissSupportDialog(page)

		// The sentence about the first working day belongs to the case that
		// needs it, and to no other.
		await expect(page.getByText(/eerste werkdag|first working day/i)).toHaveCount(0)
	})
})
