/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-dossier-shared-with-the-requester, task 6.1: the three scenarios the
 * change marks e2e.
 *
 * The requester's half (the row action on `vragenAanU`, "Bestanden
 * toevoegen", the result link on the case card) is rendered by portaliq and
 * asserted in portaliq's own suite (woo-dossier-in-my-cases). What only the
 * real dossiq stack can show is what lands: the answer portaliq forwards is
 * written on the open request with the term still paused and the handler told;
 * the term note is on the case row the portal reads; the result link appears
 * once the decision is really published.
 *
 * THE ANSWER IS SENT AS PORTALIQ SENDS IT: a POST to
 * `/api/portal/vragen/antwoord` with a signed `X-Portal-Subject` assertion.
 * The assertion is signed with portaliq's own `jwt_signing_secret`, read
 * through occ, so the test needs portaliq installed and skips without it.
 *
 * THE CASES USE THE SHIPPED WOO CASE TYPE (`woo-verzoek`): a clarification is
 * refused on any other type. The test skips when the instance has none.
 *
 * THE PUBLISH NEEDS OPENCATALOGI, as in woo-publish-from-the-case: the result
 * link test skips when the publish answers `opencatalogi_unavailable`.
 *
 * Not run in a build lane (decision 139): the live pass runs it, against the
 * board portaliq/ZaakWooVerzoek.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createHmac } from 'crypto'
import {
	cleanupRunObjects,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedDocument,
	showObject,
} from './helpers/fixtures.ts'
import { occRun } from './helpers/occ.ts'

/** The dossiq API base. */
const API = `/index.php/apps/${REGISTER}/api`

/** The Woo case type's shared identifier (WooRequestIntakeInterface). */
const WOO_CASE_TYPE_IDENTIFIER = 'woo-verzoek'

/** The resident's pseudonymous portal reference on the seeded cases. */
const RESIDENT = `${RUN_PREFIX}-verzoeker`

/** The uid Playwright signs in as; the handler the notification goes to. */
const ADMIN_USER = 'admin'

/** The question and the answer of the change's scenario. */
const QUESTION = 'Over welke speeltuinen en welke jaren gaat uw verzoek?'
const ANSWER = 'Het gaat om de speeltuinen in Oosthaven, 2023 tot nu'

/** The paused-term sentence, with the day the request went out in words. */
const PAUSED_NOTE =
	/^De termijn staat stil sinds \d{1,2} (januari|februari|maart|april|mei|juni|juli|augustus|september|oktober|november|december) \d{4}\. Hij loopt weer zodra wij uw antwoord hebben\.$/

let wooCaseType = ''

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

/**
 * Base64url without padding.
 *
 * @param bytes What to encode.
 * @return The encoding.
 */
function b64url(bytes: Buffer | string): string {
	return Buffer.from(bytes).toString('base64url')
}

/**
 * The assertion portaliq signs for a resident, in the shape
 * PortalAssertionVerifier accepts: HS256, `use: assertion`, `iss: portaliq`.
 *
 * @param secret  portaliq's signing secret.
 * @param subject The resident's subject reference.
 * @return The JWT.
 */
function assertionFor(secret: string, subject: string): string {
	const now = Math.floor(Date.now() / 1000)
	const head = b64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' }))
	const body = b64url(
		JSON.stringify({
			iss: 'portaliq',
			use: 'assertion',
			sub: subject,
			audience: 'citizen',
			iat: now,
			exp: now + 120,
		}),
	)
	const signature = b64url(
		createHmac('sha256', secret).update(`${head}.${body}`).digest(),
	)

	return `${head}.${body}.${signature}`
}

/**
 * portaliq's signing secret, or an empty string when portaliq is not there.
 *
 * @return The secret.
 */
async function portaliqSecret(): Promise<string> {
	const { code, output } = await occRun([
		'config:app:get',
		'portaliq',
		'jwt_signing_secret',
	])

	return code === 0 ? output.trim() : ''
}

