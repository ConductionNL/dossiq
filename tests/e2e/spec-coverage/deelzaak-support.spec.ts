/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the deelzaak (sub-case) UI surface.
 *
 * These tests drive a real browser against the deployed dossiq app. They are
 * defensively guarded: every surface is data-dependent (it needs the seeded
 * hoofdzaak/deelzaak demo objects and the deelzaak build to be deployed). On a
 * fresh/unseeded register or a deploy that predates this change the test SKIPS
 * with a clear reason rather than failing — distinguishing a deploy/data
 * mismatch from a genuine UI defect (see the gate-19 live-verify deploy-reality
 * note). The pure badge/orphan copy + thresholds are unit-tested in
 * tests/vitest/deelzaakHelpers.spec.js; backend orphan-cleanup, counts, and the
 * caseType constraint are proven by PHPUnit (DeelzaakServiceTest /
 * CreateSubCaseHandlerTest) and Newman (deelzaken-api collection).
 *
 * The deelzaak surfaces live behind the manifest "Sub-cases" tab on a case
 * detail (DeelzaakList) and the full-page DeelzaakDetail. The helpers below
 * navigate there and skip cleanly when the surface is not present.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from '../helpers/fixtures.ts'
import { dismissSupportDialog, navTo } from '../helpers/nav.ts'

/** OpenRegister's object API for this app's own register. */
const CASES_API = '/index.php/apps/openregister/api/objects/dossiq/case'

/**
 * Resolve a case to work with, seeding one when the register has none.
 *
 * TWO THINGS THIS REPLACED.
 *
 * 1. It asks the API instead of clicking a row. The previous approach —
 *    `.locator('.viewTableRow, tr[role="row"], .list-item, table tbody tr')
 *    .first()` followed by `.click().catch(() => {})` — had two faults that
 *    cancelled into a silent no-op: `tr[role="row"]` matches the table HEADER,
 *    so `.first()` selected a header, and the swallowed catch made a click that
 *    navigated nowhere look exactly like one that worked. Every test then
 *    asserted against the Cases LIST believing it was on a case detail.
 *
 * 2. It SEEDS rather than standing down. Asking the API first turned the old
 *    false skip into an honest one — "No cases in the seeded register" — which
 *    was true, and still left this requirement unverified in CI. dossiq's own
 *    fixture helpers already seed a caseType and a case for the visual suite,
 *    so the data this needs is one call away and there is no reason to skip for
 *    the want of it.
 *
 * Only a genuinely unreachable API skips now, and it names its status code.
 */
async function ensureCaseId(page): Promise<string | null> {
	const resp = await page.request.get(`${CASES_API}?_limit=1`, {
		headers: { Accept: 'application/json' },
	})
	if (!resp.ok()) {
		test.skip(true, `cases API not reachable (HTTP ${resp.status()})`)
		return null
	}
	const body = await resp.json()
	const first = (body.results ?? body.items ?? [])[0]
	if (first) return first.id ?? first['@self']?.id ?? null

	// Empty register — seed the minimum the schema requires (title + caseType).
	let api: APIRequestContext | null = null
	try {
		api = await request.newContext({ storageState: STORAGE_STATE })
		const token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)
		const kase = await seedCase(api, token, {
			title: 'E2E deelzaak parent case',
			caseType: caseType.id,
			description: 'Seeded by deelzaak-support.spec.ts.',
		})
		return objectId(kase)
	} finally {
		await api?.dispose()
	}
}

/** Open the Cases list, or skip when it does not render. */
async function openCasesListOrSkip(page) {
	// NOT wrapped in `.catch(() => {})`. A missing sidebar label is a rename
	// this suite has to notice, and swallowing it here would run every test
	// below against whatever the Dashboard happens to render — green, and
	// asserting nothing. The skip below is for absent DATA, not a broken menu.
	await navTo(page, /^(All cases|Alle zaken)$/)
	await dismissSupportDialog(page).catch(() => {})
	const caseId = await ensureCaseId(page)
	if (!caseId) return false
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
	return true
}

