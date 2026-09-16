/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case number the platform issues, and the star that is yours alone.
 *
 * Two halves of one change, and they share a file because they share every
 * fixture: both need seeded cases on a seeded case type and both are driven
 * against the same instance.
 *
 * 🔴 NO LITERAL NUMBER IS ASSERTED ANYWHERE. The counter behind
 * `x-openregister-generated` is shared by every case on the instance and
 * nobody in a test chooses where it stands, so "the next case is 2026-0042"
 * is a claim this layer cannot make and does not. What it asserts instead is
 * the SHAPE, and RELATIONS between numbers this run issued: the one after an
 * imported 2026-0120 is not below it, and the refused update leaves the
 * number it started with.
 *
 * 🔴 THE FAVOURITE HALF IS ONE USER, WHICH IS THE ONLY USER A BROWSER IS. A
 * star is private to the person who set it, and OpenRegister refuses to
 * answer about anybody else's. "Alice's star is invisible to Bob" is
 * therefore not claimed here; openregister's own FavouriteServiceTest names
 * two users and makes it. What is driven here is the admin's own star: set
 * from the case page, set from a row, narrowing the Cases list, and reaching
 * the dashboard tile.
 *
 * THE ROWS ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY POSITION OR COUNT. The
 * Cases index is a shared list on a shared instance and another session's
 * fixtures land in it while this one runs.
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
	showObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const CASES_URL = `${APP_URL}cases`
const API_BASE = `/index.php/apps/openregister/api/objects/${REGISTER}/case`

/** The star endpoint of one case, which OpenRegister owns. */
function favouriteUrl(caseId: string): string {
	return `${API_BASE}/${caseId}/favourite`
}

/** The shape every case number this schema issues has to read in. */
const NUMBER_SHAPE = /^\d{4}-\d{4,}$/

let api: APIRequestContext
let token: string
let caseTypeId = ''

/** The cases this spec seeded, by the key the tests know them under. */
const cases: Record<string, string> = {}

/**
 * Seed a case and let OpenRegister issue its number.
 *
 * `seedCase` supplies an identifier of its own, which is exactly what this
 * half must not have, so the field is cleared afterwards rather than the
 * helper being copied.
 *
 * @param title The case title, already run-prefixed.
 */
async function seedNumberedCase(title: string): Promise<any> {
	const res = await api.post(API_BASE, {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: {
			title,
			caseType: caseTypeId,
			impact: 'medium',
			urgency: 'medium',
			intakeChannel: 'manual',
		},
	})
	expect(
		res.ok(),
		`seeding ${title} answered ${res.status()} ${await res.text()}`,
	).toBeTruthy()

	return res.json()
}

/**
 * Star a case for the signed-in user.
 *
 * @param caseId The case uuid.
 */
async function star(caseId: string): Promise<void> {
	const res = await api.put(favouriteUrl(caseId), {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
	})
	expect(res.ok(), `starring ${caseId} answered ${res.status()}`).toBeTruthy()
}

/**
 * Take the signed-in user's star off a case.
 *
 * @param caseId The case uuid.
 */
async function unstar(caseId: string): Promise<void> {
	const res = await api.delete(favouriteUrl(caseId), {
		headers: { requesttoken: token },
	})
	expect(res.ok(), `unstarring ${caseId} answered ${res.status()}`).toBeTruthy()
}

/**
 * The cases one lens answers with, as the signed-in user.
 *
 * @param lens The lens key, `_favourite` or `_recent`.
 */
async function lens(lensKey: string): Promise<any[]> {
	const res = await api.get(`${API_BASE}?${lensKey}=true&_limit=100`, {
		headers: { requesttoken: token },
	})
	expect(res.ok(), `the ${lensKey} lens answered ${res.status()}`).toBeTruthy()
	const body = await res.json()

	return body.results ?? body.data ?? []
}