/**
 * Seed a Woo case of the resident, assigned to the signed-in handler.
 *
 * @param api   Authenticated request context.
 * @param token The CSRF request token.
 * @param title The case title suffix.
 * @return The case id.
 */
async function seedWooCase(
	api: APIRequestContext,
	token: string,
	title: string,
): Promise<string> {
	return objectId(
		await seedCase(api, token, {
			title: `${RUN_PREFIX} Woo-verzoek ${title}`,
			caseType: wooCaseType,
			intakeChannel: 'website',
			startDate: new Date().toISOString().slice(0, 10),
			assignee: ADMIN_USER,
			portalSubject: RESIDENT,
		}),
	)
}

/**
 * Ask the clarification question from the desk, as the handler's screen does.
 *
 * @param api    Authenticated request context.
 * @param token  The CSRF request token.
 * @param caseId The case.
 * @return The open request.
 */
async function askClarification(
	api: APIRequestContext,
	token: string,
	caseId: string,
): Promise<any> {
	const res = await api.post(`${API}/cases/${caseId}/information-request`, {
		headers: headers(token),
		data: { kind: 'verduidelijking', question: QUESTION, durationDays: 14 },
	})
	expect(res.status(), await res.text()).toBe(200)
	const body = await res.json()
	expect(body.suspended).toBe(true)

	return body.request
}

/**
 * The timeline entries of one kind on the case.
 *
 * @param api    Authenticated request context.
 * @param caseId The case.
 * @param kind   The timeline kind.
 * @return The entries.
 */
