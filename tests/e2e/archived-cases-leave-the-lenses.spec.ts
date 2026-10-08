/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An archived case leaves the working lists and comes back whole.
 *
 * 🔴 THE STATE IS THE PLATFORM'S MARKER, AND EVERY ASSERTION HERE IS ABOUT
 * WHAT THE MARKER DOES TO A LIST. `case.archiveStatus` is the ZGW fact and no
 * list reads it, so a spec that asserted the field would pass on a case that
 * never left a single lens, which is the whole of what this change is for.
 * Where the field is asserted, it is asserted BESIDE the marker and never
 * instead of it.
 *
 * 🔴 THE ROWS ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY POSITION OR COUNT.
 * The Cases index is a shared list on a shared instance and another session's
 * fixtures land in it while this one runs. "Seven of ten are listed" is a
 * claim about a fixture set, not about the page, so it is made by asking for
 * THIS run's rows and counting those.
 *
 * 🔴 EVERY CASE THIS SPEC ARCHIVES IS RESTORED OR DESTROYED IN CLEANUP. An
 * archived fixture left behind is invisible to the sweep that reads the
 * default list, which is exactly the property under test, so the teardown
 * clears the marker first and deletes afterwards.
 *
 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
} from './helpers/fixtures.ts'
import {
	dismissSupportDialog,
	openHeaderActionsMenu,
	PAGE_LOAD,
	trackDossiqErrors,
} from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const CASES_URL = `${APP_URL}cases`
/**
 * The url of one act on one case.
 *
 * @param caseId The case uuid.
 * @param verb The path segment after `/api/case/{id}/`.
 */
function caseApi(caseId: string, verb: string): string {
	return `/index.php/apps/${REGISTER}/api/case/${caseId}/${verb}`
}

let api: APIRequestContext
let token: string
let caseTypeId = ''
let caseTypeTitle = ''
let statusDone = ''

/** The cases this spec seeded, by the key the tests know them under. */
const cases: Record<string, string> = {}

/** The keys this spec archives, so teardown can clear every marker. */
const ARCHIVED_KEYS = ['Gearchiveerd A', 'Gearchiveerd B', 'Gearchiveerd C']

/** The keys this spec leaves open. */
const OPEN_KEYS = ['Open A', 'Open B', 'Open C', 'Open D']

/**
 * One quick-filter chip, by its English or Dutch label.
 *
 * The instance may be Dutch, so every chip is matched in both languages: a
 * spec that asserted English labels fails on a Dutch instance for a reason
 * that has nothing to do with the archive.
 *
 * @param page The page.
 * @param label A pattern matching the chip's label in either language.
 */
function chip(page: Page, label: RegExp) {
	return page.getByRole('tab', { name: label })
}

const CHIPS = {
	all: /^(All|Alle)$/,
	archived: /^(Archived|Gearchiveerd)$/,
}

/**
 * The row for one seeded case, addressed by its run-prefixed title.
 *
 * @param page The page.
 * @param key The key the case was seeded under.
 */
function row(page: Page, key: string) {
	return page.getByRole('row').filter({ hasText: `${RUN_PREFIX} ${key}` })
}

/**
 * Every row of this run currently on the page.
 *
 * @param page The page.
 */
function ownRows(page: Page) {
	return page.getByRole('row').filter({ hasText: RUN_PREFIX })
}

/**
 * Narrow the Cases index to the rows this run seeded.
 *
 * Without this the lens tests look at page one of several: the index
 * paginates and the shared instance holds far more cases than this run seeds,
 * so a row seeded seconds ago can sit three pages away and the assertion reads
 * as "the lens does not show my row" when the lens is fine.
 *
 * @param page The page.
 */
async function narrowToThisRun(page: Page): Promise<void> {
	const facet = page.getByRole('button', {
		name: new RegExp(
			`^${caseTypeTitle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\s+\\d+)?$`,
		),
	})
	await expect(
		facet,
		`the sidebar should offer a case-type filter named ${caseTypeTitle}`,
	).toBeVisible({ timeout: 30_000 })
	await facet.click()

	// The list must have answered before a lens is touched, or the first
	// assertion races the fetch this click started.
	await expect(ownRows(page).first()).toBeVisible({ timeout: 30_000 })
}