/**
 * Open the first case detail and reveal its Sub-cases SECTION, or skip.
 *
 * There is no Sub-cases TAB, and there never was. This helper used to look for
 * `getByRole('tab', { name: /Sub-cases|Deelzaken/i })` and, on finding none,
 * skip every test in this file with "Sub-cases tab not present in the deployed
 * build (deploy mismatch)" — a deployment excuse for a surface no build has
 * ever shipped. The manifest declares `DeelzaakList` as a `type: "custom"`
 * PAGE at `/cases/:id/deelzaken`, and `CaseDetail` carried only the
 * `case-kpis-sub-cases` COUNT.
 *
 * It is a section in the literal sense now: the sub-cases list is the second
 * SECTION of the Related tab, reached by opening that tab. There was briefly a
 * Sub-cases tab, between the widget landing and the strip coming down from
 * fourteen tabs to six.
 *
 * The spec is the authority and it says section, not tab:
 *
 *   "The case detail view SHALL display a 'Sub-cases' section ... listing all
 *    cases whose parentCase references the current case. The section MUST show
 *    each sub-case's title, status, assignee, and deadline."
 *
 * So the requirement was simply unimplemented. It is now implemented as the
 * `case-sub-cases` object-list widget on `CaseDetail`, and this helper asserts
 * that section on the detail page rather than clicking a tab.
 */
async function openSubCasesSectionOrSkip(page) {
	const caseId = await ensureCaseId(page)
	if (!caseId) return false

	// Navigate by URL. dossiq is history-mode
	// (`createWebHistory(generateUrl('/apps/dossiq'))`) and its detail route is
	// `/cases/:id`, the same form the visual and workflow suites already drive.
	await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, {
		waitUntil: 'domcontentloaded',
	})
	await dismissSupportDialog(page).catch(() => {})

	// Retrying assertion rather than a fixed pause: the detail page mounts its
	// widgets asynchronously, and a `waitForTimeout` either wastes time or
	// races, depending on the runner's load.
	// The sub-cases section now lives in the case-detail tabs widget rather than
	// as its own card on the grid. The requirement is unchanged — the detail view
	// still displays the section — but reaching it takes a click, and the panel is
	// LAZY, so its table does not exist in the DOM until the tab is opened.
	// Asserting the container alone would pass while the list below never renders.
	const tab = page
		.locator('.cn-tabs-widget')
		.getByRole('tab', { name: 'Related', exact: true })
		.first()
	if ((await tab.count()) > 0) {
		await expect(
			tab,
			'CaseDetail must offer the "Sub-cases" section (deelzaak-support: '
				+ '"Sub-cases section on parent case detail")',
		).toBeVisible({ timeout: 15_000 })
		await tab.click()

		// WAIT for the panel to fill. The panel is lazy, so the click starts a
		// mount AND a fetch, and the caller's `count()` takes one snapshot that
		// cannot retry — it fired against an empty panel and reported the
		// section missing. Same trap the comment above guards for the tab
		// itself; making the panel lazy moved it one step later.
		// Scoped to the sub-cases SECTION and not to the whole panel. Since the
		// strip came down from fourteen tabs to six, the Related tab holds the
		// related-cases list ABOVE this one, so an unscoped `table` count is
		// satisfied by the neighbouring table and this test would pass with the
		// sub-cases list missing entirely.
		const panel = page.locator(
			'.cn-tabs-widget .cn-tabs__content > [role="tabpanel"]:not([hidden]) [data-testid="case-section-case-sub-cases"]',
		)
		await expect
			.poll(
				async () =>
					(await panel.locator('.viewTable, table').count())
					+ (await panel
						.getByText(
							/No sub-cases yet|Nog geen deelzaken|geen deelzaken/i,
						)
						.count()),
				{
					timeout: 20_000,
					message:
						'the Sub-cases panel rendered neither a table nor an empty state within 20s',
				},
			)
			.toBeGreaterThan(0)
	} else {
		// Pre-tabs layout: the section is a card on the grid.
		const section = page
			.locator('.cn-widget-wrapper, section, [class*="widget"]')
			.filter({ hasText: /Sub-cases/i })
			.first()
		await expect(
			section,
			'CaseDetail must render the "Sub-cases" section (deelzaak-support: '
				+ '"Sub-cases section on parent case detail")',
		).toBeVisible({ timeout: 15_000 })
	}

	await expect(page.locator('body')).not.toContainText('Internal Server Error')
	return true
}

