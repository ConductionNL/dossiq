/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two cases become one, and the old number still finds it.
 *
 * What only a browser can show here is that the merge is a gesture on the case
 * page and not only an endpoint: the header action opens the picker, the
 * survivor is searched for and chosen, and the rows that hung off the case
 * being merged away are afterwards on the case that survived.
 *
 * THE REFUSALS ARE ASSERTED OVER THE API, NOT OFF A TOAST. The sentences are
 * translated and nothing pins the language of the e2e instance; the status and
 * the `code` beside it are the same everywhere, and they are what the dialog
 * acts on.
 *
 * PLAYWRIGHT SIGNS IN AS ADMIN, which passes `CaseAccessGuard` on any case. So
 * the refusal asserted here is the one the MERGE rule makes on a case with a
 * signed decision, not the per-case authorization one: an admin cannot take a
 * lesser role in a browser, and that branch belongs to the controller's own
 * unit test.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

let caseTypeId = ''

/** One case per role in the story, so no test depends on another's writes. */
const cases: Record<string, string> = {}

test.describe('Merge two cases into one', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id

		const seed = async (key: string, extra: Record<string, unknown> = {}) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} Merge ${key}`,
				caseType: caseTypeId,
				description: 'Throwaway case for the case-merge e2e layer.',
				startDate: new Date().toISOString().slice(0, 10),
				...extra,
			})
			cases[key] = objectId(row)
		}

		// The survivor, the duplicate, and one case that may never be merged
		// away because it carries a signed decision.
		await seed('survivor')
		await seed('duplicate')
		await seed('decided', { besluitDocument: `${RUN_PREFIX}-besluit` })

		// A party on each half, so the merge has something to move and the
		// survivor's own rows can be shown to be left alone.
		await createObject(api, token, 'role', {
			name: `${RUN_PREFIX} Aanvrager duplicaat`,
			case: cases.duplicate,
		})
		await createObject(api, token, 'role', {
			name: `${RUN_PREFIX} Aanvrager overlevende`,
			case: cases.survivor,
		})

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * The parties currently hanging off one case.
	 *
	 * @param api An authenticated request context.
	 * @param id The case id.
	 * @return The party names, sorted.
	 */
	const partiesOn = async (api: any, id: string): Promise<string[]> =>
		(await listObjects(api, 'role', { case: id }))
			.map((row: any) => String(row?.name ?? ''))
			.sort()

	// @e2e openspec/changes/case-merge/specs/case-management/spec.md#scenario-a-duplicate-is-merged
	test('The duplicate is merged and the survivor carries both parties', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const errors = trackDossiqErrors(page)

		await page.goto(`/apps/${REGISTER}/cases/${cases.duplicate}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-case-merge')
		await expect(page.getByTestId('case-merge-dialog')).toBeVisible({
			timeout: 30_000,
		})

		// The survivor is searched for and picked. Nothing is preselected, so a
		// confirm without a pick is refused by the button itself.
		await expect(page.getByTestId('case-merge-confirm')).toBeDisabled()
		await page
			.getByTestId('case-merge-search')
			.locator('input')
			.fill(`${RUN_PREFIX} Merge survivor`)
		await page
			.getByTestId(`case-merge-option-${cases.survivor}`)
			.click({ timeout: 30_000 })
		await page
			.getByTestId('case-merge-reason')
			.locator('textarea')
			.fill('Dezelfde aanvraag, twee keer ingediend.')
		await page.getByTestId('case-merge-confirm').click()

		const api = await playwright.request.newContext({ baseURL })

		await expect
			.poll(async () => await partiesOn(api, cases.survivor), {
				timeout: 30_000,
				message:
					'The merge did not move the duplicate’s party onto the survivor',
			})
			.toEqual([
				`${RUN_PREFIX} Aanvrager duplicaat`,
				`${RUN_PREFIX} Aanvrager overlevende`,
			])

		const merged = await showObject(api, 'case', cases.duplicate)
		expect(String(merged.mergedInto ?? '')).toBe(cases.survivor)
		expect(String(merged.endingAct ?? '')).toBe('merged')

		await api.dispose()
		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/changes/case-merge/specs/case-management/spec.md#scenario-the-old-public-link
	test('The merged case’s public link shows the survivor’s status', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// The link the applicant was given BEFORE the merge, on the case that
		// is merged away by the test above.
		const minted = await api.post('/index.php/apps/dossiq/api/shares', {
			headers: { requesttoken: token },
			data: {
				caseId: cases.duplicate,
				shareType: 'link',
				label: `${RUN_PREFIX} volg uw zaak`,
				capabilities: ['read'],
			},
		})
		expect(minted.ok()).toBeTruthy()
		const anchor =
			String((await minted.json())?.url ?? '')
				.split('/')
				.filter((part: string) => part !== '')
				.pop() ?? ''
		expect(anchor).not.toBe('')
		await api.dispose()

		// Opened with no session at all, the way an applicant opens it.
		await page.context().clearCookies()
		await page.goto(`/apps/${REGISTER}/public/status/${anchor}`, PAGE_LOAD)

		await expect(page.getByTestId('public-status-merged-from')).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByTestId('public-status-value')).not.toBeEmpty()
	})

	test('A case with a signed decision is refused as a merge source', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const refused = await api.post(
			`/index.php/apps/${REGISTER}/api/case/${cases.decided}/merge`,
			{
				headers: { requesttoken: token },
				data: { into: cases.survivor, reason: 'Zou niet mogen.' },
			},
		)

		expect(refused.status()).toBe(409)
		expect((await refused.json()).code).toBe('signed-beschikking')

		// And the refusal is a refusal, not a message over a write that landed.
		const still = await showObject(api, 'case', cases.decided)
		expect(String(still.mergedInto ?? '')).toBe('')

		await api.dispose()
	})
})
