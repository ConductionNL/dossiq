/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the applicant reads for a status (citizen-status-labels, REQ-CT-25).
 *
 * WHAT ONLY A LIVE INSTANCE CAN SHOW HERE
 * ---------------------------------------
 * `StatusPublicLabelsTest` and `tests/vitest/statusPublicLabel.spec.js` pin the
 * rule: the label falls back to the name, the description falls back to
 * nothing. Both are green on a row handed to them by hand. Neither can show
 * that the words ever REACH the person they were written for.
 *
 * They travel on the CASE, not behind it. A status is a uuid on the case, and
 * every citizen-facing surface is handed the case alone: the public status page
 * resolves through an endpoint that renders with `_extend: []`, and an
 * OpenRegister access link projects the case for its holder. So the label is a
 * MATERIALISED calculation over `@ref.statusType`, resolved in OpenRegister's
 * save-time listener (ADR-031). If that listener does not run it, the surfaces
 * show a blank where the status should be and every unit test stays green.
 * That is the seam these tests cross.
 *
 * 🔴 WHAT THIS FILE DOES NOT DO, SO NOBODY READS IT AS IF IT DID
 * --------------------------------------------------------------
 * It does not drive `src/views/public/PublicStatusPage.vue` in a browser.
 * Since #2888 a case share mints an OpenRegister ACCESS LINK, and dossiq mints
 * no case token of its own any more (`case-sharing-mints-access-links` asserts
 * `share.token` is undefined). The page still resolves
 * `/apps/openregister/api/public/case-tokens/{token}`, so nothing a handler can
 * do produces a token that page will open. Reading its label off the case is
 * covered by the vitest spec; pointing the page at the link surface is
 * #2888's follow-through, not this change, and a test written today would have
 * had to mint a token no surface hands out.
 *
 * What the anonymous half below DOES prove is the same question asked of the
 * surface that is live: a holder with no account reads the words the author
 * wrote, and never the ones written for a colleague.
 *
 * NOT RUN IN THIS LANE. Written and tagged here; the nightly Playwright matrix
 * runs it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { anonymousContext } from './helpers/principals.ts'

/** dossiq's own share surface, where a link is minted. */
const SHARES = '/index.php/apps/dossiq/api/shares'

/** Everything done to a link that already exists. */
const LINKS = '/index.php/apps/dossiq/api/access-links'

/** OpenRegister's holder-facing link surface, as #3817 published it. */
const PUBLIC_LINKS = '/index.php/apps/openregister/api/public/links'

/** What the author wrote for the applicant. */
const LABEL = 'We beoordelen uw aanvraag'
const DESCRIPTION = 'U hoeft nu niets te doen. U hoort binnen twee weken.'

/** What the same status says to a handler, and must never say to anyone else. */
const INTERNAL_DESCRIPTION = 'Behandelaar toetst register B op eerdere bezwaren.'

let api: APIRequestContext
let anonymous: APIRequestContext
let token = ''

const seeded = {
	caseType: '',
	labelled: '',
	unlabelled: '',
	labelledName: `${RUN_PREFIX} Toets register B`,
	unlabelledName: `${RUN_PREFIX} Ontvangen`,
}

const cases: Record<string, string> = {}
const links: Array<{ linkId: string; caseId: string }> = []

/**
 * The anchor out of a link URL, which is its last path segment.
 *
 * @param url The address the handler is given.
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

/**
 * Mint a share link for one case and return what a holder would open.
 *
 * @param caseId The case to publish.
 * @return The link's anchor.
 */
async function mintLink(caseId: string): Promise<string> {
	const minted = await api.post(SHARES, {
		headers: { requesttoken: token },
		data: {
			caseId,
			shareType: 'link',
			label: `${RUN_PREFIX} volg uw zaak`,
			capabilities: ['read'],
		},
	})
	expect(minted.status(), await minted.text()).toBe(200)

	const body = await minted.json()
	expect(
		body.url,
		'a share with no address is a share nobody can use',
	).toBeTruthy()

	const linkId = String(body?.share?.accessLinkId ?? '')
	if (linkId !== '') {
		links.push({ linkId, caseId })
	}
	return anchorOf(body.url)
}

