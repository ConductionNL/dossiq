/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-CAL-01, REQ-CAL-03 and REQ-CAL-04: a case share is an OpenRegister
 * access link, an external advisory body answers through that link's comment
 * capability, and the Sharing tab names the state of every link on the case.
 *
 * WHY THESE ARE E2E AND NOT ONLY UNIT TESTS. Every assertion below crosses the
 * seam between dossiq and OpenRegister, and the seam is where this feature
 * fails silently. An OpenRegister that predates #3817 has no AccessLinkService
 * at all, and dossiq's gateway answers null for it: the Sharing tab then
 * renders, renders no links, and offers a Share by link button that reports a
 * polite error. That is indistinguishable, in a unit test, from a case nobody
 * has shared yet. Only a real mint against a real OpenRegister tells the two
 * apart, which is why the first test asserts a URL comes back and that the URL
 * answers to somebody with no session.
 *
 * WHAT THIS SUITE DELIBERATELY DOES NOT ASSERT. It never re-derives what a
 * holder may read. That projection is OpenRegister's (AccessLinkReader), and a
 * second evaluator written here would eventually disagree with the app and be
 * "fixed" in whichever direction was easier. What it asserts is the property
 * dossiq owns: the body that comes back carries no `@self` key outside the
 * published set, whatever OpenRegister decided to put in it.
 *
 * WHAT IT NEEDS FROM THE INSTANCE. OpenRegister at or past #3817, and the
 * dossiq register imported. The anonymous half uses a second request context
 * with no session and no request token, because an authenticated read of the
 * public route would pass even if the link granted nothing at all.
 *
 * NOT RUN IN THIS LANE. Written and tagged here; the nightly Playwright matrix
 * runs it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'

/** dossiq's own share surface. */
const SHARES = '/index.php/apps/dossiq/api/shares'

/** OpenRegister's holder-facing link surface, as #3817 published it. */
const PUBLIC_LINKS = '/index.php/apps/openregister/api/public/links'

/** The `@self` keys OpenRegister publishes through a link. */
const PUBLISHED_SELF_KEYS = [
	'id',
	'uuid',
	'name',
	'description',
	'summary',
	'register',
	'schema',
	'published',
	'depublished',
	'created',
	'updated',
]

/**
 * The anchor out of a link URL, which is its last path segment.
 *
 * @param url the address the handler is given.
 * @return the anchor.
 */
function anchorOf(url: string): string {
	return url.split('/').filter((part) => part !== '').pop() ?? ''
}

test.describe('A case share is an access link', () => {
	test.setTimeout(180_000)

	let api: APIRequestContext
	let anonymous: APIRequestContext
	let token = ''
	let caseId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		anonymous = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} shared by link`,
			caseType: caseType.id,
		})
		caseId = objectId(seeded)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
		await anonymous.dispose()
	})

	// @e2e case-share-via-shares-leaf::a-handler-shares-a-case-with-an-outsider
	//
	// The load-bearing pair is the mint and the anonymous read. A mint that
	// returns a URL nobody can open is the failure an authenticated assertion
	// cannot see, because the handler's own session would open the case anyway.
	test('a handler shares a case with an outsider, and the outsider can open it without an account', async () => {
		const minted = await api.post(SHARES, {
			headers: { requesttoken: token },
			data: {
				caseId,
				shareType: 'link',
				label: `${RUN_PREFIX} brandweer`,
				capabilities: ['read', 'comment'],
				expiresAt: '2030-01-01',
			},
		})

		expect(minted.status(), await minted.text()).toBe(200)
		const body = await minted.json()
		expect(body.success).toBe(true)
		expect(body.url, 'a share with no address is a share nobody can use').toBeTruthy()
		expect(body.share.capabilities).toBe('read,comment')
		expect(body.share.token, 'dossiq mints no token of its own').toBeUndefined()

		const held = await anonymous.get(`${PUBLIC_LINKS}/${anchorOf(body.url)}`)
		expect(held.status(), await held.text()).toBe(200)

		const served = await held.json()
		expect(served.subject, 'a link that serves no subject serves nothing').toBeTruthy()
		expect(served.link.capabilities).toContain('comment')
	})

	// @e2e case-share-via-shares-leaf::the-preview-carries-no-case-internals
	//
	// Asserted over the keys that came back rather than over a fixed list, so
	// a key OpenRegister starts publishing tomorrow fails this rather than
	// slipping past an assertion that only looked for four known names.
	test('what the holder reads carries no case internals', async () => {
		const minted = await api.post(SHARES, {
			headers: { requesttoken: token },
			data: {
				caseId,
				shareType: 'link',
				label: `${RUN_PREFIX} preview`,
				capabilities: ['read'],
				expiresAt: '2030-01-01',
			},
		})
		const body = await minted.json()

		const preview = await api.get(
			`${SHARES}/${body.share.accessLinkId}/preview?caseId=${encodeURIComponent(caseId)}`,
			{ headers: { requesttoken: token } },
		)

		expect(preview.status(), await preview.text()).toBe(200)
		const self = (await preview.json()).preview.subject['@self'] ?? {}

		for (const key of Object.keys(self)) {
			expect(PUBLISHED_SELF_KEYS, `@self carried ${key} to a holder`).toContain(key)
		}
	})

	// @e2e case-share-via-shares-leaf::the-handler-sees-the-state-of-every-link
	//
	// A link switched off must read as switched off rather than disappearing:
	// a handler who cannot see a paused link cannot switch it back on.
	test('the handler sees the state of every link on the case', async () => {
		const minted = await api.post(SHARES, {
			headers: { requesttoken: token },
			data: {
				caseId,
				shareType: 'link',
				label: `${RUN_PREFIX} paused`,
				capabilities: ['read'],
				expiresAt: '2030-01-01',
			},
		})
		const linkId = (await minted.json()).share.accessLinkId

		const paused = await api.put(`${SHARES}/${linkId}`, {
			headers: { requesttoken: token },
			data: { caseId, disabled: true },
		})
		expect(paused.status(), await paused.text()).toBe(200)

		const listed = await api.get(
			`${SHARES}/case/${encodeURIComponent(caseId)}`,
			{ headers: { requesttoken: token } },
		)
		const rows = (await listed.json()).results
		const row = rows.find((entry: { accessLinkId: number }) => entry.accessLinkId === linkId)

		expect(row, 'a paused link must still be listed').toBeTruthy()
		expect(row.state).toBe('paused')
	})

	// @e2e case-share-via-shares-leaf::an-advisory-body-answers-without-an-account
	//
	// The refusal is what makes the grant mean anything: a link declaring only
	// reading must be refused when it tries to comment, or "commenting works"
	// is a statement about the endpoint and not about the capability.
	test('a link that does not declare commenting cannot comment', async () => {
		const minted = await api.post(SHARES, {
			headers: { requesttoken: token },
			data: {
				caseId,
				shareType: 'link',
				label: `${RUN_PREFIX} read only`,
				capabilities: ['read'],
				expiresAt: '2030-01-01',
			},
		})
		const anchor = anchorOf((await minted.json()).url)

		const refused = await anonymous.post(`${PUBLIC_LINKS}/${anchor}/comments`, {
			data: { message: `${RUN_PREFIX} should not land` },
		})

		expect(refused.status(), await refused.text()).toBe(403)
	})
})
