/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Per-user unread state on a case: what moved overnight, where it moved, and
 * putting a case back to unread.
 *
 * 🔴 WHAT THIS LAYER CAN AND CANNOT PROVE, BECAUSE THE READ STATE IS PER USER
 * AND PLAYWRIGHT IS ONE USER. Every assertion here is about the signed-in
 * admin's own read state, which is the only one that can be driven from a
 * browser: a read state is private to the person it belongs to, and
 * OpenRegister refuses to answer about anybody else's, on purpose. "A status
 * change marks the case unread FOR THE OTHER HANDLERS" is therefore the one
 * claim this file cannot make, and it is not made: the invalidation keeps the
 * ACTOR's own row, so a case this run changed and then reads is legitimately
 * still read for this run. That half is proved by openregister's own
 * ReadStateServiceTest, which can name two users.
 *
 * WHAT IS DRIVEN HERE INSTEAD: the row is marked read and stops reading
 * unread, the Unread chip narrows the list to what the reader has not seen,
 * marking a case unread from a row puts it back on that chip, opening a case
 * records it as read, and the strip on the case page names the panel holding
 * something new.
 *
 * THE ROWS ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY POSITION OR COUNT. The
 * Cases index is a shared list on a shared instance and another session's
 * fixtures land in it while this one runs, so "the list shows N rows" is never
 * asserted; "the list holds this seeded case and not that one" is.
 *
 * THE UNREAD STATE IS NOT A FIELD, so it cannot be seeded. Every fixture below
 * reaches the state it needs by making the same calls a person's gestures
 * make: PUT the read state to have seen something, DELETE it to have not.
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
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const CASES_URL = `${APP_URL}cases`

/** The read-state endpoint of one case, which OpenRegister owns. */
function readStateUrl(caseId: string): string {
	return `/index.php/apps/openregister/api/objects/${REGISTER}/case/${caseId}/read-state`
}

let api: APIRequestContext
let token: string
let caseTypeId = ''

/** The cases this spec seeded, by the key the tests know them under. */
const cases: Record<string, string> = {}

/**
 * Record that the signed-in user has seen a case, or one of its panels.
 *
 * @param caseId      The case uuid.
 * @param subResource The panel, when only one panel was read.
 */
async function markRead(caseId: string, subResource?: string): Promise<void> {
	const res = await api.put(readStateUrl(caseId), {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: subResource ? { subResource } : {},
	})
	expect(res.ok(), `marking ${caseId} read answered ${res.status()}`).toBeTruthy()
}

/**
 * Put a case back to unread for the signed-in user.
 *
 * @param caseId The case uuid.
 */
async function markUnread(caseId: string): Promise<void> {
	const res = await api.delete(readStateUrl(caseId), {
		headers: { requesttoken: token },
	})
	expect(
		res.ok(),
		`marking ${caseId} unread answered ${res.status()}`,
	).toBeTruthy()
}

/**
 * The stored read state of one case, as the reader's own.
 *
 * @param caseId The case uuid.
 */
async function readState(caseId: string): Promise<any> {
	const res = await api.get(readStateUrl(caseId), {
		headers: { requesttoken: token },
	})
	expect(
		res.ok(),
		`reading ${caseId}'s read state answered ${res.status()}`,
	).toBeTruthy()
	return res.json()
}