test.describe('A status says to the applicant what the author wrote for them', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		test.setTimeout(180_000)
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		// No session and no request token. An authenticated read of the public
		// route would pass even if the link granted nothing at all.
		//
		// 🔴 THAT COMMENT WAS TRUE OF THE INTENT AND FALSE OF THE CODE. The
		// call was `newContext({ baseURL })`, which inherits `use.storageState`
		// from playwright.config.ts, and that names the admin's captured
		// session. So every "the applicant reads" assertion below was the
		// admin reading, and the two `expect(body).not.toContain(...)` lines
		// were checked against a body served to somebody entitled to all of
		// it. `anonymousContext` builds what the comment describes.
		anonymous = await anonymousContext(playwright, String(baseURL))
		token = await getRequestToken(api)

		// PUBLISHED: `case.caseType` filters on `isDraft: false`.
		seeded.caseType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Publieke status`,
				identifier: `${RUN_PREFIX.toLowerCase()}-csl`,
				description: 'Throwaway caseType for citizen-status-labels.',
				isDraft: false,
			}),
		)

		// The one that declares both, and carries an internal description the
		// applicant must never see.
		seeded.labelled = objectId(
			await createObject(api, token, 'statusType', {
				name: seeded.labelledName,
				caseType: seeded.caseType,
				order: 1,
				isFinal: false,
				description: INTERNAL_DESCRIPTION,
				publicLabel: LABEL,
				publicDescription: DESCRIPTION,
			}),
		)

		// The one that declares neither. Its name is what the applicant reads.
		seeded.unlabelled = objectId(
			await createObject(api, token, 'statusType', {
				name: seeded.unlabelledName,
				caseType: seeded.caseType,
				order: 2,
				isFinal: false,
				description: 'Interne omschrijving die de aanvrager nooit ziet.',
			}),
		)

		await updateObject(api, token, 'caseType', seeded.caseType, {
			initialStatus: seeded.labelled,
		})

		cases.labelled = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Dakkapel`,
				caseType: seeded.caseType,
				status: seeded.labelled,
			}),
		)
		cases.unlabelled = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Uitrit`,
				caseType: seeded.caseType,
				status: seeded.unlabelled,
			}),
		)
	})

	test.afterAll(async () => {
		test.setTimeout(180_000)
		// The links first: revoking one needs the case it addresses to still
		// be readable, and the sweep below is about to remove the cases.
		for (const { linkId, caseId } of links) {
			await api
				.delete(`${LINKS}/${linkId}?caseId=${encodeURIComponent(caseId)}`, {
					headers: { requesttoken: token },
				})
				.catch(() => undefined)
		}
		await cleanupRunObjects(api, token)
		await anonymous.dispose()
		await api.dispose()
	})

	// @e2e case-types::the-applicant-reads-the-public-label
	//
	// The load-bearing pair is the materialisation and the anonymous read. A
	// label that materialises but is not published reads as a blank status to
	// a citizen, and a handler's own session would show the case either way.
	test('the applicant reads the public label, never the internal name', async () => {
		const stored = await showObject(api, 'case', cases.labelled)
		expect(
			String(stored.statusPublicLabel),
			'the case should carry the label the statusType declared',
		).toBe(LABEL)
		expect(String(stored.statusPublicDescription)).toBe(DESCRIPTION)

		const anchor = await mintLink(cases.labelled)
		const held = await anonymous.get(`${PUBLIC_LINKS}/${anchor}`)
		expect(held.status(), await held.text()).toBe(200)

		const served = await held.json()
		expect(
			served.subject,
			'a link that serves no subject serves nothing',
		).toBeTruthy()
		expect(
			String(served.subject.statusPublicLabel),
			'the words the author wrote should travel with the case',
		).toBe(LABEL)
		expect(String(served.subject.statusPublicDescription)).toBe(DESCRIPTION)

		// 🔴 AND NEITHER INTERNAL STRING CROSSES. Asserted over the whole
		// served body, not over the two fields, because the name could arrive
		// under any key OpenRegister decides to publish tomorrow.
		const body = JSON.stringify(served)
		expect(body).not.toContain(seeded.labelledName)
		expect(body).not.toContain(INTERNAL_DESCRIPTION)
	})

	// @e2e case-types::no-label-no-change
	test('a status with no public label reads as its name, and says nothing more', async () => {
		const stored = await showObject(api, 'case', cases.unlabelled)
		expect(
			String(stored.statusPublicLabel),
			'the fallback to the name resolves server-side, on the case',
		).toBe(seeded.unlabelledName)

		const anchor = await mintLink(cases.unlabelled)
		const held = await anonymous.get(`${PUBLIC_LINKS}/${anchor}`)
		expect(held.status(), await held.text()).toBe(200)

		const served = await held.json()
		expect(String(served.subject.statusPublicLabel)).toBe(seeded.unlabelledName)

		// 🔴 AND NOTHING WHERE THE DESCRIPTION WOULD GO. The internal
		// description is written for a colleague. A fallback there would
		// publish it, and every surface would look entirely correct doing it.
		expect(
			String(served.subject.statusPublicDescription ?? ''),
			'no public description means nothing, never the internal one',
		).toBe('')
		expect(JSON.stringify(served)).not.toContain('Interne omschrijving')
	})
})
