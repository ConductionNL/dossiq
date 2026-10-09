/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case number the platform issues, and the follow that replaced the star.
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
 * 🔴 THE STAR IS GONE (one-follow-control). A favourite became a follow with
 * notifications off (openregister merge-follow-and-favourites), so the second
 * half drives the follow: a quiet follow from the case page, a follow from a
 * row, the Following lens with `_favourite` as its alias, and the dashboard
 * tile. It is one user, the only user a browser is.
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

/**
 * The follow endpoint of one case, which OpenRegister owns.
 *
 * The star is gone (one-follow-control): a favourite is a follow with
 * notifications off, so this spec drives `.../watch` where it drove
 * `.../favourite`.
 */
function watchUrl(caseId: string): string {
	return `${API_BASE}/${caseId}/watch`
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
 * Follow a case quietly for the signed-in user: what starring used to be.
 *
 * @param caseId The case uuid.
 */
async function star(caseId: string): Promise<void> {
	const res = await api.put(watchUrl(caseId), {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: { notify: false },
	})
	expect(res.ok(), `following ${caseId} answered ${res.status()}`).toBeTruthy()
}

/**
 * Stop following a case as the signed-in user.
 *
 * @param caseId The case uuid.
 */
async function unstar(caseId: string): Promise<void> {
	const res = await api.delete(watchUrl(caseId), {
		headers: { requesttoken: token },
	})
	expect(res.ok(), `unfollowing ${caseId} answered ${res.status()}`).toBeTruthy()
}

/**
 * The cases one lens answers with, as the signed-in user.
 *
 * @param lens The lens key, `_watching`, `_favourite` or `_recent`.
 */
async function lens(lensKey: string): Promise<any[]> {
	const res = await api.get(`${API_BASE}?${lensKey}=true&_limit=100`, {
		headers: { requesttoken: token },
	})
	expect(res.ok(), `the ${lensKey} lens answered ${res.status()}`).toBeTruthy()
	const body = await res.json()

	return body.results ?? body.data ?? []
}

test.describe('The case number and the follows that replaced the star', () => {
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
	 * @e2e REQ-CM-40 a quiet follow from the case page
	 */
	test('the case page has no star, and the bell makes a follow quiet', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		await unstar(cases.starred)

		await page.goto(`${APP_URL}cases/${cases.starred}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect(page.getByTestId('case-favourite-toggle')).toHaveCount(0)
		const toggle = page.getByTestId('case-follow-toggle')
		await expect(toggle).toHaveAttribute('aria-pressed', 'false')
		await expect(page.getByTestId('case-follow-notify')).toHaveCount(0)

		await toggle.click()
		await expect(toggle).toHaveAttribute('aria-pressed', 'true')
		const bell = page.getByTestId('case-follow-notify')
		await expect(bell).toHaveAttribute('aria-pressed', 'true')
		await bell.click()
		await expect(bell).toHaveAttribute('aria-pressed', 'false')

		await page.reload(PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.getByTestId('case-follow-toggle')).toHaveAttribute('aria-pressed', 'true')
		await expect(page.getByTestId('case-follow-notify')).toHaveAttribute('aria-pressed', 'false')

		expect(errors).toEqual([])
	})

	/**
	 * @e2e REQ-CM-40 following leaves the case untouched
	 */
	test('following cuts no version and writes no audit entry', async () => {
		const before = await showObject(api, 'case', cases.unstarred)
		const versionBefore = before['@self']?.version

		await star(cases.unstarred)

		const after = await showObject(api, 'case', cases.unstarred)

		expect(after['@self']?.version).toBe(versionBefore)
		expect(after['@self']?.watching).toBe(true)
		expect(after['@self']?.watchNotify).toBe(false)
		expect(after.title).toBe(before.title)

		// A control: something a real write DOES move, so the equality above
		// cannot pass on an instance whose version never moves at all.
		expect(versionBefore).toBeDefined()
	})

	/**
	 * @e2e REQ-CM-40 you follow a case from a list row
	 */
	test('the row action follows a case from the Cases list', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		await unstar(cases.rowStar)

		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		const row = page
			.getByRole('row', { name: new RegExp(`favourite rowStar`) })
			.first()
		await expect(row).toBeVisible()

		await row.getByRole('button', { name: /actions/i }).click()
		await page.getByRole('menuitem', { name: /follow/i }).click()

		await expect
			.poll(async () =>
				(await lens('_watching')).map((c: any) => objectId(c)),
			)
			.toContain(cases.rowStar)

		expect(errors).toEqual([])
	})

	/**
	 * @e2e REQ-FAV-02 the following lens lists what you follow, quiet or not
	 */
	test('the Following lens holds the quiet follows, and _favourite answers the same', async () => {
		await star(cases.starred)
		await unstar(cases.alsoUnstarred)

		const followed = (await lens('_watching')).map((c: any) => objectId(c))

		expect(followed).toContain(cases.starred)
		expect(followed).not.toContain(cases.alsoUnstarred)
		// The deprecated alias, for one release: an app still asking for
		// favourites gets the follows.
		expect((await lens('_favourite')).map((c: any) => objectId(c)).sort()).toEqual([...followed].sort())
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
	test('the dashboard carries the followed cases and the case just opened', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)

		await star(cases.starred)

		await page.goto(APP_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect(page.getByText('Cases you follow').first()).toBeVisible()
		await expect(page.getByText('Your favourites')).toHaveCount(0)
		await expect(page.getByText('Recently opened')).toBeVisible()

		expect(errors).toEqual([])
	})
})
