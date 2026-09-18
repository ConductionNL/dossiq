/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Splitting a case from its own page.
 *
 * The rules for this shipped first and were reachable from nothing, so what is
 * asserted here is the reaching: a handler opens the case, ticks a document,
 * confirms, and the document is afterwards on the new case and NOT on the old
 * one. A unit test can show the performer patched a row; only a live register
 * can show that the original no longer holds it, which is the whole difference
 * between a split and the copy beside it.
 *
 * THE REFUSAL IS ASSERTED OVER THE API. The sentence is the policy's and is
 * translated; the status is the same in every language.
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

/** The cases seeded for this run. */
const cases: Record<string, string> = {}

/** The documents on the case that gets split. */
const docs: Record<string, string> = {}

test.describe('A case splits from its own page', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id

		const row = await seedCase(api, token, {
			title: `${RUN_PREFIX} Twee klachten op een formulier`,
			caseType: caseTypeId,
			startDate: new Date().toISOString().slice(0, 10),
		})
		cases.source = objectId(row)

		for (const n of [1, 2, 3]) {
			const doc = await createObject(api, token, 'caseDocument', {
				case: cases.source,
				title: `${RUN_PREFIX} stuk ${n}`,
			})
			docs[`d${n}`] = objectId(doc)
		}

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/case-split-surface/specs/case-management/spec.md#scenario-a-chosen-document-moves-and-the-rest-stays
	test('A ticked document moves to the new case and leaves the old one', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const errors = trackDossiqErrors(page)

		await page.goto(`/apps/${REGISTER}/cases/${cases.source}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

		await clickHeaderAction(page, 'cn-action-case-split')
		await expect(page.getByTestId('case-split-dialog')).toBeVisible({
			timeout: 30_000,
		})

		// Nothing is ticked to begin with, because a split cannot be undone by
		// unticking afterwards.
		await expect(page.getByTestId('case-split-confirm')).toBeDisabled()

		await page
			.getByTestId('case-split-title')
			.locator('input')
			.fill(`${RUN_PREFIX} tweede klacht`)
		await page.getByTestId(`case-split-documents-${docs.d1}`).click()
		await page.getByTestId('case-split-confirm').click()

		const api = await playwright.request.newContext({ baseURL })

		// 🔴 IT LEFT. A split that copied would pass "does the new case have
		// it" and leave both cases claiming one document.
		await expect
			.poll(
				async () =>
					String((await showObject(api, 'caseDocument', docs.d1)).case ?? ''),
				{ timeout: 30_000, message: 'the ticked document never moved' },
			)
			.not.toBe(cases.source)

		expect(
			(await listObjects(api, 'caseDocument', { case: cases.source })).length,
		).toBe(2)

		await api.dispose()
		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/changes/case-split-surface/specs/case-management/spec.md#scenario-a-forbidden-part-is-refused-in-the-policys-own-words
	test('A case type that forbids dividing documents refuses, and says what may still be divided', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const caseType = await showObject(api, 'caseType', caseTypeId)
		await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/caseType/${caseTypeId}`,
			{
				headers: { requesttoken: token },
				data: { ...caseType, splittableParts: ['parties', 'tasks'] },
			},
		)

		// The picker is offered only what may be divided, so documents is not
		// among the parts it names.
		const offered = await api.get(
			`/index.php/apps/${REGISTER}/api/case/${cases.source}/split`,
		)
		expect(offered.ok()).toBeTruthy()
		expect((await offered.json()).allowed).not.toContain('documents')

		const refused = await api.post(
			`/index.php/apps/${REGISTER}/api/case/${cases.source}/split`,
			{ headers: { requesttoken: token }, data: { documents: [docs.d2] } },
		)
		expect(refused.status()).toBe(409)

		// The sentence names what may STILL be divided, because a handler told
		// only what they may not do guesses at the rest.
		const message = String((await refused.json()).error ?? '')
		expect(message).toContain('parties')

		// And the refusal is a refusal: the document did not move.
		expect(String((await showObject(api, 'caseDocument', docs.d2)).case)).toBe(
			cases.source,
		)

		await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/caseType/${caseTypeId}`,
			{ headers: { requesttoken: token }, data: caseType },
		)
		await api.dispose()
	})
})