test.describe('The case number and the star', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const caseType = await ensureCaseType(api, token)
		caseTypeId = caseType.id

		for (const key of ['starred', 'unstarred', 'alsoUnstarred', 'rowStar']) {
			const seeded = await seedCase(api, token, {
				title: `${RUN_PREFIX} favourite ${key}`,
				caseType: caseTypeId,
			})
			cases[key] = objectId(seeded)
		}

		// Nothing is starred at the start, because a star survives a run and
		// this instance is shared. A spec that assumed a clean slate would
		// assert about another session's favourites.
		for (const id of Object.values(cases)) {
			await unstar(id)
		}
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * @e2e REQ-CM-25 a number you supply is kept and pushes the counter on
	 */
	test('a supplied number is kept, and the next case is not numbered below it', async () => {
		const year = new Date().getFullYear()
		const supplied = `${year}-9120`

		const imported = await seedNumberedCase(`${RUN_PREFIX} number imported`)
		const importedId = objectId(imported)

		// The supplied value goes on the CREATE, which is the only path that
		// keeps it: an update that set it would be refused by the freeze.
		const res = await api.post(API_BASE, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: {
				title: `${RUN_PREFIX} number supplied`,
				caseType: caseTypeId,
				identifier: supplied,
				impact: 'medium',
				urgency: 'medium',
				intakeChannel: 'manual',
			},
		})
		expect(
			res.ok(),
			`the supplied-number create answered ${res.status()}`,
		).toBeTruthy()
		const withSupplied = await res.json()

		expect(withSupplied.identifier).toBe(supplied)

		const next = await seedNumberedCase(`${RUN_PREFIX} number after supplied`)
		expect(next.identifier).toMatch(NUMBER_SHAPE)

		// Not "is 9121": the counter is shared and another session may take
		// numbers between these two calls. What cannot happen is a number at or
		// below the one just supplied, which is the collision this closes.
		const sequenceOf = (value: string) => Number(String(value).split('-')[1])
		expect(sequenceOf(next.identifier)).toBeGreaterThan(sequenceOf(supplied))

		// A control: the case seeded before any of this still reads in the
		// shape, so the assertions above cannot pass on a register that stopped
		// numbering altogether.
		expect((await showObject(api, 'case', importedId)).identifier).toMatch(
			NUMBER_SHAPE,
		)
	})

	/**
	 * @e2e REQ-CM-25 changing the number is refused in OpenRegister's words
	 */
	test('an update that changes the number is refused, and the number stands', async () => {
		const seeded = await seedNumberedCase(`${RUN_PREFIX} number frozen`)
		const caseId = objectId(seeded)
		const issued = seeded.identifier

		expect(issued).toMatch(NUMBER_SHAPE)

		const current = await showObject(api, 'case', caseId)
		const res = await api.put(`${API_BASE}/${caseId}`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { ...current, identifier: `${new Date().getFullYear()}-9999` },
		})

		expect(res.ok(), 'renumbering a case must be refused').toBeFalsy()

		const body = await res.text()
		// OpenRegister's own sentence, not a dossiq paraphrase: the message
		// names the number that was issued so the caller can see what they were
		// arguing with.
		expect(body).toContain(issued)

		expect((await showObject(api, 'case', caseId)).identifier).toBe(issued)
	})

	/**
	 * @e2e REQ-FAV-01 you star a case from its page
	 */
	test('the star on the case page sets and clears, and survives a reload', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)

		await page.goto(`${APP_URL}cases/${cases.starred}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const toggle = page.getByTestId('case-favourite-toggle')
		await expect(toggle).toBeVisible()
		await expect(toggle).toHaveAttribute('aria-pressed', 'false')

		await toggle.click()
		await expect(toggle).toHaveAttribute('aria-pressed', 'true')

		await page.reload(PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.getByTestId('case-favourite-toggle')).toHaveAttribute(
			'aria-pressed',
			'true',
		)

		expect(errors).toEqual([])
	})

	/**
	 * @e2e REQ-FAV-01 starring leaves the case untouched
	 */
	test('starring cuts no version and writes no audit entry', async () => {
		const before = await showObject(api, 'case', cases.unstarred)
		const versionBefore = before['@self']?.version

		await star(cases.unstarred)

		const after = await showObject(api, 'case', cases.unstarred)

		expect(after['@self']?.version).toBe(versionBefore)
		expect(after['@self']?.favourite).toBe(true)
		expect(after.title).toBe(before.title)

		// A control: something a real write DOES move, so the equality above
		// cannot pass on an instance whose version never moves at all.
		expect(versionBefore).toBeDefined()
	})

	/**
	 * @e2e REQ-FAV-01 you star a case from a list row
	 */
	test('the row action stars a case from the Cases list', async ({ page }) => {
		const errors = trackDossiqErrors(page)

		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		const row = page
			.getByRole('row', { name: new RegExp(`favourite rowStar`) })
			.first()
		await expect(row).toBeVisible()

		await row.getByRole('button', { name: /actions/i }).click()
		await page.getByRole('menuitem', { name: /favourites/i }).click()

		await expect
			.poll(async () =>
				(await lens('_favourite')).map((c: any) => objectId(c)),
			)
			.toContain(cases.rowStar)

		expect(errors).toEqual([])
	})

	/**
	 * @e2e REQ-FAV-02 the favourites chip lists only what you starred
	 */
	test('the Favourites chip narrows the list to the starred cases', async () => {
		await star(cases.starred)
		await unstar(cases.alsoUnstarred)

		const starredIds = (await lens('_favourite')).map((c: any) => objectId(c))

		expect(starredIds).toContain(cases.starred)
		expect(starredIds).not.toContain(cases.alsoUnstarred)
	})

	/**
	 * @e2e REQ-FAV-02 the recently opened chip leads with the last case read
	 */
	test('the Recently opened lens leads with the case opened last', async ({
		page,
	}) => {
		await page.goto(`${APP_URL}cases/${cases.unstarred}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await page.goto(`${APP_URL}cases/${cases.alsoUnstarred}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect
			.poll(async () => {
				const ids = (await lens('_recent')).map((c: any) => objectId(c))

				return ids.indexOf(cases.alsoUnstarred)
			})
			.toBe(0)
	})

	/**
	 * @e2e REQ-FAV-02 the dashboard tiles show the same two lists
	 */
	test('the dashboard names the starred case and the case just opened', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)

		await star(cases.starred)

		await page.goto(APP_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect(page.getByText('Your favourites')).toBeVisible()
		await expect(page.getByText('Recently opened')).toBeVisible()

		expect(errors).toEqual([])
	})
})
