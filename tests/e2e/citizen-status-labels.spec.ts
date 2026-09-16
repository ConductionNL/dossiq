/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the applicant reads on the public status page (citizen-status-labels,
 * REQ-CT-25).
 *
 * WHAT ONLY A BROWSER CAN SHOW HERE
 * ---------------------------------
 * `StatusPublicLabelsTest` and `tests/vitest/statusPublicLabel.spec.js` pin the
 * rule: the label falls back to the name, the description falls back to
 * nothing. Both are green on a row handed to them by hand. What neither can
 * show is that the row ever REACHES the page.
 *
 * It did not. `PublicStatusPage` printed `obj.status`, and `obj.status` is the
 * statusType's uuid: OpenRegister's `case-tokens` endpoint renders the object
 * with `_extend: []` and the page has no session to fetch the statusType with.
 * A citizen who followed a "track your case" link read 32 hex characters where
 * the status should be, and every unit test in the repository was green while
 * they did. So the chain below is the point of this file: a label typed on a
 * status type, materialised onto a case by OpenRegister, projected through a
 * public token, and read off the page as rendered text.
 *
 * 🔴 WHAT THIS FILE DOES NOT PROVE, SO NOBODY READS IT AS IF IT DID
 * -----------------------------------------------------------------
 * It does NOT prove a citizen can open this page. The API half is anonymous:
 * OpenRegister's `case-tokens` resolve is `#[PublicPage]`. The PAGE half is
 * not. Dossiq's SPA shell is served by the AppHost catch-all
 * (`dashboard#catchAll`), which carries `#[NoAdminRequired]` and no
 * `#[PublicPage]`, so a visitor with no Nextcloud account is bounced to the
 * login screen before any of this runs. `src/manifest.json` calls it a lib gap
 * on the `PublicStatus` page, and it is owned by the AppHost route table in
 * openregister, not by this change.
 *
 * So each test below drives the page WITH a session, and what it proves is the
 * chain: a label typed on a status type, materialised onto a case by
 * OpenRegister, projected through a real minted token, and rendered as text.
 * The day the shell goes public, the storage state is the only line that
 * changes. Writing it as an anonymous context today would have made a test
 * that fails for the shell rather than for anything it is about.
 *
 * WHY THE CASE IS ASSERTED TOO
 * ----------------------------
 * `statusPublicLabel` is a MATERIALISED calculation, resolved in OpenRegister's
 * save-time listener. If it does not materialise, the page shows its
 * "In progress" placeholder, which is also what it shows for a case the token
 * cannot reach. Reading the case through the API first separates the two, so a
 * failure here names which half broke instead of saying the page is empty.
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
import { navToRoute } from './helpers/nav.ts'

/** Dossiq's share endpoint, which mints through OpenRegister's shares leaf. */
const SHARES_BASE = '/index.php/apps/dossiq/api/shares'

/**
 * `src/views/public/PublicStatusPage.vue`, addressed by a real minted token.
 *
 * Built here rather than taken from `helpers/page-components.ts`, whose
 * `PublicStatusPage` deliberately carries a token that cannot resolve: that
 * constant exists to pin the error branch, and this file is about the branch
 * where the token DOES resolve. The prefix is left to `navToRoute`, which
 * probes both `/apps/dossiq` and `/index.php/apps/dossiq`: hard-coding either
 * makes the router's catch-all redirect to the Dashboard on the other kind of
 * instance, and the Dashboard renders happily.
 *
 * @param minted The opaque public token.
 * @return The app-relative route.
 */
function publicStatusRoute(minted: string): string {
	return `/public/status/${minted}`
}

let api: APIRequestContext
let token = ''

/** The status that says something to the applicant, and the one that does not. */
const LABEL = 'We beoordelen uw aanvraag'
const DESCRIPTION = 'U hoeft nu niets te doen. U hoort binnen twee weken.'

const seeded = {
	caseType: '',
	labelled: '',
	unlabelled: '',
	labelledName: `${RUN_PREFIX} Toets register B`,
	unlabelledName: `${RUN_PREFIX} Ontvangen`,
}

const cases: Record<string, string> = {}
const tokens: Record<string, string> = {}

/**
 * The minted share rows, so the teardown revokes them.
 *
 * A token outlives the case it addresses: `cleanupRunObjects` removes the
 * objects and the token row stays behind in OpenRegister's table, resolving to
 * a uniform 404 forever. Harmless to a reader and still residue this suite
 * made, so it takes it back out.
 */