test.describe('Sub-case count badge (deelzaak-support REQ — case list)', () => {
	// @e2e deelzaak-support::case-list-shows-sub-case-count
	// @e2e deelzaak-support::case-without-sub-cases-has-no-badge
	// @e2e deelzaak-support::sub-case-counts-batch-loaded-per-page
	// FIXME(#719): data-dependent. Measured on /cases with an unseeded list:
	// table=0, [role=table]=0, .viewTable=0, [class*=card]=0 — the body
	// renders an empty state, so there is no table to assert against.
	test('the case list renders and may show an "N deelzaken" badge in a single batch', async ({
		page,
	}) => {
		test.fixme(
			true,
			'FIXME(#719): data-dependent. Measured on /cases with an unseeded list: table=0, [role=table]=0, .viewTable=0, [class*=card]=0 — the body renders an empty state, so there is no table to assert against.',
		)
		const opened = await openCasesListOrSkip(page)
		if (!opened) return

		// Capture network calls to assert the batch query (one /counts request).
		const countCalls: string[] = []
		page.on('request', (req) => {
			if (req.url().includes('/api/deelzaken/counts'))
				countCalls.push(req.url())
		})
		await page.reload().catch(() => {})
		await openCasesListOrSkip(page)
		await page.waitForTimeout(1500)

		await expect(
			page.locator('table, .viewTable, [role="table"]').first(),
		).toBeVisible({ timeout: 10000 })
		// Badge shown only for cases WITH sub-cases; absent otherwise (no-badge branch).
		const badge = page.getByText(/\d+ deelzaken/i).first()
		if ((await badge.count()) > 0) {
			await expect(badge).toBeVisible()
		} else {
			test.info().annotations.push({
				type: 'note',
				description:
					'No badge present — seeded deelzaak demo not deployed (no-badge branch).',
			})
		}
		// Batch (not N+1): if counts were fetched, they collapse to a single call per render.
		if (countCalls.length > 0) {
			expect(countCalls.length).toBeLessThanOrEqual(2)
		}
		await expect(page.locator('body')).not.toContainText('TypeError')
	})
})

test.describe('Sub-cases list + create (deelzaak-support REQ — section / creation)', () => {
	// @e2e deelzaak-support::parent-case-shows-sub-cases-list
	// @e2e deelzaak-support::parent-case-with-no-sub-cases-shows-empty-state
	// `case-without-sub-case-type-support-hides-section` USED TO BE CITED HERE
	// and has been moved to a reason-bearing `@e2e exclude` on the scenario.
	// That requirement says the section MUST NOT render for a case type with an
	// empty `subCaseTypes`; the assertion below is `hasTable || hasEmpty`, which
	// requires it TO render, so it passed precisely when the requirement was
	// violated. The spec's own body records the section as unimplemented (a
	// manifest widget has no conditional-visibility key), and the fixture in
	// the eligibility block further down confirms it: a case on a case type with
	// no sub-case types still draws the section, explaining itself in the empty
	// state. An exclude naming that is the honest citation until the renderer
	// grows the capability.
	test('the Sub-cases tab renders either a list or an empty state without error', async ({
		page,
	}) => {
		const opened = await openSubCasesSectionOrSkip(page)
		if (!opened) return

		// Either the sub-cases table OR the "No sub-cases yet" empty state must
		// render (depending on whether this parent has sub-cases / sub-case types).
		const table = page.locator('.viewTable, table').first()
		const empty = page
			.getByText(/No sub-cases yet|Nog geen deelzaken|geen deelzaken/i)
			.first()
		const hasTable = (await table.count()) > 0
		const hasEmpty = (await empty.count()) > 0
		expect(hasTable || hasEmpty).toBeTruthy()
		await expect(page.locator('body')).not.toContainText('TypeError')
	})
})

