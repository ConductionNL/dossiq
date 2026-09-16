/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-PC-10: every entry dossiq writes on the case feed defaults to internal,
 * what leaves the counter is public, and the applicant sees only the public
 * ones.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT SUITE. The unit suites prove that
 * dossiq hands the seam `internal` or `public`, and that the readers ask for
 * the public ones. Neither can prove the two things this file is for.
 *
 *  - THE SERVER ENFORCES THE FILTER ON A REQUEST WITH NO SESSION. The public
 *    status page resolves an opaque token anonymously. Whether an internal
 *    entry crosses that boundary is decided by a server-side predicate on a
 *    request no unit test in this repository ever makes.
 *  - THE PUBLIC PAYLOAD CARRIES A TIMELINE AT ALL. An OpenRegister that
 *    predates the public-timeline key answers the same 200 with the same
 *    object and no entries, and every absence assertion below would pass on
 *    it for the wrong reason. So the first assertion of the first test is
 *    that the key IS a list. Without it this file is a test that cannot fail.
 *
 * WHICH DOOR. The entries are written through OpenRegister's own timeline API,
 * which is the same door the Timeline tab uses. The public read goes through
 * the anonymous resolve endpoint with NO request token and NO cookies, which
 * is what a citizen's browser does.
 *
 * WHAT THIS SUITE DOES NOT DO. It seeds its own case under RUN_PREFIX and
 * writes entries only on the case it created. An entry is a record of a
 * communication, and one left on somebody's real case is residue this
 * repository has been bitten by before.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'

/** OpenRegister's own API, which owns the timeline and the public token. */
const OR = '/index.php/apps/openregister/api'

/** The case schema, as the timeline and share routes address it. */
const SCHEMA = 'case'

/** The case every test in this file hangs its entries on. */
let caseId = ''

/** The CSRF token for every write below. */
let token = ''

/** The opaque public token minted for that case. */
let publicToken = ''

/** The day the beschikking went, as the public page should print it. */
const DELIVERED_ON = '2026-05-04T09:12:00+02:00'

/**
 * Write one entry on the seeded case.
 *
 * @param api  The request context.
 * @param body The entry payload.
 *
 * @return The raw response, so a test may assert on a refusal.
 */
async function writeEntry(api: APIRequestContext, body: Record<string, unknown>) {
	return api.post(`${OR}/objects/${REGISTER}/${SCHEMA}/${caseId}/timeline`, {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: body,
	})
}

/**
 * Resolve the public token the way a citizen's browser does: no request
 * token, no cookies, no session. A context of our own is the only way to be
 * sure of that; the shared `request` fixture carries the admin's cookies.
 *
 * @return The parsed resolve payload.
 */
async function readPublicView(): Promise<any> {
	const anonymous = await playwrightRequest.newContext()
	try {
		const response = await anonymous.get(
			`${OR}/public/case-tokens/${encodeURIComponent(publicToken)}`,
		)
		expect(
			response.ok(),
			`the public resolve answered ${response.status()}`,
		).toBeTruthy()

		return response.json()
	} finally {
		await anonymous.dispose()
	}
}

test.beforeAll(async ({ request }) => {
	token = await getRequestToken(request)
	const caseTypeId = (await ensureCaseType(request, token)).id

	const seeded = await seedCase(request, token, {
		title: `${RUN_PREFIX} visibility`,
		caseType: caseTypeId,
	})
	caseId = objectId(seeded)

	// One note the handler kept to themselves, and one delivery that left the
	// counter. Both are written before any read, so the two tests below assert
	// against the same feed rather than racing each other.
	const internal = await writeEntry(request, {
		message: `${RUN_PREFIX} interne notitie over de aanvrager`,
		visibility: 'internal',
	})
	expect(internal.status()).toBe(201)

	const delivered = await writeEntry(request, {
		kind: 'beschikking-verzonden',
		message: `${RUN_PREFIX} Beschikking verzonden`,
		visibility: 'public',
		fields: { channel: 'berichtenbox', sentOn: DELIVERED_ON },
	})
	expect(delivered.status()).toBe(201)

	const minted = await request.post(
		`${OR}/objects/${REGISTER}/${SCHEMA}/${caseId}/integrations/shares`,
		{
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { type: 'public-token', label: `${RUN_PREFIX} track your case` },
		},
	)
	expect(
		minted.ok(),
		`minting a public token answered ${minted.status()}`,
	).toBeTruthy()
	publicToken = String((await minted.json()).token ?? '')
	expect(publicToken, 'the mint answered a token').not.toBe('')
})

test.afterAll(async ({ request }) => {
	await cleanupRunObjects(request, token)
})

test.describe('REQ-PC-10 the applicant sees the public entries and no others', () => {
	test('a note written without the box ticked is not on the public page', async () => {
		const body = await readPublicView()

		// THE CONTROL. Without this the absence below passes on an instance
		// whose OpenRegister has no public timeline at all, which is the
		// failure this whole file exists to catch.
		expect(
			Array.isArray(body.timeline),
			'the public resolve carries a timeline; an instance without one fails here rather than passing vacuously',
		).toBe(true)

		const messages = (body.timeline as any[]).map((entry) =>
			String(entry?.message ?? ''),
		)

		expect(messages.join(' | ')).not.toContain('interne notitie')
	})

	test('a delivered beschikking is listed with its date', async () => {
		const body = await readPublicView()
		const delivered = (body.timeline as any[]).filter(
			(entry) => String(entry?.kind ?? '') === 'beschikking-verzonden',
		)

		expect(delivered).toHaveLength(1)
		expect(String(delivered[0].message)).toContain('Beschikking verzonden')
		expect(String(delivered[0].occurredAt ?? '')).not.toBe('')
		expect(delivered[0].fields?.sentOn).toBe(DELIVERED_ON)
	})

	test('the public projection hands out no author and no visibility flag', async () => {
		const body = await readPublicView()

		for (const entry of body.timeline as any[]) {
			expect(
				Object.keys(entry).sort(),
				'only the whitelisted keys leave the building',
			).toEqual(['fields', 'id', 'kind', 'message', 'occurredAt'])
		}
	})

	test('a signed-in handler still reads both, which is what makes the filter a filter', async ({
		request,
	}) => {
		const response = await request.get(
			`${OR}/objects/${REGISTER}/${SCHEMA}/${caseId}/timeline`,
		)
		expect(response.ok()).toBeTruthy()

		const messages = ((await response.json()).results ?? []).map((row: any) =>
			String(row?.message ?? ''),
		)

		expect(messages.join(' | ')).toContain('interne notitie')
		expect(messages.join(' | ')).toContain('Beschikking verzonden')
	})
})