async function entriesOfKind(
	api: APIRequestContext,
	caseId: string,
	kind: string,
): Promise<any[]> {
	const res = await api.get(
		`/index.php/apps/openregister/api/objects/${REGISTER}/case/${caseId}/timeline`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(res.ok()).toBeTruthy()
	const rows = ((await res.json()).results ?? []) as any[]

	return rows.filter((row: any) => String(row?.kind ?? '') === kind)
}

/**
 * The signed-in user's notifications about one case.
 *
 * @param api    Authenticated request context.
 * @param caseId The case.
 * @return The notifications.
 */
async function notificationsFor(
	api: APIRequestContext,
	caseId: string,
): Promise<any[]> {
	const res = await api.get(
		'/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	if (res.ok() === false) {
		return []
	}
	const rows = ((await res.json())?.ocs?.data ?? []) as any[]

	return rows.filter(
		(row: any) =>
			row?.app === REGISTER &&
			row?.object_type === 'case' &&
			row?.object_id === caseId,
	)
}

test.describe('The Woo dossier shared with the requester', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const types = await listObjects(api, 'caseType', {
			identifier: WOO_CASE_TYPE_IDENTIFIER,
		})
		const published = types.filter((row: any) => row?.isDraft !== true)
		wooCaseType = published.length > 0 ? objectId(published[0]) : ''
		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		await cleanupRunObjects(api, await getRequestToken(api))
		await api.dispose()
	})

	// @e2e openspec/specs/portal-contribution/spec.md#scenario-the-requester-answers-the-clarification-question
	test('the requester answers the clarification question', async ({
		request,
	}) => {
		test.skip(wooCaseType === '', 'No published Woo case type on this instance')
		const secret = await portaliqSecret()
		test.skip(secret === '', 'portaliq is not installed or has no signing secret')

		const token = await getRequestToken(request)
		const caseId = await seedWooCase(request, token, 'verduidelijking')
		const asked = await askClarification(request, token, caseId)
		const requestId = objectId(asked)
		expect(asked.kind).toBe('verduidelijking')

		const before = (await entriesOfKind(request, caseId, 'reactie-indiener'))
			.length
		const noticesBefore = (await notificationsFor(request, caseId)).length

		// Exactly what portaliq forwards for the row action `beantwoordVraag`.
		const answered = await request.post(`${API}/portal/vragen/antwoord`, {
			headers: {
				'X-Portal-Subject': assertionFor(secret, RESIDENT),
				'Content-Type': 'application/json',
			},
			data: { requestId, antwoord: ANSWER },
		})
		expect(answered.status(), await answered.text()).toBe(200)
		expect((await answered.json()).state).toBe('open')

		const stored = await showObject(request, 'aanvullingsverzoek', requestId)
		expect(stored.applicantAnswer).toBe(ANSWER)
		expect(stored.state).toBe('open')

		await expect
			.poll(
				async () =>
					(await entriesOfKind(request, caseId, 'reactie-indiener')).length,
				{ timeout: 30_000 },
			)
			.toBe(before + 1)
		await expect
			.poll(async () => (await notificationsFor(request, caseId)).length, {
				timeout: 30_000,
			})
			.toBe(noticesBefore + 1)

		const theCase = await showObject(request, 'case', caseId)
		expect(theCase.waitingOnApplicant).toBe(true)
		expect(String(theCase.termNote ?? '')).toMatch(PAUSED_NOTE)
	})

	// @e2e openspec/specs/portal-contribution/spec.md#scenario-a-paused-term-says-why-and-gives-no-date
	test('a paused term says why', async ({ request }) => {
		test.skip(wooCaseType === '', 'No published Woo case type on this instance')

		const token = await getRequestToken(request)
		const caseId = await seedWooCase(request, token, 'termijn')
		await askClarification(request, token, caseId)

		// The ask saves the case; the note and the empty date are written there.
		await expect
			.poll(
				async () => String((await showObject(request, 'case', caseId)).termNote ?? ''),
				{ timeout: 30_000 },
			)
			.toMatch(PAUSED_NOTE)
		const theCase = await showObject(request, 'case', caseId)
		expect(theCase.legalDecisionDate ?? '').toBe('')
		expect(theCase.waitingOnApplicant).toBe(true)
	})

	// @e2e openspec/specs/portal-contribution/spec.md#scenario-the-link-appears-after-publishing
	test('the link appears after publishing', async ({ request }) => {
		test.skip(wooCaseType === '', 'No published Woo case type on this instance')

		const token = await getRequestToken(request)
		const caseId = await seedWooCase(request, token, 'publicatie')

		// One public document and an assembled decision, through the routes
		// the handler's screens use (as woo-publish-from-the-case does).
		const document = await seedDocument(request, token, {
			title: `${RUN_PREFIX} Woo besluit bijlage`,
			case: caseId,
		})
		const assessed = await request.post(`${API}/cases/${caseId}/woo/assessment`, {
			headers: headers(token),
			data: {
				assessments: [
					{ documentRef: objectId(document), classification: 'openbaar' },
				],
			},
		})
		expect(assessed.ok(), `assess -> ${assessed.status()} ${await assessed.text()}`).toBeTruthy()
		const decision = await request.post(`${API}/cases/${caseId}/woo/decision`, {
			headers: headers(token),
			data: {},
		})
		expect(decision.ok(), `assemble -> ${decision.status()} ${await decision.text()}`).toBeTruthy()

		const before = await showObject(request, 'case', caseId)
		expect(before.resultLink ?? null).toBeNull()

		const res = await request.post(`${API}/cases/${caseId}/woo/publish`, {
			headers: headers(token),
			data: {},
		})
		const body = await res.json()
		test.skip(
			body?.reason === 'opencatalogi_unavailable',
			'OpenCatalogi is not installed on this instance',
		)
		expect(res.ok(), `publish -> ${res.status()} ${JSON.stringify(body)}`).toBeTruthy()

		const published = await showObject(request, 'case', caseId)
		expect(published.wooPublicationStatus).toBe('published')
		expect(published.resultLink).toEqual({
			label: 'Bekijk wat openbaar is gemaakt',
			url: published.wooPublicationUrl,
		})
	})
})