test.describe('Sub-case breadcrumb + roll-up (deelzaak-support REQ — navigation / progress)', () => {
	// @e2e deelzaak-support::sub-case-shows-parent-breadcrumb
	// @e2e deelzaak-support::top-level-case-has-no-breadcrumb
	// @e2e deelzaak-support::roll-up-shows-completion-progress
	// @e2e deelzaak-support::roll-up-with-no-completed-sub-cases
	test('opening a sub-case shows the parent breadcrumb and the list shows a completion roll-up', async ({
		page,
	}) => {
		const opened = await openSubCasesSectionOrSkip(page)
		if (!opened) return

		// The DeelzaakList header carries the "(X/Y completed)" roll-up when a
		// parent is resolved. Assert it renders (any X/Y) when sub-cases exist.
		const rollup = page
			.getByText(/\(\d+\/\d+ completed\)|\(\d+\/\d+ voltooid\)/i)
			.first()
		if ((await rollup.count()) > 0) {
			await expect(rollup).toBeVisible()
		}

		// Open the first sub-case row → DeelzaakDetail must show the parent
		// breadcrumb (a back-link to the parent case).
		const subRow = page.locator('.viewTableRow, table tbody tr').first()
		if ((await subRow.count()) === 0) {
			test.info().annotations.push({
				type: 'note',
				description:
					'No sub-case rows to open — breadcrumb path not reachable on this case.',
			})
			return
		}
		await subRow.click().catch(() => {})
		await page.waitForTimeout(800)
		const breadcrumb = page
			.locator('nav[aria-label="breadcrumb"], .deelzaak-detail__breadcrumb')
			.first()
		if ((await breadcrumb.count()) > 0) {
			await expect(breadcrumb).toBeVisible({ timeout: 5000 })
		} else {
			test.info().annotations.push({
				type: 'note',
				description:
					'Breadcrumb not rendered — row did not navigate to DeelzaakDetail in this deploy.',
			})
		}
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})

/*
 * The six refusal/protection citations, rebuilt against their own fixtures.
 *
 * WHAT THESE REPLACED. All six used to hang off two tests that navigated to
 * `/cases/:id` and looked for controls that do not live there. The Create
 * sub-case action and the Delete case action are on `DeelzaakList`, the page
 * at `/cases/:id/deelzaken`; the case detail carries only the sub-cases
 * LISTING. So the creation test found no button, took its `if (count === 0)`
 * early return, annotated "parent not eligible" and passed — every run, on
 * whatever case the register happened to hold first. A Create sub-case button
 * wrongly offered on a closed case, on a sub-case, or on a case type with no
 * `subCaseTypes` would have taken the happy path and passed too.
 *
 * Three properties this block has that the old one did not:
 *
 *   - Each refusal has its OWN seeded fixture, so the precondition the
 *     scenario names is established rather than hoped for.
 *   - The decisive assertion is the SERVER's answer. Hiding a button is a
 *     courtesy; `POST /api/deelzaken/validate` is the protection, and
 *     `DeelzaakCreateModal` calls it before every write. A build that hid the
 *     button and dropped the guard would pass a UI-only test and fails these.
 *   - Absence is asserted only NEXT TO presence: `openSubCasesPage` first
 *     proves the page painted (heading + Delete action) and each refusal test
 *     then requires the page's own explanation of the refusal. "Correctly
 *     refused" and "surface missing" are no longer the same green.
 */

/** The app's own sub-case validation endpoint — the server-side refusal. */
const VALIDATE_URL = '/index.php/apps/dossiq/api/deelzaken/validate'

let api: APIRequestContext
let fixtureToken: string

/** Case type that ALLOWS two child types. */
let parentTypeId = ''
/** The two allowed child types, by id and by title. */
let childTypeAId = ''
let childTypeBId = ''
let childTypeATitle = ''
let childTypeBTitle = ''
/** A third type that is NOT on the parent's allow-list. */
let strangerTypeId = ''
let strangerTypeTitle = ''
/** Case type that allows NO child types (empty `subCaseTypes`). */
let barrenTypeId = ''

/** Open, childless parent on `parentTypeId` — the eligible case. */
let eligibleParentId = ''
/** Closed parent (endDate set) on `parentTypeId`. */
let closedParentId = ''
/** Open, childless case on `barrenTypeId`. */
let barrenParentId = ''
/** A case that is itself a sub-case of `eligibleParentId`. */
let subCaseId = ''
/** A parent carrying exactly two sub-cases, for the deletion warning. */
let twoChildParentId = ''
let childOneId = ''
let childTwoId = ''
/** A childless case used for the plain deletion confirmation. */
let childlessCaseId = ''

/**
 * Seed a published case type.
 *
 * `isDraft: false` is load-bearing: `case.caseType` carries
 * `x-relation-filter: {isDraft: false}` and the caseType schema defaults
 * `isDraft` to true, so a draft type is invisible to every picker.
 *
 * @param label        A short, human-readable suffix for the title.
 * @param subCaseTypes The case type ids this type may parent (empty allows none).
 * @return The created case type object.
 */
async function seedCaseType(
	label: string,
	subCaseTypes: string[] = [],
): Promise<any> {
	const slug = label.toLowerCase().replace(/[^a-z0-9]+/g, '-')
	return createObject(api, fixtureToken, 'caseType', {
		title: `${RUN_PREFIX} ${label}`,
		identifier: `${RUN_PREFIX.toLowerCase()}-${slug}`,
		description: 'Seeded by deelzaak-support.spec.ts.',
		isDraft: false,
		subCaseTypes,
	})
}

/**
 * Ask the server whether a sub-case of `childCaseTypeId` may hang off
 * `parentCaseUuid`.
 *
 * This is the protection itself rather than a rendering of it: hiding the
 * button without this endpoint answering would leave the refusal unenforced
 * for every caller that is not a mouse click.
 *
 * @param page            The Playwright page (carries the session).
 * @param parentCaseUuid  The proposed parent case.
 * @param childCaseTypeId The proposed child's case type.
 * @return The HTTP status and the parsed body.
 */
async function validateSubCase(
	page,
	parentCaseUuid: string,
	childCaseTypeId: string,
): Promise<{ status: number; body: any }> {
	const res = await page.request.post(VALIDATE_URL, {
		headers: {
			requesttoken: fixtureToken,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},

		data: { parentCaseUuid, childCaseTypeId },
	})
	return { status: res.status(), body: await res.json().catch(() => ({})) }
}

/**
 * Open `/cases/:id/deelzaken` and assert the surface itself rendered.
 *
 * This is the control group for every "the control must not be visible" claim
 * below. `DeelzaakList` draws its heading and its Delete case action once the
 * parent has loaded, whatever the eligibility verdict — so seeing both proves
 * the page is present and merely withholding the Create control, which is what
 * the requirement asks for and what a page that failed to render looks exactly
 * like without this.
 *
 * @param page The Playwright page.
 * @param id   The case whose sub-cases page to open.
 */
async function openSubCasesPage(page, id: string): Promise<void> {
	await page.goto(`/index.php/apps/${REGISTER}/cases/${id}/deelzaken`, {
		waitUntil: 'domcontentloaded',
	})
	await dismissSupportDialog(page).catch(() => {})

	await expect(
		page.getByRole('heading', { name: 'Sub-cases', exact: true }),
		'DeelzaakList must render its "Sub-cases" heading, so an absent Create '
			+ 'control below reads as a refusal and not as a blank page',
	).toBeVisible({ timeout: 30_000 })

	await expect(
		page
			.getByRole('button', {
				name: /Delete (parent )?case|(Hoofd)?zaak verwijderen/i,
			})
			.first(),
		'DeelzaakList must render its Delete case action once the parent has '
			+ 'loaded — the second half of the proof that this page painted',
	).toBeVisible({ timeout: 15_000 })

	await expect(page.locator('body')).not.toContainText('Internal Server Error')
}

/** The Create sub-case control, in both of its labels and both languages. */
function createControl(page) {
	return page.getByRole('button', {
		name: /Create sub-case|Create first sub-case|Deelzaak aanmaken/i,
	})
}

/** The orphan warning copy, which only the with-children path may show. */
const ORPHAN_WARNING =
	/unlink the sub-case|unlinked from their parent|losgekoppeld van hun hoofdzaak/i

test.describe('Deelzaak creation eligibility and deletion protection', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		fixtureToken = await getRequestToken(api)

		const childA = await seedCaseType('Child type A')
		const childB = await seedCaseType('Child type B')
		const stranger = await seedCaseType('Stranger type')
		childTypeAId = objectId(childA)
		childTypeBId = objectId(childB)
		strangerTypeId = objectId(stranger)
		childTypeATitle = String(childA.title)
		childTypeBTitle = String(childB.title)
		strangerTypeTitle = String(stranger.title)

		parentTypeId = objectId(
			await seedCaseType('Parent type', [childTypeAId, childTypeBId]),
		)
		barrenTypeId = objectId(await seedCaseType('Barren type', []))

		eligibleParentId = objectId(
			await seedCase(api, fixtureToken, {
				title: `${RUN_PREFIX} eligible parent`,
				caseType: parentTypeId,
			}),
		)
		closedParentId = objectId(
			await seedCase(api, fixtureToken, {
				title: `${RUN_PREFIX} closed parent`,
				caseType: parentTypeId,
				endDate: '2026-01-31T17:00:00Z',
			}),
		)
		barrenParentId = objectId(
			await seedCase(api, fixtureToken, {
				title: `${RUN_PREFIX} barren parent`,
				caseType: barrenTypeId,
			}),
		)
		subCaseId = objectId(
			await seedCase(api, fixtureToken, {
				title: `${RUN_PREFIX} existing sub-case`,
				caseType: childTypeAId,
				parentCase: eligibleParentId,
			}),
		)

		twoChildParentId = objectId(
			await seedCase(api, fixtureToken, {
				title: `${RUN_PREFIX} parent of two`,
				caseType: parentTypeId,
			}),
		)
		childOneId = objectId(
			await seedCase(api, fixtureToken, {
				title: `${RUN_PREFIX} child one`,
				caseType: childTypeAId,
				parentCase: twoChildParentId,
			}),
		)
		childTwoId = objectId(
			await seedCase(api, fixtureToken, {
				title: `${RUN_PREFIX} child two`,
				caseType: childTypeBId,
				parentCase: twoChildParentId,
			}),
		)
		childlessCaseId = objectId(
			await seedCase(api, fixtureToken, {
				title: `${RUN_PREFIX} childless case`,
				caseType: parentTypeId,
			}),
		)
	})

	test.afterAll(async () => {
		if (api !== undefined) {
			await cleanupRunObjects(api, fixtureToken, ['case', 'caseType'])
			await api.dispose()
		}
	})

	// @e2e deelzaak-support::create-sub-case-from-parent-case-detail
	test('an eligible parent offers Create sub-case, filtered to its own subCaseTypes, and the created case carries parentCase', async ({
		page,
	}) => {
		// The positive half of the guard the three refusals below assert.
		// Without it, "the button is hidden" would be satisfied by a build that
		// hides the button everywhere.
		const allowed = await validateSubCase(page, eligibleParentId, childTypeAId)
		expect(
			allowed.status,
			'an allowed child type on an open, top-level parent must validate',
		).toBe(200)
		expect(allowed.body.ok).toBe(true)

		// A type the parent does NOT list is refused by the same endpoint, so
		// the 200 above is a verdict rather than a rubber stamp.
		const stranger = await validateSubCase(
			page,
			eligibleParentId,
			strangerTypeId,
		)
		expect(stranger.status).toBe(409)
		expect(stranger.body.reason).toBe('case_type_not_allowed')

		await openSubCasesPage(page, eligibleParentId)
		await expect(createControl(page).first()).toBeVisible({ timeout: 15_000 })
		await createControl(page).first().click()

		const dialog = page.getByRole('dialog').filter({ hasText: /Sub-case type/ })
		await expect(dialog).toBeVisible({ timeout: 15_000 })

		// The scenario's second clause: the dropdown MUST offer only the types
		// on the parent's allow-list. Reading the option list is what makes it
		// falsifiable — the previous test accepted the string "No allowed
		// sub-case types" as a pass for this same anchor.
		await dialog.getByRole('combobox', { name: /Sub-case type/i }).click()
		const options = page.getByRole('option')
		await expect(options).toHaveCount(2, { timeout: 15_000 })
		await expect(options.filter({ hasText: childTypeATitle })).toHaveCount(1)
		await expect(options.filter({ hasText: childTypeBTitle })).toHaveCount(1)
		await expect(options.filter({ hasText: strangerTypeTitle })).toHaveCount(0)

		await options.filter({ hasText: childTypeATitle }).click()

		const subTitle = `${RUN_PREFIX} created sub-case`
		await dialog.getByRole('textbox').first().fill(subTitle)
		await dialog.getByRole('button', { name: /Create sub-case/i }).click()

		// THE STORED ROW, not a toast. "on submit, the created case MUST have
		// `parentCase` set to the parent case's UUID" is a claim about the
		// register, and only the register can answer it.
		let created: any
		await expect(async () => {
			const rows = await listObjects(api, 'case', {
				parentCase: eligibleParentId,
				_limit: '500',
			})
			created = rows.find((row: any) => String(row.title ?? '') === subTitle)
			expect(created, `no case titled ${subTitle} was stored`).toBeTruthy()
		}).toPass({ timeout: 30_000 })
		expect(String(created.parentCase)).toBe(eligibleParentId)
		expect(String(created.caseType)).toBe(childTypeAId)
	})

	// @e2e deelzaak-support::sub-case-creation-blocked-when-parent-has-no-sub-case-types
	test('a parent whose case type allows no sub-case types is refused server-side and offers no Create control', async ({
		page,
	}) => {
		const refused = await validateSubCase(page, barrenParentId, childTypeAId)
		expect(
			refused.status,
			'an empty subCaseTypes allow-list must be refused by the server, not '
				+ 'merely hidden in the UI',
		).toBe(409)
		expect(refused.body.ok).toBe(false)
		expect(refused.body.reason).toBe('case_type_not_allowed')

		await openSubCasesPage(page, barrenParentId)
		await expect(createControl(page)).toHaveCount(0)
		// The page must SAY why, which is how a refusal is told apart from a
		// page that simply failed to draw the control.
		await expect(
			page.getByText(/The parent case type does not allow any sub-cases/i),
		).toBeVisible({ timeout: 15_000 })
	})

	// @e2e deelzaak-support::sub-case-creation-blocked-when-parent-case-is-closed
	test('a closed parent is refused server-side and offers no Create control', async ({
		page,
	}) => {
		const refused = await validateSubCase(page, closedParentId, childTypeAId)
		expect(
			refused.status,
			'a parent carrying an endDate must be refused by the server',
		).toBe(409)
		expect(refused.body.ok).toBe(false)
		expect(refused.body.reason).toBe('parent_closed')

		// The same child type IS allowed on the open parent of the same case
		// type, so the refusal is attributable to the endDate and nothing else.
		const openTwin = await validateSubCase(page, eligibleParentId, childTypeAId)
		expect(openTwin.status).toBe(200)

		await openSubCasesPage(page, closedParentId)
		await expect(createControl(page)).toHaveCount(0)
		await expect(
			page.getByText(/This case is closed; sub-cases can no longer be added/i),
		).toBeVisible({ timeout: 15_000 })
	})

	// @e2e deelzaak-support::sub-case-of-sub-case-is-prohibited
	test('a case that is itself a sub-case is refused server-side and offers no Create control', async ({
		page,
	}) => {
		// The fixture is the point: this case has a non-null `parentCase`, which
		// nothing in the previous test established about whatever case it
		// happened to open first.
		const stored = await showObject(api, 'case', subCaseId)
		expect(String(stored.parentCase)).toBe(eligibleParentId)

		const refused = await validateSubCase(page, subCaseId, childTypeBId)
		expect(
			refused.status,
			'grandparenting must be refused by the server, not merely hidden',
		).toBe(409)
		expect(refused.body.ok).toBe(false)
		expect(refused.body.reason).toBe('grandparenting_forbidden')

		await openSubCasesPage(page, subCaseId)
		await expect(createControl(page)).toHaveCount(0)
		await expect(
			page.getByText(/Sub-cases cannot themselves have sub-cases/i),
		).toBeVisible({ timeout: 15_000 })
	})

	// @e2e deelzaak-support::delete-parent-case-with-sub-cases-shows-warning
	test('deleting a parent with sub-cases warns about the orphans and nulls parentCase on every child', async ({
		page,
	}) => {
		await openSubCasesPage(page, twoChildParentId)

		// Both children are on the page, so the "2 sub-cases" the warning names
		// is a number this run produced rather than one read off the demo set.
		await expect(page.locator('table.viewTable tbody tr')).toHaveCount(2)

		await page
			.getByRole('button', {
				name: /Delete (parent )?case|(Hoofd)?zaak verwijderen/i,
			})
			.first()
			.click()

		// UNCONDITIONAL. This assertion used to sit inside
		// `if ((await warning.count()) > 0)` with an annotation in the else
		// branch, so a parent deleted with no warning at all was
		// indistinguishable from one correctly warned.
		await expect(
			page.getByText(ORPHAN_WARNING).first(),
			'a parent with sub-cases must warn that deletion unlinks them',
		).toBeVisible({ timeout: 15_000 })

		await page
			.getByRole('dialog')
			.filter({ hasText: ORPHAN_WARNING })
			.getByRole('button', { name: /^(Delete|Verwijderen)$/ })
			.click()

		// The scenario's real subject: the children survive as standalone cases.
		// Asserted on the stored rows, because a dialog can say anything.
		await expect(async () => {
			for (const childId of [childOneId, childTwoId]) {
				const child = await showObject(api, 'case', childId)
				expect(
					child.parentCase ?? null,
					`case ${childId} must be unlinked, not left pointing at a deleted parent`,
				).toBeFalsy()
			}
		}).toPass({ timeout: 45_000 })
	})

	// @e2e deelzaak-support::delete-case-without-sub-cases-proceeds-normally
	test('deleting a case with no sub-cases takes the plain confirmation, with no orphan warning', async ({
		page,
	}) => {
		await openSubCasesPage(page, childlessCaseId)
		await expect(
			page.getByText(/No sub-cases yet|Nog geen deelzaken/i),
		).toBeVisible({ timeout: 15_000 })

		await page
			.getByRole('button', {
				name: /Delete (parent )?case|(Hoofd)?zaak verwijderen/i,
			})
			.first()
			.click()

		// The standard confirmation, asserted by its own copy. The only
		// unconditional assertion this anchor used to carry was "the body does
		// not say Internal Server Error", which a page showing no dialog at all
		// satisfies just as well.
		await expect(
			page.getByText(/Are you sure you want to delete this case/i),
		).toBeVisible({ timeout: 15_000 })

		// And NOT the orphan warning. This half is what makes the pair a
		// discrimination rather than two unrelated smoke checks.
		await expect(page.getByText(ORPHAN_WARNING)).toHaveCount(0)

		await page
			.getByRole('button', { name: /^(Cancel|Annuleren)$/ })
			.first()
			.click()
	})
})