const shares: Array<{ shareId: string; caseId: string }> = []

/**
 * The headers a write needs.
 *
 * @return The headers.
 */
function writeHeaders(): Record<string, string> {
	return {
		requesttoken: token,
		'Content-Type': 'application/json',
		'OCS-APIRequest': 'true',
	}
}

/**
 * Mint a public "track your case" token for one case.
 *
 * Through dossiq's own endpoint rather than the leaf's, because that is the
 * path the Share dialog takes and therefore the one a citizen's link comes
 * from.
 *
 * @param caseId The case to mint for.
 * @return The opaque token.
 */
async function mintToken(caseId: string): Promise<string> {
	const res = await api.post(SHARES_BASE, {
		headers: writeHeaders(),
		data: { caseId, shareType: 'token', label: `${RUN_PREFIX} volg uw zaak` },
	})
	expect(res.ok(), `mint -> ${res.status()} ${await res.text()}`).toBeTruthy()
	const body = await res.json()
	const minted = String(body?.share?.token ?? '')
	expect(
		minted,
		`the mint came back without a token: ${JSON.stringify(body)}`,
	).not.toBe('')
	const shareId = String(body?.share?.id ?? '')
	if (shareId !== '') {
		shares.push({ shareId, caseId })
	}
	return minted
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
				description: 'Behandelaar toetst register B op eerdere bezwaren.',
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

		tokens.labelled = await mintToken(cases.labelled)
		tokens.unlabelled = await mintToken(cases.unlabelled)
	})

	test.afterAll(async () => {
		test.setTimeout(180_000)
		// Tokens first: revoking one needs the case it addresses to still be
		// readable, because the controller checks the caller may access it.
		// `caseId` is REQUIRED, not decoration: the controller reads it to tell
		// a leaf-minted token from a partner handover, and without it this
		// falls into the partner branch, finds no share and revokes nothing
		// while answering something that reads like success.
		for (const { shareId, caseId } of shares) {
			await api
				.delete(`${SHARES_BASE}/${shareId}?caseId=${caseId}`, {
					headers: writeHeaders(),
				})
				.catch(() => undefined)
		}
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e case-types::the-applicant-reads-the-public-label
	test('the applicant reads the public label, not the internal name', async ({
		page,
	}) => {
		// The case carries what the status declared, resolved by OpenRegister's
		// save-time listener. Asserted first so a page that shows nothing below
		// cannot be blamed on the wrong half.
		const stored = await showObject(api, 'case', cases.labelled)
		expect(
			String(stored.statusPublicLabel),
			'the case should carry the label the statusType declared',
		).toBe(LABEL)
		expect(String(stored.statusPublicDescription)).toBe(DESCRIPTION)

		await navToRoute(page, publicStatusRoute(tokens.labelled))

		const status = page.getByTestId('public-status-value')
		await expect(status).toBeVisible({ timeout: 60_000 })
		await expect(status).toHaveText(LABEL)
		await expect(page.getByTestId('public-status-description')).toHaveText(
			DESCRIPTION,
		)

		// AND neither the internal name nor the internal description is on the
		// page. The last one is the regression this file exists for: the page
		// used to print `obj.status`, which is the statusType's uuid, and a
		// citizen read 32 hex characters where the status should be.
		await expect(page.locator('body')).not.toContainText(seeded.labelledName)
		await expect(page.locator('body')).not.toContainText(
			'Behandelaar toetst register B',
		)
		await expect(page.locator('body')).not.toContainText(seeded.labelled)
	})

	// @e2e case-types::no-label-no-change
	test('a status with no public label shows its name, and no description', async ({
		page,
	}) => {
		const stored = await showObject(api, 'case', cases.unlabelled)
		expect(
			String(stored.statusPublicLabel),
			'the fallback to the name resolves server-side, on the case',
		).toBe(seeded.unlabelledName)

		await navToRoute(page, publicStatusRoute(tokens.unlabelled))

		const status = page.getByTestId('public-status-value')
		await expect(status).toBeVisible({ timeout: 60_000 })
		await expect(status).toHaveText(seeded.unlabelledName)

		// 🔴 AND NOTHING WHERE THE DESCRIPTION WOULD GO. The internal
		// description is written for a colleague. A fallback there would
		// publish it, and the page would look entirely correct doing it.
		await expect(page.getByTestId('public-status-description')).toHaveCount(
			0,
		)
		await expect(page.locator('body')).not.toContainText(
			'Interne omschrijving',
		)
	})
})
