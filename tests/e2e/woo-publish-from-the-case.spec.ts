/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-publish-decision-from-the-case: a handler publishes a ready Woo decision
 * from the case page, sees the link, and withdraws it.
 *
 * NOT RUN IN THIS LANE (decision 139). No Playwright runs here. The suite is
 * written so the live pass owns it.
 *
 * THE PUBLISH ITSELF NEEDS OPENCATALOGI. The tests that publish skip when the
 * publish endpoint answers `opencatalogi_unavailable`, so a run on an instance
 * without OpenCatalogi reads as skipped, never as a pass.
 *
 * PLAYWRIGHT SIGNS IN AS ADMIN. The header gate is `assignee eq @me`, so every
 * case here is seeded with `assignee: admin`.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedDocument,
	showObject,
} from './helpers/fixtures.ts'
import { openHeaderActionsMenu, PAGE_LOAD } from './helpers/nav.ts'

/** The uid Playwright signs in as. */
const ADMIN_USER = 'admin'

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

/**
 * The headers a dossiq write needs.
 *
 * @param token The CSRF request token.
 * @return The headers.
 */
function headers(token: string): Record<string, string> {
	return {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		'Content-Type': 'application/json',
	}
}

test.describe('Publish a Woo decision from the case', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		const caseTypeId = (await ensureCaseType(api, token)).id

		for (const key of ['none', 'ready']) {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} Woo ${key}`,
				caseType: caseTypeId,
				description: 'Throwaway case for the woo-publish-from-the-case e2e.',
				startDate: new Date().toISOString().slice(0, 10),
				assignee: ADMIN_USER,
			})
			cases[key] = objectId(row)
		}

		// The ready case gets one document assessed as public and an assembled
		// decision, through the same routes a handler's screens use.
		const document = await seedDocument(api, token, {
			title: `${RUN_PREFIX} Woo besluit bijlage`,
			case: cases.ready,
		})
		const assessed = await api.post(
			`/index.php/apps/${REGISTER}/api/cases/${cases.ready}/woo/assessment`,
			{
				headers: headers(token),
				data: {
					assessments: [
						{
							documentRef: objectId(document),
							classification: 'openbaar',
						},
					],
				},
			},
		)
		expect(
			assessed.ok(),
			`assess -> ${assessed.status()} ${await assessed.text()}`,
		).toBeTruthy()
		const decision = await api.post(
			`/index.php/apps/${REGISTER}/api/cases/${cases.ready}/woo/decision`,
			{ headers: headers(token), data: {} },
		)
		expect(
			decision.ok(),
			`assemble -> ${decision.status()} ${await decision.text()}`,
		).toBeTruthy()

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		await cleanupRunObjects(api, await getRequestToken(api))
		await api.dispose()
	})

	// @e2e openspec/specs/woo-publication-via-opencatalogi/spec.md#scenario-no-woo-decision-yet
	test('a case without a Woo decision answers 409 no_woo_decision', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const res = await request.post(
			`/index.php/apps/${REGISTER}/api/cases/${cases.none}/woo/publish`,
			{ headers: headers(token), data: {} },
		)
		const body = await res.json()
		test.skip(
			body?.reason === 'opencatalogi_unavailable',
			'OpenCatalogi is not installed on this instance',
		)
		expect(res.status()).toBe(409)
		expect(body.reason).toBe('no_woo_decision')
	})

	// @e2e openspec/specs/woo-publication-via-opencatalogi/spec.md#scenario-unpublished-decision-shows-a-publish-action
	test('a ready case offers Publish (Woo) and shows ready on the Data tab', async ({
		page,
		request,
	}) => {
		const stored = await showObject(request, 'case', cases.ready)
		expect(stored.wooPublicationStatus).toBe('ready')

		await page.goto(`/apps/${REGISTER}/cases/${cases.ready}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
		await openHeaderActionsMenu(page)
		await expect(page.getByTestId('cn-action-woo-publish')).toBeVisible()
		await expect(page.getByTestId('cn-action-woo-withdraw')).toHaveCount(0)
	})

	// @e2e openspec/specs/woo-publication-via-opencatalogi/spec.md#scenario-publish-without-a-decision-id
	// @e2e openspec/specs/woo-publication-via-opencatalogi/spec.md#scenario-published-decision-shows-its-status-and-link
	test('the handler publishes without a decision id, sees the link, and withdraws', async ({
		page,
		request,
	}) => {
		const token = await getRequestToken(request)
		const res = await request.post(
			`/index.php/apps/${REGISTER}/api/cases/${cases.ready}/woo/publish`,
			{ headers: headers(token), data: {} },
		)
		const body = await res.json()
		test.skip(
			body?.reason === 'opencatalogi_unavailable',
			'OpenCatalogi is not installed on this instance',
		)
		expect(
			res.ok(),
			`publish -> ${res.status()} ${JSON.stringify(body)}`,
		).toBeTruthy()

		const published = await showObject(request, 'case', cases.ready)
		expect(published.wooPublicationStatus).toBe('published')
		expect(String(published.wooPublicationUrl)).toContain('publication')

		await page.goto(`/apps/${REGISTER}/cases/${cases.ready}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
		await openHeaderActionsMenu(page)
		await expect(page.getByTestId('cn-action-woo-publish')).toHaveCount(0)
		await expect(page.getByTestId('cn-action-woo-withdraw')).toBeVisible()
		await expect(
			page.getByTestId('cn-action-woo-publication-open'),
		).toBeVisible()

		const withdrawn = await request.post(
			`/index.php/apps/${REGISTER}/api/cases/${cases.ready}/woo/withdraw`,
			{ headers: headers(token), data: {} },
		)
		expect(withdrawn.ok()).toBeTruthy()
		expect(
			(await showObject(request, 'case', cases.ready)).wooPublicationStatus,
		).toBe('withdrawn')
	})
})
