/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-PC-10: every entry dossiq writes on the case feed defaults to internal,
 * what leaves the counter is public, and somebody outside the organisation
 * sees only the public ones.
 *
 * WHICH SURFACE, AND WHY THIS ONE. Since #2888 a case share mints an
 * OpenRegister access link, and nothing mints a case token any more. So the
 * anonymous reader a real outsider reaches is `GET /api/public/links/{anchor}`,
 * and that is the one this file reads. The case-token resolve still exists in
 * OpenRegister and is covered by its own unit suite; no outsider reaches it.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT SUITE. The unit suites prove that
 * dossiq hands the seam `internal` or `public`, and that the readers ask for
 * the public ones. Neither can prove the three things this file is for.
 *
 *  - THE SERVER ENFORCES THE FILTER ON A REQUEST WITH NO SESSION. Whether an
 *    internal entry crosses that boundary is decided by a server-side
 *    predicate on a request no unit test in this repository ever makes.
 *  - THE PAYLOAD CARRIES A TIMELINE AT ALL. An OpenRegister older than the
 *    public-timeline reader answers the same 200 with no entries or with notes
 *    only, and every absence assertion below would pass on it for the wrong
 *    reason. So the first assertion is that the key IS a list, and the second
 *    test needs a kinded record to be on it.
 *  - NOBODY'S NAME LEAVES. The access-link reader used to hand out each public
 *    note with its author's user id and display name. That renders perfectly,
 *    so it is asserted key by key.
 *
 * TWO CONTEXTS, ON PURPOSE. `api` signs in and writes; `anonymous` never signs
 * in, so it carries no cookie and no request token, which is what an
 * outsider's browser does. Both are built with the base URL, because a
 * context without one cannot resolve the relative paths below.
 *
 * WHAT THIS SUITE DOES NOT DO. It seeds its own case under RUN_PREFIX and
 * writes entries only on the case it created. An entry is a record of a
 * communication, and one left on somebody's real case is residue this
 * repository has been bitten by before.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { anonymousContext } from './helpers/principals.ts'

/** OpenRegister's own API, which owns the timeline. */
const OR = '/index.php/apps/openregister/api'

/** dossiq's share surface, where a link is minted. */
const SHARES = '/index.php/apps/dossiq/api/shares'

/** OpenRegister's holder-facing link surface. */
const PUBLIC_LINKS = `${OR}/public/links`

/** The case schema, as the timeline routes address it. */
const SCHEMA = 'case'

/** The only keys a public entry may carry. */
const PUBLIC_ENTRY_KEYS = ['fields', 'id', 'kind', 'message', 'occurredAt']

/** The day the beschikking went. */
const DELIVERED_ON = '2026-05-04T09:12:00+02:00'

/**
 * The anchor out of a link URL, which is its last path segment.
 *
 * @param url The address the handler is given.
 *
 * @return The anchor.
 */
function anchorOf(url: string): string {
	return (
		url
			.split('/')
			.filter((part) => part !== '')
			.pop() ?? ''
	)
}