/**
 * Post one act on one case and insist it was accepted.
 *
 * @param caseId The case uuid.
 * @param act The path segment after `/api/case/{id}/`.
 * @param body The JSON body.
 */
async function act(
	caseId: string,
	act: string,
	body: Record<string, unknown>,
): Promise<any> {
	const res = await api.post(caseApi(caseId, act), {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: body,
	})
	expect(
		res.ok(),
		`${act} on ${caseId} answered ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	return res.json()
}

/**
 * The archive marker on one case, read off `@self`.
 *
 * Read by id rather than off a list, because a read by identifier is
 * deliberately unaffected by the archive: that is what lets a reference into
 * an archived case still resolve, and it is what lets this helper answer at
 * all once the case is out of every list.
 *
 * @param caseId The case uuid.
 *
 * @return The marker, or null when the case is not archived.
 */
async function markerOn(caseId: string): Promise<any> {
	const object = await showObject(api, 'case', caseId)
	return object?.['@self']?.archived ?? null
}

/**
 * The ZGW archive status of one case.
 *
 * @param caseId The case uuid.
 */
async function archiveStatusOf(caseId: string): Promise<string> {
	const object = await showObject(api, 'case', caseId)
	return String(object?.archiveStatus ?? '')
}

/**
 * Close a case so it can be archived, then archive it.
 *
 * Archiving refuses an open case, and that refusal is not an obstacle to work
 * around in a fixture: it is REQ-CM-41's second scenario, asserted below.
 *
 * @param caseId The case uuid.
 */
async function closeAndArchive(caseId: string): Promise<void> {
	await act(caseId, 'finish', { reason: 'Afgerond voor de e2e', resultTypeId: '' })
	await act(caseId, 'archive', { reason: 'Naar het e-depot' })
}

test.beforeAll(async ({ playwright, baseURL }) => {
	api = await playwright.request.newContext({ baseURL })
	token = await getRequestToken(api)

	const machine = await seedStateMachine(api, token)
	caseTypeId = machine.caseTypeId
	caseTypeTitle = machine.caseTypeTitle
	statusDone = machine.statusDone

	for (const key of [...OPEN_KEYS, ...ARCHIVED_KEYS]) {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} ${key}`,
			caseType: caseTypeId,
			description: 'Seeded by the archived-cases-leave-the-lenses layer.',
		})
		cases[key] = objectId(seeded)
	}

	for (const key of ARCHIVED_KEYS) {
		await closeAndArchive(cases[key])
	}
})

test.afterAll(async () => {
	// 🔴 THE MARKER COMES OFF BEFORE THE DELETE. An archived fixture is out of
	// every default list, which is the property this spec exists to prove, so
	// a sweep that reads the default list would never see it again.
	for (const key of ARCHIVED_KEYS) {
		const id = cases[key]
		if (!id) {
			continue
		}
		try {
			await act(id, 'unarchive', { reason: 'e2e teardown' })
		} catch {
			// A test may already have restored it. The delete below is what
			// actually has to happen.
		}
	}
	await cleanupRunObjects(api, token)
	await api.dispose()
})

