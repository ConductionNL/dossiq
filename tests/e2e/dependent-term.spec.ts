/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case that waits on another, and a term that moved behind it.
 *
 * What only a live instance can show here is the reverse index: `waitsOn` is
 * written once, on the case that declares it, and the offer is made from the
 * OTHER side. A unit test can stub the rows that come back; only OpenRegister
 * can show that the link written on the vergunning is the one the bezwaar
 * reads, under the other half of its name.
 *
 * THE OFFER IS ASSERTED ON THE TASK, NOT ON A TOAST. The task's text is Dutch
 * prose and the instance's language is not pinned, so what is asserted is the
 * metadata dossiq wrote and the case the task sits on.
 *
 * NOTHING MOVES UNTIL SOMEONE ACCEPTS, and that is the assertion that matters
 * most: the vergunning's own end date is read again after the bezwaar's term
 * is extended, and it has to be unchanged.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	listFlowTasks,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

let caseTypeId = ''

/** The bezwaar that moves, and the vergunning that waits on it. */
const cases: Record<string, string> = {}

/** The term instances of both, so their end dates can be read back. */
const terms: Record<string, string> = {}

test.describe('A dependent term follows its predecessor', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id

		const seed = async (key: string) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType: caseTypeId,
				description: 'Throwaway case for the dependent-term e2e layer.',
				startDate: new Date().toISOString().slice(0, 10),
			})
			cases[key] = objectId(row)
		}

		await seed('Bezwaar')
		await seed('Vergunning')

		// A running term on each, because an offer is only made to a case that
		// has something to extend.
		for (const key of ['Bezwaar', 'Vergunning']) {
			const instance = await createObject(api, token, 'deadlineInstance', {
				case: cases[key],
				status: 'lopend',
				startDate: new Date().toISOString(),
				endDateCalculated: '2026-12-01',
				endDateCurrent: '2026-12-01',
				countExtensions: 0,
			})
			terms[key] = objectId(instance)
		}

		// The vergunning declares the wait. One write, on the declaring side.
		await api.post(
			`/index.php/apps/${REGISTER}/api/cases/${cases.Vergunning}/relations`,
			{
				headers: { requesttoken: token },
				data: { targetId: cases.Bezwaar, aardRelatie: 'waitsOn' },
			},
		)

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md#scenario-link-a-vergunning-to-the-bezwaar-it-waits-on
	test('Each side reads the link under its own half of the name', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${cases.Vergunning}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
		await page.getByText('Related', { exact: true }).first().click()
		await expect(page.getByText('wacht op').first()).toBeVisible({
			timeout: 30_000,
		})

		await page.goto(`/apps/${REGISTER}/cases/${cases.Bezwaar}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
		await page.getByText('Related', { exact: true }).first().click()
		await expect(page.getByText('blokkeert').first()).toBeVisible({
			timeout: 30_000,
		})
	})

	// @e2e openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md#scenario-the-handler-is-offered-the-extension
	test('The moved term is offered, and moves nothing by itself', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const extended = await api.post(
			`/index.php/apps/${REGISTER}/api/termijn/instances/${terms.Bezwaar}/verleng`,
			{
				headers: { requesttoken: token },
				data: {
					rationale: 'Meer tijd nodig voor de hoorzitting.',
					newEinddatum: '2026-12-15',
				},
			},
		)
		expect(extended.ok()).toBeTruthy()

		// The vergunning's handler is offered the same move.
		await expect
			.poll(
				async () =>
					(
						await listFlowTasks(api, { objectUuid: cases.Vergunning })
					).filter(
						(row: any) => row?.metadata?.dossiq?.kind === 'term-follow',
					).length,
				{
					timeout: 60_000,
					message: 'The moved term was never offered to the waiting case',
				},
			)
			.toBe(1)

		// And nothing moved on its own. This is the assertion the whole change
		// exists for: Awb 4:14 wants the applicant told first, so a person
		// decides.
		const waiting = await showObject(api, 'deadlineInstance', terms.Vergunning)
		expect(String(waiting.endDateCurrent ?? '')).toBe('2026-12-01')

		await api.dispose()
	})

	// @e2e openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md#scenario-accepting-extends-with-the-reason
	test('Accepting moves the waiting term by the offered days', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const offer = (
			await listFlowTasks(api, { objectUuid: cases.Vergunning })
		).find((row: any) => row?.metadata?.dossiq?.kind === 'term-follow')
		expect(offer, 'no term-follow offer was written').toBeTruthy()
		expect(String(offer.metadata.dossiq.sourceCase)).toBe(cases.Bezwaar)
		expect(Number(offer.metadata.dossiq.daysImpact)).toBe(14)

		const accepted = await api.post(
			`/index.php/apps/${REGISTER}/api/case/${cases.Vergunning}/term-follow/${offer.id}/accept`,
			{ headers: { requesttoken: token } },
		)
		expect(accepted.ok()).toBeTruthy()

		await expect
			.poll(
				async () =>
					String(
						(await showObject(api, 'deadlineInstance', terms.Vergunning))
							.endDateCurrent ?? '',
					),
				{
					timeout: 30_000,
					message: 'Accepting the offer did not move the waiting term',
				},
			)
			.toBe('2026-12-15')

		await api.dispose()
	})
})