test.describe('REQ-PC-10 an outsider sees the public entries and no others', () => {
	test.setTimeout(180_000)

	let api: APIRequestContext
	let anonymous: APIRequestContext
	let token = ''
	let caseId = ''
	let anchor = ''

	/**
	 * Write one entry on the seeded case.
	 *
	 * @param body The entry payload.
	 *
	 * @return The raw response.
	 */
	async function writeEntry(body: Record<string, unknown>) {
		return api.post(`${OR}/objects/${REGISTER}/${SCHEMA}/${caseId}/timeline`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: body,
		})
	}

	/**
	 * What the outsider is served through the link.
	 *
	 * @return The parsed payload.
	 */
	async function servedToTheOutsider(): Promise<any> {
		const held = await anonymous.get(`${PUBLIC_LINKS}/${anchor}`)
		expect(held.status(), await held.text()).toBe(200)

		return held.json()
	}

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		// 🔴 THE OUTSIDER HAS TO BE ONE. The file header says `anonymous`
		// never signs in, but the call was identical to the line above, which
		// inherits `use.storageState` from playwright.config.ts and is the
		// admin's captured session. So "the outsider sees only the public
		// entries" was the admin reading a case they may read in full, and
		// every `not.toContain` below was asserted over a body served to
		// somebody entitled to all of it.
		anonymous = await anonymousContext(playwright, String(baseURL))
		token = await getRequestToken(api)

		const caseType = await ensureCaseType(api, token)
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} visibility`,
			caseType: caseType.id,
		})
		caseId = objectId(seeded)

		// One note the handler kept to themselves, one they published, and one
		// delivery that left the counter. All written before any read, so the
		// tests below assert against the same feed rather than racing.
		const internal = await writeEntry({
			message: `${RUN_PREFIX} interne notitie over de aanvrager`,
			visibility: 'internal',
		})
		expect(internal.status(), await internal.text()).toBe(201)

		const published = await writeEntry({
			message: `${RUN_PREFIX} we hebben uw stukken ontvangen`,
			visibility: 'public',
		})
		expect(published.status(), await published.text()).toBe(201)

		const delivered = await writeEntry({
			kind: 'beschikking-verzonden',
			message: `${RUN_PREFIX} Beschikking verzonden`,
			visibility: 'public',
			fields: { channel: 'berichtenbox', sentOn: DELIVERED_ON },
		})
		expect(delivered.status(), await delivered.text()).toBe(201)

		const minted = await api.post(SHARES, {
			headers: { requesttoken: token },
			data: {
				caseId,
				shareType: 'link',
				label: `${RUN_PREFIX} aanvrager`,
				capabilities: ['read'],
				expiresAt: '2030-01-01',
			},
		})
		expect(minted.status(), await minted.text()).toBe(200)
		anchor = anchorOf(String((await minted.json()).url ?? ''))
		expect(anchor, 'the share answered an address').not.toBe('')
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
		await anonymous.dispose()
	})

	test('a note written without the box ticked is not served through the link', async () => {
		const served = await servedToTheOutsider()

		// THE CONTROL. Without this the absence below passes on an instance
		// whose OpenRegister serves no timeline at all.
		expect(
			Array.isArray(served.timeline),
			'the link serves a timeline; an instance without one fails here rather than passing vacuously',
		).toBe(true)

		const messages = (served.timeline as any[]).map((entry) =>
			String(entry?.message ?? ''),
		)

		expect(messages.join(' | ')).toContain('we hebben uw stukken ontvangen')
		expect(messages.join(' | ')).not.toContain('interne notitie')
	})

	test('a delivered beschikking is listed with its date', async () => {
		const served = await servedToTheOutsider()
		const delivered = (served.timeline as any[]).filter(
			(entry) => String(entry?.kind ?? '') === 'beschikking-verzonden',
		)

		// A kinded entry exists only as a timeline record. A reader that read
		// notes alone, as the link reader once did, lists nothing here.
		expect(delivered).toHaveLength(1)
		expect(String(delivered[0].message)).toContain('Beschikking verzonden')
		expect(String(delivered[0].occurredAt ?? '')).not.toBe('')
		expect(delivered[0].fields?.sentOn).toBe(DELIVERED_ON)
	})

	test('no entry tells the outsider who wrote it', async () => {
		const served = await servedToTheOutsider()

		expect((served.timeline as any[]).length).toBeGreaterThan(0)
		for (const entry of served.timeline as any[]) {
			expect(
				Object.keys(entry).sort(),
				'only the whitelisted keys leave the building',
			).toEqual(PUBLIC_ENTRY_KEYS)
			expect(entry).not.toHaveProperty('actorId')
			expect(entry).not.toHaveProperty('actorDisplayName')
			expect(entry).not.toHaveProperty('author')
		}
	})

	test('a signed-in handler still reads both, which is what makes the filter a filter', async () => {
		const response = await api.get(
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