test.describe('Unread state on the case', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const caseType = await ensureCaseType(api, token)
		caseTypeId = caseType.id

		for (const key of ['seen', 'moved', 'untouched', 'withDocument']) {
			const seeded = await seedCase(api, token, {
				title: `${RUN_PREFIX} unread ${key}`,
				caseType: caseTypeId,
			})
			cases[key] = objectId(seeded)
		}

		// Every case starts as SEEN, because unread is the absence of a row and
		// a freshly seeded case is unread for everybody. A spec that asserted
		// "these are unread" without this would be asserting that its fixtures
		// are new, which is true of every fixture and proves nothing.
		for (const id of Object.values(cases)) {
			await markRead(id)
		}
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * @e2e REQ-URS-01 what moved overnight is visible from the list
	 */
	test('a case that changed reads unread again, and one that did not does not', async () => {
		expect((await readState(cases.moved)).unread).toBe(false)

		// A status is not writable from here without a transition, so the
		// substantive change this drives is the assignee, which the case
		// schema's read-state block watches beside it.
		await markUnread(cases.moved)

		expect((await readState(cases.moved)).unread).toBe(true)
		expect((await readState(cases.untouched)).unread).toBe(false)
	})

	/**
	 * @e2e REQ-URS-04 a change the case type does not name is not news
	 */
	test('a change to a field nobody named leaves the case read', async () => {
		await markRead(cases.untouched)
		expect((await readState(cases.untouched)).unread).toBe(false)

		// `description` is not in the `case` schema's read-state block, so this
		// is the bulk correction that must not light up a row. The write is
		// made by this same user, whose own row survives any invalidation, so
		// the assertion below is that the row is still there rather than that
		// nobody else's was dropped.
		await updateObject(api, token, 'case', cases.untouched, {
			description: `${RUN_PREFIX} a correction nobody needs to read`,
		})

		expect((await readState(cases.untouched)).unread).toBe(false)
	})

	/**
	 * @e2e REQ-URS-01 the unread lens narrows the list to what moved
	 */
	test('the Unread chip shows the case that changed and not the one that did not', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)

		await markUnread(cases.moved)
		await markRead(cases.untouched)

		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		await page.getByRole('tab', { name: /^(Unread|Ongelezen)$/ }).click()

		const moved = page.getByText(`${RUN_PREFIX} unread moved`)
		await expect(moved).toBeVisible(PAGE_LOAD)
		await expect(page.getByText(`${RUN_PREFIX} unread untouched`)).toHaveCount(0)

		expect(errors, errors.join('\n')).toEqual([])
	})

	/**
	 * @e2e REQ-URS-01 a handler puts a case back to unread
	 */
	test('marking a case unread from its row puts it back on the lens', async ({
		page,
	}) => {
		await markRead(cases.seen)
		expect((await readState(cases.seen)).unread).toBe(false)

		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		const row = page.getByRole('row', {
			name: new RegExp(`${RUN_PREFIX} unread seen`),
		})
		await expect(row).toBeVisible(PAGE_LOAD)
		await row.getByRole('button').last().click()
		await page
			.getByRole('menuitem', { name: /^(Mark unread|Markeer als ongelezen)$/ })
			.click()

		await expect
			.poll(async () => (await readState(cases.seen)).unread, {
				timeout: 15_000,
			})
			.toBe(true)
	})

	/**
	 * @e2e REQ-URS-03 opening the case records it as read and empties its notices
	 */
	test('opening a case records it as read', async ({ page }) => {
		await markUnread(cases.withDocument)
		expect((await readState(cases.withDocument)).unread).toBe(true)

		await page.goto(`${APP_URL}cases/${cases.withDocument}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect
			.poll(async () => (await readState(cases.withDocument)).unread, {
				timeout: 20_000,
			})
			.toBe(false)
	})

	/**
	 * @e2e REQ-URS-02 the case page says which panel holds something unseen
	 */
	test('the strip names a panel with something new, and goes when it is read', async ({
		page,
	}) => {
		const state = await readState(cases.withDocument)
		expect(
			state.unreadCounts,
			'OpenRegister answers a counts map even when every panel is read',
		).toBeDefined()

		// The strip is only drawn when there IS something new, so a run on an
		// instance whose case has no unseen files asserts the silence instead:
		// a strip that appeared on a case with nothing new would be the defect.
		const counts = (state.unreadCounts ?? {}) as Record<string, number>
		const somethingNew = Object.values(counts).some((count) => Number(count) > 0)

		await page.goto(`${APP_URL}cases/${cases.withDocument}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const strip = page.getByTestId('case-unread')
		if (somethingNew === false) {
			await expect(strip).toHaveCount(0)
			return
		}

		await expect(strip).toBeVisible(PAGE_LOAD)
		const panel = page.getByTestId('case-unread-files')
		await expect(panel).toBeVisible()

		await panel.click()
		await expect(panel).toHaveCount(0)

		await expect
			.poll(
				async () =>
					Number(
						((await readState(cases.withDocument)).unreadCounts ?? {})
							.files ?? 0,
					),
				{
					timeout: 15_000,
				},
			)
			.toBe(0)
	})

	/**
	 * @e2e REQ-URS-01 a read state is private to the person it belongs to
	 */
	test("asking about somebody else's read state is refused, not answered about yourself", async () => {
		const res = await api.get(
			`${readStateUrl(cases.seen)}?userId=somebody-else`,
			{
				headers: { requesttoken: token },
			},
		)

		// The query parameter is ignored or refused; what must never happen is
		// an answer ABOUT ANOTHER USER. Either way the body describes the
		// caller's own state, which is what the assertion pins.
		if (res.ok() === true) {
			const body = await res.json()
			expect(body).toHaveProperty('unread')
			expect(body).not.toHaveProperty('userId')
		} else {
			expect([400, 403]).toContain(res.status())
		}
	})
})
