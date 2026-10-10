/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * pipelinq on the case: the boards DqZaakContactmomenten, DqZaakPartijen and
 * DqZaaktype as they run on an instance that HAS pipelinq.
 *
 * Every assertion reads the outcome back over the API that owns it (dossiq's
 * PipelinqCaseController, which answers through pipelinq), because the thing
 * these surfaces exist to prevent is a panel that looks right and is empty:
 * an absent pipelinq drawn as "no contact moments", or a declaration that
 * no-opped. The tests skip, saying why, when pipelinq is not installed: that
 * instance is the fallback, which the unit tests cover.
 *
 * Live pass owed (decision 139): written by the build lane, run by the
 * live-pass agent on an instance with dossiq and pipelinq installed.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openCasePanel } from './helpers/case-panels.ts'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { clickHeaderAction } from './helpers/nav.ts'

const BASE = `/index.php/apps/${REGISTER}/api`

let api: APIRequestContext
let token: string
let caseTypeId = ''
let caseA = ''
let caseB = ''
let pipelinqHere = false

test.describe('pipelinq on the case', () => {
	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({
			baseURL,
			httpCredentials: { username: 'admin', password: 'admin' },
		})
		token = await getRequestToken(api)
		const types = await adoptableCaseTypes(api)
		caseTypeId = objectId(types[0])
		caseA = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} pipelinq A`,
				caseType: caseTypeId,
			}),
		)
		caseB = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} pipelinq B`,
				caseType: caseTypeId,
			}),
		)

		const probe = await api.get(
			`${BASE}/cases/${caseA}/pipelinq/contact-moments`,
			{ headers: { requesttoken: token } },
		)
		pipelinqHere = probe.ok() && (await probe.json())?.available === true
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#a-logged-call-reaches-both-records
	test('a call logged from the case page reaches dossiq and pipelinq', async ({
		page,
	}) => {
		test.skip(!pipelinqHere, 'pipelinq is not installed on this instance')
		const summary = `${RUN_PREFIX} rang about the stukken`

		await page.goto(`/apps/${REGISTER}/cases/${caseA}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
		await clickHeaderAction(page, 'cn-action-log-contact')
		const dialog = page.locator('[data-testid="log-contact-dialog"]')
		await dialog
			.locator('[data-testid="log-contact-summary"]')
			.getByRole('textbox')
			.fill(summary)
		await dialog.locator('[data-testid="log-contact-confirm"]').click()
		await expect(
			dialog.locator('[data-testid="log-contact-saved"]'),
		).toBeVisible({ timeout: 20_000 })

		await expect(async () => {
			const res = await api.get(
				`${BASE}/cases/${caseA}/pipelinq/contact-moments`,
				{ headers: { requesttoken: token } },
			)
			const body = await res.json()
			const found = (body.moments || []).find((m: any) =>
				String(m.summary || '').includes(summary),
			)
			expect(
				found,
				'pipelinq holds the same moment, hosted on the case',
			).toBeTruthy()
			expect(found.direction).toBe('inbound')
		}).toPass({ timeout: 30_000 })
	})

	// @e2e openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#one-call-on-three-cases-appears-on-each
	test('a moment filed on a second case shows on both, with the shared line', async ({
		page,
	}) => {
		test.skip(!pipelinqHere, 'pipelinq is not installed on this instance')

		const before = await (
			await api.get(`${BASE}/cases/${caseA}/pipelinq/contact-moments`, {
				headers: { requesttoken: token },
			})
		).json()
		const moment = (before.moments || []).find((m: any) =>
			String(m.summary || '').startsWith(RUN_PREFIX),
		)
		test.skip(!moment, 'the previous test logged no moment to file')

		const filed = await api.post(
			`${BASE}/cases/${caseA}/pipelinq/contact-moments/${moment.id}/file`,
			{
				headers: { requesttoken: token },
				data: { targetCaseId: caseB },
			},
		)
		expect(filed.status(), await filed.text()).toBe(200)

		await page.goto(`/apps/${REGISTER}/cases/${caseB}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
		const panel = await openCasePanel(page, 'customerRecord')
		const row = panel
			.locator('[data-testid="case-pipelinq-moment"]')
			.filter({ hasText: moment.summary })
		await expect(row).toHaveCount(1, { timeout: 20_000 })
		await expect(
			row.locator('[data-testid="case-pipelinq-moment-shared"]'),
		).toBeVisible()
	})

	// @e2e openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#a-subsidy-case-type-declares-two-kinds
	test('a case type declares its kinds in order and pipelinq holds them', async () => {
		test.skip(!pipelinqHere, 'pipelinq is not installed on this instance')

		const vocabulary = await (
			await api.get(`${BASE}/case-types/${caseTypeId}/pipelinq/party-kinds`, {
				headers: { requesttoken: token },
			})
		).json()
		const codes = (vocabulary.kinds || [])
			.map((k: any) => String(k.code || ''))
			.filter(Boolean)
		test.skip(
			codes.length < 2,
			'pipelinq holds fewer than two party kinds on this instance',
		)

		const declared = [codes[1], codes[0]]
		const put = await api.put(
			`${BASE}/case-types/${caseTypeId}/pipelinq/party-kinds`,
			{
				headers: { requesttoken: token },
				data: { kinds: declared },
			},
		)
		expect(put.status(), await put.text()).toBe(200)

		const after = await (
			await api.get(`${BASE}/case-types/${caseTypeId}/pipelinq/party-kinds`, {
				headers: { requesttoken: token },
			})
		).json()
		expect(after.accepted, 'read back in the declared order').toEqual(declared)
	})

	// @e2e openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#a-case-already-in-a-programme-is-refused-with-the-holder-named
	test('a case already under a programme is refused with the holder named', async () => {
		test.skip(!pipelinqHere, 'pipelinq is not installed on this instance')

		const options = await (
			await api.get(`${BASE}/pipelinq/programmes`, {
				headers: { requesttoken: token },
			})
		).json()
		const programmes = options.programmes || []
		test.skip(
			programmes.length < 2,
			'pipelinq holds fewer than two programmes on this instance',
		)

		const first = await api.post(`${BASE}/cases/${caseA}/pipelinq/programme`, {
			headers: { requesttoken: token },
			data: { programmeId: programmes[0].id, title: 'pipelinq A' },
		})
		expect(first.status(), await first.text()).toBe(200)

		const second = await api.post(`${BASE}/cases/${caseA}/pipelinq/programme`, {
			headers: { requesttoken: token },
			data: { programmeId: programmes[1].id },
		})
		expect(second.status()).toBe(409)
		expect(String((await second.json()).error)).toContain(programmes[0].id)
	})
})
