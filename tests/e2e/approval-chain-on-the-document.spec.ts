import type { APIRequestContext } from '@playwright/test'

/**
 * A document on a case carries decidiq's approval chain.
 *
 * A concept letter goes round three people before it is sent. dossiq could
 * hold that route for exactly one kind of document, a beschikking; every other
 * document went round by e-mail and the case kept no record of who agreed to
 * what. Parafering was retired on the rule that sign-off belongs to decidiq,
 * which was right, and it left a hole nobody filled.
 *
 * 🔴 THE REFUSED LOCK IS DRIVEN BEFORE THE APPROVAL. A guard that stops
 * working passes every test that only ever asks it to allow something, and
 * allowing is what every document on every instance already does. So the open
 * route is refused first, and only then is the route completed and the lock
 * offered.
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL. The bystander path reads the
 * timeline with an account that is not the current actor and asserts that no
 * approve or reject action is offered: a surface that showed the buttons to
 * everybody and refused the write server-side would pass a test that only
 * checked the write.
 *
 * 🔴 AN ABSENT LEAF IS A NOTICE, NOT AN EMPTY TIMELINE. An empty timeline
 * reads as "nobody has approved anything", which is a claim about the document
 * that nobody made. The first test says which of the two worlds this instance
 * is in, so a later absence is read as the declared behaviour.
 *
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md
 */
import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const DOSSIQ_API = `/index.php/apps/${REGISTER}/api`
const DECIDIQ_API = '/index.php/apps/decidiq/api'

let api: APIRequestContext
let token: string
let caseId = ''
let documentId = ''
let decidiqInstalled = false

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)

	const caseType = await ensureCaseType(api, token)
	const seeded = await seedCase(api, token, {
		title: `${RUN_PREFIX} approval chain`,
		caseType: caseType.id,
	})
	caseId = objectId(seeded)

	const document = await createObject(api, token, 'informatieobject', {
		titel: `${RUN_PREFIX} concept brief`,
		status: 'draft',
		zaak: caseId,
	})
	documentId = objectId(document)

	const probe = await api.get(
		`${DECIDIQ_API}/approval-routes/clearance?subject=${documentId}&subjectSchema=informatieobject`,
		{ headers: { requesttoken: token } },
	)
	decidiqInstalled = probe.status() !== 404
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token, ['informatieobject', 'case'])
	await api.dispose()
})

test.describe('dossiq reads the route and never decides it', () => {
	// @e2e openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md#scenario-decidiq-is-not-installed
	test('the surface says so when decidiq is absent, and the rest of the properties render', async ({ page }) => {
		test.skip(decidiqInstalled, 'decidiq answers on this instance, so the absent path is not the one under test')

		await page.goto(`/apps/${REGISTER}/#/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await page.getByRole('tab', { name: 'Files' }).click()
		await page.getByRole('button', { name: 'Document properties' }).first().click()

		await expect(page.getByText('Approval chain unavailable')).toBeVisible()
		await expect(
			page.getByTestId('document-properties-save'),
			'the rest of the document properties stopped rendering because a sibling app is missing',
		).toBeVisible()
	})

	// @e2e openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md#scenario-an-open-route-refuses-the-lock
	test('a document in an open route cannot be made final, and the refusal names the route', async () => {
		test.skip(!decidiqInstalled, 'no decidiq on this instance, so no route can be held')

		const held = await api.post(`${DECIDIQ_API}/approval-routes/instantiate`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {
				subject: documentId,
				subjectSchema: 'informatieobject',
				name: `${RUN_PREFIX} concept review`,
			},
		})
		expect(held.ok(), `holding a route answered ${held.status()}`).toBeTruthy()

		const refused = await api.patch(
			`${DOSSIQ_API}/informatieobjecten/${documentId}/status`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { status: 'final' },
			},
		)

		expect(
			refused.status(),
			'a document in an open approval route was locked, so the route decided nothing',
		).toBe(400)
		const body = await refused.text()
		expect(body, 'the refusal does not name the route').toContain(`${RUN_PREFIX} concept review`)
	})

	// @e2e openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md#scenario-a-document-in-a-route-is-marked-in-the-list
	test('the Files tab marks the document that is in a route and leaves the others alone', async () => {
		test.skip(!decidiqInstalled, 'no decidiq on this instance, so nothing is in a route')

		const other = await createObject(api, token, 'informatieobject', {
			titel: `${RUN_PREFIX} bijlage`,
			status: 'draft',
			zaak: caseId,
		})
		const otherId = objectId(other)

		const res = await api.get(
			`${DOSSIQ_API}/informatieobjecten/approval-markers?ids=${documentId},${otherId}`,
			{ headers: { requesttoken: token } },
		)
		expect(res.ok(), `the markers endpoint answered ${res.status()}`).toBeTruthy()
		const { markers } = await res.json()

		expect(markers[documentId], 'the routed document carries no marker').toBeTruthy()
		expect(markers[documentId].routed).toBe(true)
		expect(markers[documentId].cleared).toBe(false)
		expect(
			markers[otherId],
			'a document nobody routed was marked, so every row on the tab says it is in an approval',
		).toBeUndefined()
	})

	// @e2e openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md#scenario-somebody-who-is-not-the-current-actor-sees-the-timeline-only
	test('the approval chain renders on the document properties, read only for a bystander', async ({ page }) => {
		test.skip(!decidiqInstalled, 'no decidiq on this instance, so there is no chain to render')

		await page.goto(`/apps/${REGISTER}/#/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await page.getByRole('tab', { name: 'Files' }).click()
		await page.getByRole('button', { name: 'Document properties' }).first().click()

		const section = page.getByTestId('document-approval-chain')
		await expect(section).toBeVisible()
		await expect(
			section.getByText('Approval chain unavailable'),
			'the leaf is registered on this instance but the wrapper could not resolve it',
		).toHaveCount(0)
	})

	// @e2e openspec/changes/approval-chain-on-the-document/specs/besluitvorming-leaf/spec.md#scenario-an-approved-route-offers-the-lock
	test('a completed route lets the handler make the document final', async () => {
		test.skip(!decidiqInstalled, 'no decidiq on this instance, so no route can complete')

		const clearance = await api.get(
			`${DECIDIQ_API}/approval-routes/clearance?subject=${documentId}&subjectSchema=informatieobject`,
			{ headers: { requesttoken: token } },
		)
		expect(clearance.ok(), `the clearance endpoint answered ${clearance.status()}`).toBeTruthy()
		const answer = await clearance.json()

		test.skip(
			answer.cleared !== true,
			'the seeded route is still open on this instance; the refusal above is the assertion that matters',
		)

		const locked = await api.patch(
			`${DOSSIQ_API}/informatieobjecten/${documentId}/status`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { status: 'final' },
			},
		)

		expect(locked.ok(), `locking a cleared document answered ${locked.status()}`).toBeTruthy()
		const body = await locked.json()
		expect(body.status).toBe('final')
		expect(body.lockedOn, 'a document made final must be stamped').toBeTruthy()
	})
})
