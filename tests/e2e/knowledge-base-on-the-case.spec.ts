/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The work instruction for this kind of case, next to the case.
 *
 * A handler who meets an unusual bezwaar has nowhere to read how this
 * municipality handles it. Collectives is the wiki, its pages live in a
 * Nextcloud team, and that team is what makes visibility per role. dossiq
 * declares which leaves a case carries and which page a case type points at,
 * and stores no article text of its own.
 *
 * 🔴 THE DECLARATION IS ASSERTED AGAINST THE LIVE SCHEMA, NOT THE FILE.
 * `knowledgeBasePage` has to be a declared property of `caseType` or
 * OpenRegister's magic mapper drops the value on the way in: the save answers
 * 200, the field comes back empty, and the page then reads exactly like a
 * case type nobody filled in. Reading the register file would report green on
 * precisely that, so the schema is read back from OpenRegister and the value
 * is written and read back through the API.
 *
 * 🔴 THE TAB IS ABSENT WITHOUT THE APP, AND THAT IS NOT A FAILURE. A leaf
 * whose `requiredApp` is not installed is never registered, so on an instance
 * without Collectives there is no Knowledge tab at all. The first test says
 * which of the two worlds this instance is in, so a later absence is read as
 * the declared behaviour rather than as a broken tab.
 *
 * THE CASE IS ADDRESSED BY ITS RUN PREFIX, NEVER BY POSITION. The Cases index
 * is a shared list on a shared instance and another session's fixtures land in
 * it while this one runs.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const SCHEMAS = `/index.php/apps/openregister/api/schemas`

/** A page that exists nowhere, so a reader outside the team reads nothing. */
const INSTRUCTION_URL = '/index.php/apps/collectives/handhaving/werkinstructie-bezwaar'

let api: APIRequestContext
let token: string
let caseTypeId = ''
let caseId = ''
let collectivesInstalled = false

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)
	const caseType = await ensureCaseType(api, token)
	caseTypeId = caseType.id
	const seeded = await seedCase(api, token, {
		title: 'Knowledge base on the case',
		caseType: caseTypeId,
	})
	caseId = objectId(seeded)

	const apps = await api.get('/index.php/apps/openregister/api/integrations', {
		headers: { requesttoken: token },
	})
	if (apps.ok()) {
		const body = await apps.json()
		collectivesInstalled = JSON.stringify(body).includes('collectives')
	}
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('the case type carries its work instruction', () => {
	// @e2e openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md#scenario-every-case-of-a-type-shows-its-instruction
	test('the caseType schema on this instance declares knowledgeBasePage', async () => {
		const res = await api.get(`${SCHEMAS}?_limit=200`, {
			headers: { requesttoken: token },
		})
		expect(res.ok(), `the schema list answered ${res.status()}`).toBeTruthy()
		const body = await res.json()
		const rows = body.results ?? body.data ?? []
		const caseType = rows.find((s: any) => (s.slug ?? s.title) === 'caseType')

		expect(caseType, 'this instance has no caseType schema').toBeTruthy()
		expect(
			(caseType.properties || {}).knowledgeBasePage,
			'the caseType schema does not declare knowledgeBasePage, so every value written to it is dropped in silence and the field reads as one nobody filled',
		).toBeTruthy()
	})

	// @e2e openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md#scenario-every-case-of-a-type-shows-its-instruction
	test('a work instruction written on the case type comes back from the register', async () => {
		await updateObject(api, token, 'caseType', caseTypeId, {
			knowledgeBasePage: INSTRUCTION_URL,
		})
		const stored = await showObject(api, 'caseType', caseTypeId)
		expect(
			stored.knowledgeBasePage,
			'the work instruction did not survive the write, which is what an undeclared property looks like',
		).toBe(INSTRUCTION_URL)
	})

	// @e2e openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md#scenario-every-case-of-a-type-shows-its-instruction
	test('the case type page offers the field to the person who authors the type', async ({ page }) => {
		await page.goto(`/apps/${REGISTER}/#/case-types/${caseTypeId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect(page.getByText('Work instruction')).toBeVisible()
	})
})

test.describe('the pages render through the leaf, or the tab is absent', () => {
	// @e2e openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md#scenario-a-handler-outside-the-team-does-not-read-the-page
	test('the Knowledge tab is present exactly when Collectives is', async ({ page }) => {
		await page.goto(`/apps/${REGISTER}/#/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const tab = page.getByRole('tab', { name: 'Knowledge' })
		if (collectivesInstalled) {
			await expect(
				tab,
				'Collectives is installed but the case shows no Knowledge tab',
			).toBeVisible()
		} else {
			await expect(
				tab,
				'Collectives is not installed, so the tab must be absent rather than empty',
			).toHaveCount(0)
		}
	})

	// @e2e openspec/changes/knowledge-base-on-the-case/specs/case-knowledge-base/spec.md#scenario-a-handler-outside-the-team-does-not-read-the-page
	test('dossiq shows no page body of its own', async ({ page }) => {
		test.skip(!collectivesInstalled, 'no Collectives on this instance, so there is no leaf to read')

		await page.goto(`/apps/${REGISTER}/#/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await page.getByRole('tab', { name: 'Knowledge' }).click()

		// The case links no page, and the case type's instruction lives in a
		// collective this account is not in. A tab that rendered body text here
		// would be dossiq holding a copy, which is the one thing it must not do.
		const body = await page.locator('.cn-collectives-tab').innerText()
		expect(
			body,
			'the Knowledge tab rendered page content for a page this account cannot open in Collectives',
		).not.toContain('werkinstructie')
	})
})