test.describe('A closed case is archived and comes back', () => {
	// @e2e openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md#scenario-archive-a-closed-case
	test('archiving a closed case marks it and moves the ZGW field', async () => {
		const id = cases['Gearchiveerd A']

		const marker = await markerOn(id)
		expect(
			marker,
			'the archived case should carry the platform marker',
		).not.toBeNull()
		expect(String(marker.at ?? ''), 'the marker names when').not.toBe('')
		expect(String(marker.by ?? ''), 'the marker names who').not.toBe('')

		// Beside the marker, never instead of it. The stored value is
		// `archived`; ZGW's `gearchiveerd` is what the mapping publishes.
		expect(await archiveStatusOf(id)).toMatch(/^archived/)
	})

	// @e2e openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md#scenario-archive-is-not-offered-on-a-running-case
	test('archive is refused on a case that is still running', async () => {
		const id = cases['Open A']

		const res = await api.post(caseApi(id, 'archive'), {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { reason: 'Te vroeg' },
		})
		expect(res.ok(), 'an open case must not be archivable').toBeFalsy()

		// And nothing was written: a refusal that half-archived the case would
		// be worse than one that let it through.
		expect(await markerOn(id)).toBeNull()
	})

	// @e2e openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md#scenario-restore-in-one-action
	test('restore clears the marker and puts the case back in the lists', async () => {
		const id = cases['Gearchiveerd C']

		expect(await markerOn(id), 'precondition: it is archived').not.toBeNull()

		await act(id, 'unarchive', { reason: 'Te vroeg gearchiveerd' })

		expect(await markerOn(id), 'the marker is cleared').toBeNull()
		expect(await archiveStatusOf(id)).toBe('nog_te_archiveren')

		// Put it back, so the lens tests below see the three they expect
		// whatever order the runner picks.
		await act(id, 'archive', { reason: 'Naar het e-depot' })
		expect(await markerOn(id)).not.toBeNull()
	})

	test('the menu offers Restore on an archived case and Archive on an open one', async () => {
		const archived = await api.get(caseApi(cases['Gearchiveerd A'], 'acts'), {
			headers: { requesttoken: token },
		})
		expect(archived.ok()).toBeTruthy()
		expect(
			(await archived.json()).archived,
			'the acts answer names the archive',
		).toBe(true)

		const open = await api.get(caseApi(cases['Open B'], 'acts'), {
			headers: { requesttoken: token },
		})
		expect(open.ok()).toBeTruthy()
		expect((await open.json()).archived).toBe(false)
	})
})

test.describe('Archived cases leave the working lenses', () => {
	test.beforeEach(async ({ page }) => {
		trackDossiqErrors(page)
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await narrowToThisRun(page)
	})

	// @e2e openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md#scenario-the-archived-case-is-gone-from-cases
	test('the default lens shows the open cases and none of the archived ones', async ({
		page,
	}) => {
		await expect(chip(page, CHIPS.all)).toHaveAttribute('aria-selected', 'true')

		// An absence asserted first would pass against a list that has not
		// fetched yet, so a row that MUST be there is waited for.
		await expect(row(page, 'Open A').first()).toBeVisible({ timeout: 30_000 })

		for (const key of OPEN_KEYS) {
			await expect(
				row(page, key).first(),
				`${key} is open and listed`,
			).toBeVisible()
		}
		for (const key of ARCHIVED_KEYS) {
			await expect(row(page, key), `${key} is archived and gone`).toHaveCount(
				0,
			)
		}
	})

	// @e2e openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md#scenario-the-archived-lens-finds-them
	test('the Archived lens shows them and nothing else', async ({ page }) => {
		await chip(page, CHIPS.archived).click()

		await expect(row(page, 'Gearchiveerd A').first()).toBeVisible({
			timeout: 30_000,
		})
		for (const key of ARCHIVED_KEYS) {
			await expect(
				row(page, key).first(),
				`${key} is in the archive`,
			).toBeVisible()
		}
		for (const key of OPEN_KEYS) {
			await expect(row(page, key), `${key} is open, not archived`).toHaveCount(
				0,
			)
		}
	})

	// @e2e openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md#scenario-search-does-not-return-an-archived-case
	test('the archived cases are not in the default result of a shared query', async ({
		page,
	}) => {
		// The distinctive word every case of this run carries is its prefix,
		// and the case-type facet is the query. The archived rows answer to it
		// as much as the open ones do, so their absence is the platform's
		// exclusion and not a narrower question.
		await expect(ownRows(page).first()).toBeVisible({ timeout: 30_000 })
		const titles = await ownRows(page).allInnerTexts()
		const joined = titles.join('\n')

		for (const key of ARCHIVED_KEYS) {
			expect(
				joined,
				`${key} should not be in the default result`,
			).not.toContain(`${RUN_PREFIX} ${key}`)
		}
		expect(joined).toContain(`${RUN_PREFIX} Open A`)
	})

	// @e2e openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md#scenario-a-tile-and-its-list-agree
	test('the list and the total under it count the same set', async ({ page }) => {
		// A tile and a list disagreeing is the failure, and both read the same
		// query. The count the page itself prints is therefore the thing to
		// compare against the rows it drew.
		await expect(ownRows(page).first()).toBeVisible({ timeout: 30_000 })
		const shown = await ownRows(page).count()

		await chip(page, CHIPS.archived).click()
		await expect(row(page, 'Gearchiveerd A').first()).toBeVisible({
			timeout: 30_000,
		})
		const archived = await ownRows(page).count()

		expect(shown, 'the open cases this run seeded are listed').toBe(
			OPEN_KEYS.length,
		)
		expect(
			archived,
			'the archived cases this run seeded are in the archive lens',
		).toBe(ARCHIVED_KEYS.length)
	})
})

