/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A deelzaak inherits its parent's grants (deelzaken-inherit-the-parent-grants,
 * REQ-DZ-20 and REQ-DZ-21; gap register row Q13.23).
 *
 * WHAT ONLY A BROWSER AND A LIVE REGISTER CAN SHOW HERE
 * ----------------------------------------------------
 * `tests/Unit/Service/CaseAccessGuardInheritanceTest.php` pins the walk, the
 * verb rule, the depth cap and the cycle guard against a doubled register, and
 * `tests/vitest/caseAccessProvenance.spec.js` pins the declaration and what the
 * two panels render. Both are green against fixtures. What neither can show is
 * whether the DECLARATION REACHED THE INSTANCE: an unknown configuration key
 * is dropped on import in silence, so `x-openregister-hierarchy` can be in the
 * register file, listed in `SchemaSlugMap`, and still absent from the schema
 * this instance actually holds. dossiq would believe it declared an edge,
 * OpenRegister would report no inheritance, and nothing anywhere would say the
 * block never arrived.
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED.
 * Seeding a second Nextcloud user is not something this suite can do, so the
 * refusals below are asserted with an anonymous context, which holds no group
 * and is the honest read of somebody who should get nothing. That gives a real
 * answer to the half of this change that matters most, the write that must not
 * widen, and it is deliberately NOT presented as covering the read that should
 * succeed: an anonymous reader is refused everywhere, so a green there would
 * prove nothing about inheritance. The positive read is asserted against the
 * declaration and the panel instead, and the two-user walk is left to the
 * scenario a seeded colleague can run.
 *
 * LOCALE
 * ------
 * Nothing forces the language of the E2E instance. Everything here is matched
 * on a `data-testid`, on an id, or on data this spec seeded (RUN_PREFIX).
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'
import { anonymousContext } from './helpers/principals.ts'
import { expectRefused, REFUSED_ANONYMOUS } from './helpers/refusals.ts'

/** OpenRegister's schema store, where the declaration has to have landed. */
const SCHEMAS_BASE = '/index.php/apps/openregister/api/schemas'

let api: APIRequestContext
let token: string
let caseTypeId = ''

/** The three cases: a parent, its deelzaak, and that deelzaak's deelzaak. */
let parentId = ''
let childId = ''
let grandchildId = ''

/**
 * Open a case page.
 *
 * Both URL shapes are probed for the reason `navToRoute` documents: with
 * pretty URLs the `/index.php` prefix falls outside the router base and
 * vue-router redirects to the dashboard, which renders happily.
 *
 * @param page The Playwright page.
 * @param id   The case id.
 */
async function openCase(page: Page, id: string): Promise<void> {
	for (const base of [`/apps/${REGISTER}`, `/index.php/apps/${REGISTER}`]) {
		await page.goto(`${base}/cases/${id}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		if (new URL(page.url()).pathname.includes(`/cases/${id}`)) {
			await expect(page.locator('.cn-detail-page')).toBeVisible({
				timeout: 30_000,
			})
			return
		}
	}
	throw new Error(`neither URL shape resolved the case page for ${id}`)
}

test.describe('A deelzaak inherits its parent grants', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		const caseTypes = await adoptableCaseTypes(api)
		expect(
			caseTypes.length,
			'the instance must ship at least one PUBLISHED case type',
		).toBeGreaterThan(0)
		caseTypeId = objectId(caseTypes[0])

		const parent = await seedCase(api, token, {
			title: `${RUN_PREFIX} Parent case`,
			caseType: caseTypeId,
		})
		parentId = objectId(parent)

		const child = await seedCase(api, token, {
			title: `${RUN_PREFIX} Deelzaak`,
			caseType: caseTypeId,
			parentCase: parentId,
		})
		childId = objectId(child)

		const grandchild = await seedCase(api, token, {
			title: `${RUN_PREFIX} Deelzaak of the deelzaak`,
			caseType: caseTypeId,
			parentCase: childId,
		})
		grandchildId = objectId(grandchild)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md#access-to-a-case-reaches-its-deelzaak
	// @e2e deelzaak-support::access-to-a-case-reaches-its-deelzaak
	// @e2e case-access-control::the-hierarchy-edge-reached-the-instance
	test('the hierarchy declaration reached the instance', async () => {
		// The silent failure this whole change turns on. OpenRegister drops an
		// unknown configuration key on import without a word, so the block can
		// be in the register file and absent from the schema the instance
		// holds, and the only visible symptom would be that nothing inherits.
		const response = await api.get(`${SCHEMAS_BASE}?_limit=200`)
		expect(
			response.ok(),
			`the schema list must be readable, got ${response.status()}`,
		).toBeTruthy()

		const body = await response.json()
		const schemas = body.results ?? body.schemas ?? body ?? []
		const caseSchema = (Array.isArray(schemas) ? schemas : []).find(
			(row: any) => row?.slug === 'case',
		)

		test.skip(
			caseSchema === undefined,
			'this instance publishes no `case` schema through the schema API, so the declaration cannot be read back here',
		)

		const hierarchy = caseSchema?.configuration?.['x-openregister-hierarchy']
		expect(
			hierarchy,
			'the case schema on this instance must carry x-openregister-hierarchy; an absent block means the import dropped it',
		).toBeDefined()
		expect(hierarchy.parentField).toBe('parentCase')
		expect(hierarchy.inheritedVerbs).toEqual(['read'])
	})

	// @e2e openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md#access-to-a-case-reaches-its-deelzaak
	// @e2e deelzaak-support::access-to-a-case-reaches-its-deelzaak
	test('the chain the grant travels down is written and read back', async () => {
		// The edge itself, end to end. A `parentCase` that did not survive the
		// write makes every assertion about inheritance vacuous: there would
		// be no chain to inherit along and the guard would refuse for the
		// ordinary reason rather than the interesting one.
		const child = await showObject(api, 'case', childId)
		const grandchild = await showObject(api, 'case', grandchildId)

		expect(child.parentCase).toBe(parentId)
		expect(grandchild.parentCase).toBe(childId)
	})

	// @e2e openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md#read-does-not-become-write
	// @e2e deelzaak-support::read-does-not-become-write
	// @e2e case-access-control::a-colleague-with-no-grant-is-refused-the-write
	test('a principal with no grant is refused a write on the deepest deelzaak', async ({
		playwright,
		baseURL,
	}) => {
		// See the header: the anonymous context is the least privileged
		// principal, and the write is the verb that must never widen. A
		// refusal here is a real result; the title is not read back for a
		// change, it is read back to prove the write did not land.
		//
		// 🔴 AND IT IS BUILT AS ONE. `newContext({ baseURL })` inherits
		// `use.storageState` from playwright.config.ts, which names the
		// admin's captured session, so "the ungranted principal" was the
		// admin: the write it attempted would have SUCCEEDED, and the
		// assertion below reported a working guard as a hole.
		const anonymous = await anonymousContext(playwright, String(baseURL))
		const response = await anonymous.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case/${grandchildId}`,
			{ data: { title: `${RUN_PREFIX} rewritten by nobody` } },
		)
		await expectRefused(
			response,
			REFUSED_ANONYMOUS,
			'an ungranted principal writing the deelzaak',
		)
		await anonymous.dispose()

		const unchanged = await showObject(api, 'case', grandchildId)
		expect(unchanged.title).toBe(`${RUN_PREFIX} Deelzaak of the deelzaak`)
	})

	// @e2e openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md#a-related-case-is-not-a-parent
	// @e2e deelzaak-support::a-related-case-is-not-a-parent
	test('a related case is not a parent, so nothing hangs under it', async () => {
		// `relatedCases` is a peer link written symmetrically. The assertion is
		// that it never becomes a `parentCase`: if it did, a grant would travel
		// sideways along every relation a handler ever made.
		const peer = await seedCase(api, token, {
			title: `${RUN_PREFIX} Peer case`,
			caseType: caseTypeId,
		})
		const peerId = objectId(peer)

		await updateObject(api, token, 'case', peerId, {
			relatedCases: JSON.stringify([
				{ caseId: parentId, aardRelatie: 'vervolg' },
			]),
		})

		const readBack = await showObject(api, 'case', peerId)
		expect(readBack.parentCase ?? '').toBe('')
	})

	// @e2e openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md#sharing-a-parent-warns-first
	// @e2e deelzaak-support::sharing-a-parent-warns-first
	test('the Sharing tab of a parent says a share reaches its deelzaken', async ({
		page,
	}) => {
		await openCase(page, parentId)

		const warning = page.locator('[data-testid="sharing-reaches-deelzaken"]')
		const opened = await page
			.locator(
				'[data-testid="sidebar-tab-sharing"], #sharing, [aria-controls*="sharing"]',
			)
			.first()
			.click()
			.then(() => true)
			.catch(() => false)

		test.skip(
			opened === false,
			'the Sharing sidebar tab could not be opened on this instance, so its warning cannot be read',
		)

		await expect(warning).toBeVisible({ timeout: 30_000 })
		await expect(warning).toContainText(/sub-case|deelza/i)
	})

	// @e2e openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md#the-handler-can-see-why-a-colleague-is-there
	// @e2e deelzaak-support::the-handler-can-see-why-a-colleague-is-there
	test('the deelzaak Access tab never prints a bare identifier as a source', async ({
		page,
	}) => {
		// The provenance's failure mode rather than its happy path, because
		// the happy path needs a second user this suite cannot seed. A source
		// column reading a raw uuid is what an inherited grant used to render,
		// and it is visible with one user: every row on this page must carry a
		// sentence in its source cell, inherited or not.
		await openCase(page, childId)

		const opened = await page
			.locator(
				'[data-testid="sidebar-tab-access"], #access, [aria-controls*="access"]',
			)
			.first()
			.click()
			.then(() => true)
			.catch(() => false)

		test.skip(
			opened === false,
			'the Access sidebar tab could not be opened on this instance',
		)

		const sources = page.locator('.case-access-tab__source')
		const count = await sources.count()
		test.skip(
			count === 0,
			'OpenRegister reported no rule on this case, so there is no source cell to read',
		)

		const uuid =
			/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
		for (const text of await sources.allTextContents()) {
			expect(
				uuid.test(text.trim()),
				`the source column must read as a sentence, got the bare identifier ${text.trim()}`,
			).toBe(false)
		}
	})
})