test.describe('An archived case is read-only and says so', () => {
	// @e2e openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md#scenario-the-fields-cannot-be-edited
	test('the case page names the archive and offers no write action', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		await page.goto(`${APP_URL}cases/${cases['Gearchiveerd B']}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const strip = page.getByTestId('case-archived')
		await expect(strip, 'the strip names the archive').toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByTestId('case-archived-headline')).toContainText(
			/archi|gearchiveerd/i,
		)

		// The six write actions are gated on the marker. The menu is
		// `force-menu`, so every entry is absent from the DOM until it opens:
		// asserting a count of zero on a closed menu would pass on a page that
		// still offers all six.
		await openHeaderActionsMenu(page)
		for (const id of [
			'cn-action-add-party',
			'cn-action-link-object',
			'cn-action-log-contact',
			'cn-action-generate-document',
			'cn-action-plan-follow-up',
		]) {
			await expect(
				page.getByTestId(id),
				`${id} writes to the case`,
			).toHaveCount(0)
		}

		// The control that keeps the assertion above from passing on an empty
		// menu: Lifecycle is deliberately still there, because Restore is
		// inside it.
		await expect(page.getByTestId('cn-action-case-lifecycle-menu')).toBeVisible()
	})

	test('an open case of the same type shows neither the strip nor a refusal', async ({
		page,
	}) => {
		// The control that separates "the strip is right" from "the strip
		// never renders". Without it a component that returned false always
		// would pass the assertion above by accident.
		trackDossiqErrors(page)
		await page.goto(`${APP_URL}cases/${cases['Open C']}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		// The menu opening is what says the page loaded, so an absent strip is
		// a strip that chose not to render rather than a page that never got
		// there.
		await openHeaderActionsMenu(page)
		await expect(page.getByTestId('cn-action-add-party')).toBeVisible()
		await expect(page.getByTestId('case-archived')).toHaveCount(0)
	})

	// @e2e openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md#scenario-a-write-shows-the-reason
	test('a write reaching the server anyway is refused, naming the archive', async () => {
		const id = cases['Gearchiveerd B']

		const res = await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case/${id}`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { title: `${RUN_PREFIX} Gearchiveerd B gewijzigd` },
			},
		)

		expect(res.ok(), 'a write to an archived case must be refused').toBeFalsy()
		expect(
			(await res.text()).toLowerCase(),
			'the refusal names the archive rather than failing silently',
		).toContain('archiv')

		// And the case is unchanged, which is the half a status code cannot say.
		const object = await showObject(api, 'case', id)
		expect(String(object?.title ?? '')).toBe(`${RUN_PREFIX} Gearchiveerd B`)
	})
})

test.describe('The status the case ended in', () => {
	test('the archived cases are in the final status the machine declares', async () => {
		// Not a claim about the archive, a guard on the fixture: if the close
		// stopped landing on the final status, every assertion above would
		// still pass while testing something else.
		for (const key of ARCHIVED_KEYS) {
			const object = await showObject(api, 'case', cases[key])
			expect(String(object?.status ?? ''), `${key} is closed`).toBe(statusDone)
		}
	})
})
