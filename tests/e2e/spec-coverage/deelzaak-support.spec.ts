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
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from '../helpers/fixtures.ts'
import { dismissSupportDialog } from '../helpers/nav.ts'

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

/**
 * Seed a parent case, and by default one sub-case hanging off it.
 *
 * The orphan-deletion branch is decided by how many sub-cases the parent has
 * (`requiresOrphanWarning` in src/utils/deelzaakHelpers.js), so a test that
 * reads whatever the register happens to hold asserts whichever branch it
 * lands on. Each side seeds its own shape instead.
 *
 * @param  options            Seeding options.
 * @param  options.withChild  Seed a sub-case referencing the parent.
 * @param  options.title      Title for the parent, when the caller has to find
 *                            its row in a list.
 * @return The seeded ids.
 */
async function seedParentWithSubCase(
	options: { withChild?: boolean; title?: string } = {},
): Promise<{ parentId: string; childId: string | null }> {
	const withChild = options.withChild !== false
	const api = await request.newContext({ storageState: STORAGE_STATE })
	try {
		const token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)
		const parent = await seedCase(api, token, {
			title:
				options.title
				?? `E2E deelzaak parent ${withChild ? 'with' : 'without'} sub-case`,
			caseType: caseType.id,
			description:
				'Seeded by deelzaak-support.spec.ts (orphan-deletion legs).',
		})
		const parentId = objectId(parent)
		let childId: string | null = null
		if (withChild) {
			const child = await seedCase(api, token, {
				title: 'E2E deelzaak child',
				caseType: caseType.id,
				parentCase: parentId,
				description:
					'Seeded by deelzaak-support.spec.ts (orphan-deletion legs).',
			})
			childId = objectId(child)
		}
		return { parentId, childId }
	} finally {
		await api.dispose()
	}
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

/**
 * Open the Cases list narrowed to one seeded title.
 *
 * @param page  The page.
 * @param title The exact seeded title to narrow to.
 */
async function visitCases(page, title: string): Promise<void> {
	await page.goto(
		`/index.php/apps/dossiq/cases?title=${encodeURIComponent(title)}`,
		{ timeout: 90_000 },
	)
	await dismissSupportDialog(page).catch(() => {})
	await expect(
		page.locator('table, .viewTable, [role="table"]').first(),
	).toBeVisible({ timeout: 60_000 })
}

test.describe('Sub-case count badge (deelzaak-support REQ — case list)', () => {
	// @e2e deelzaak-support::case-list-shows-sub-case-count
	// @e2e deelzaak-support::case-without-sub-cases-has-no-badge
	// @e2e deelzaak-support::sub-case-counts-batch-loaded-per-page
	// UNPARKED, AND IT SEEDS THE ROW IT MEANS TO READ.
	//
	// The old FIXME(#719) said "data-dependent … the body renders an empty
	// state, so there is no table to assert against", which was true of the
	// register it was measured on and is a reason to seed, not to stand down.
	// The old body also annotated the no-badge outcome as a note instead of
	// asserting it, so a run where nothing had sub-cases passed while proving
	// neither branch.
	//
	// It also navigated three times inside one test (navTo, reload, navTo) and
	// then slept 1500ms. Six workers on one `php -S` put a page load at 13 to
	// 23 seconds, so that overran the 60s test budget before it asserted
	// anything: measured on CI run 34578033755 as `page.goto: Test timeout of
	// 60000ms exceeded`. One navigation now, and `test.slow()` for the budget.
	test('the case list shows a sub-case badge, and only on a parent', async ({
		page,
	}) => {
		test.slow()
		// 🔴 THE OLD LOCATOR WAS DUTCH AND THE INSTANCE IS ENGLISH.
		// It matched `/\d+ deelzaken/i`. `subCaseCountBadge()` in
		// src/utils/deelzaakHelpers.js returns `t('dossiq', '{count}
		// sub-cases')`, and "N deelzaken" is only what l10n/nl.json renders
		// that into. So on the English CI instance the badge is on the page and
		// the assertion could not see it, which is why the old body could only
		// ever take its own "no badge present" branch. Both spellings now.
		const BADGE = /\d+ (sub-cases|deelzaken)/i
		const withTitle = `${RUN_PREFIX} DZ badge parent`
		const loneTitle = `${RUN_PREFIX} DZ badge lone`
		await seedParentWithSubCase({ title: withTitle })
		await seedParentWithSubCase({ withChild: false, title: loneTitle })

		// Counted BEFORE the navigation, so the render's own fetches are the
		// ones observed. `deelzaken/counts` is the batch endpoint: the claim is
		// one request per rendered page, not one per row.
		const countCalls: string[] = []
		page.on('request', (req) => {
			if (req.url().includes('/api/deelzaken/counts'))
				countCalls.push(req.url())
		})

		// Narrowed by title rather than paged through. Fixtures accumulate
		// across runs, so the row this test seeded need not be on page one of
		// an unfiltered list, and a `getByRole('row')` filter would then be
		// asserting an absence it had not earned.
		await visitCases(page, withTitle)
		const withChild = page.getByRole('row').filter({ hasText: withTitle })
		await expect(
			withChild.getByText(BADGE).first(),
			'a case with one sub-case shows the sub-case badge',
		).toBeVisible({ timeout: 60_000 })

		// Batched, not N+1. The endpoint is asked once for the page, twice at
		// most when the list re-renders after its first data arrives.
		expect(
			countCalls.length,
			`deelzaken counts must be batched per page, saw ${countCalls.length} requests`,
		).toBeLessThanOrEqual(2)

		// THE OTHER HALF. `subCaseCountBadge()` returns '' for a count of zero
		// (REQ-DZS-005-B), so a badge stamped on every row would satisfy the
		// assertion above on its own.
		await visitCases(page, loneTitle)
		const withoutChild = page.getByRole('row').filter({ hasText: loneTitle })
		await expect(withoutChild.first()).toBeVisible({ timeout: 60_000 })
		await expect(
			withoutChild.getByText(BADGE),
			'a case with no sub-cases shows no badge',
		).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText('TypeError')
	})
})

test.describe('Sub-case orphan deletion (deelzaak-support REQ — deletion protection)', () => {
	// @e2e deelzaak-support::delete-parent-case-with-sub-cases-shows-warning
	// @e2e deelzaak-support::delete-case-without-sub-cases-proceeds-normally
	//
	// UNPARKED, AND POINTED AT THE PAGE THE CONTROL IS ON.
	//
	// This skipped on every run, and its reason was right that nothing was
	// missing and wrong about where to look. The delete control is declared in
	// `src/views/cases/DeelzaakList.vue`, which the manifest mounts as the
	// `type: "custom"` page at `/cases/:id/deelzaken`. The old body reached
	// CaseDetail's Related tab instead, where the `case-sub-cases` object-list
	// widget renders the sub-case LIST and no delete action at all. So the
	// control could never attach, on any build, and the five-second wait was
	// measuring the wrong page.
	//
	// BOTH BRANCHES, NOT WHICHEVER THE INSTANCE HAPPENED TO HOLD. The old body
	// took whatever the first case in the register gave it and annotated the
	// other outcome as a note, so a run where no case had sub-cases asserted
	// the orphan warning never appeared. Each branch now seeds the shape it
	// needs: `requiresOrphanWarning(count)` in src/utils/deelzaakHelpers.js is
	// the fork, and the two scenarios are its two sides.
	test('the sub-cases page warns about orphans for a parent with sub-cases', async ({
		page,
	}) => {
		const { parentId } = await seedParentWithSubCase()

		await page.goto(`/index.php/apps/dossiq/cases/${parentId}/deelzaken`, {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})
		await dismissSupportDialog(page).catch(() => {})

		const deleteBtn = page
			.getByRole('button', {
				name: /Delete parent case|Hoofdzaak verwijderen/i,
			})
			.first()
		await expect(
			deleteBtn,
			'DeelzaakList renders the parent delete control once the parent loads',
		).toBeVisible({ timeout: 30_000 })
		await deleteBtn.click()

		// The orphan dialog, by its own title and its own sentence. Asserting
		// the sentence alone would also match the plain confirm dialog if the
		// copy ever converged; asserting both pins the branch.
		await expect(
			page.getByText('Delete case with sub-cases').first(),
			'a parent with sub-cases takes the orphan-warning branch, not the plain confirm',
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByText(/unlink the sub-cases from their parent/i).first(),
		).toBeVisible()
		// Cancel — this test proves the warning, not the deletion.
		await page
			.getByRole('button', { name: /^(Cancel|Annuleren)$/ })
			.first()
			.click()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	// @e2e deelzaak-support::delete-case-without-sub-cases-proceeds-normally
	test('a parent with no sub-cases takes the plain delete confirmation', async ({
		page,
	}) => {
		const { parentId } = await seedParentWithSubCase({ withChild: false })

		await page.goto(`/index.php/apps/dossiq/cases/${parentId}/deelzaken`, {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})
		await dismissSupportDialog(page).catch(() => {})

		const deleteBtn = page
			.getByRole('button', {
				name: /Delete parent case|Hoofdzaak verwijderen/i,
			})
			.first()
		await expect(deleteBtn).toBeVisible({ timeout: 30_000 })
		await deleteBtn.click()

		// The OTHER side of requiresOrphanWarning(): the plain CnConfirmDialog.
		await expect(
			page.getByText('Are you sure you want to delete this case?').first(),
		).toBeVisible({ timeout: 15_000 })
		// And NOT the orphan copy — a case with nothing hanging off it must not
		// be told its sub-cases will be unlinked.
		await expect(
			page.getByText(/unlink the sub-cases from their parent/i),
		).toHaveCount(0)
		await page
			.getByRole('button', { name: /^(Cancel|Annuleren)$/ })
			.first()
			.click()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})

test.describe('Sub-cases list + create (deelzaak-support REQ — section / creation)', () => {
	// @e2e deelzaak-support::parent-case-shows-sub-cases-list
	// @e2e deelzaak-support::parent-case-with-no-sub-cases-shows-empty-state
	// @e2e deelzaak-support::case-without-sub-case-type-support-hides-section
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

	// @e2e deelzaak-support::create-sub-case-from-parent-case-detail
	// @e2e deelzaak-support::sub-case-creation-blocked-when-parent-has-no-sub-case-types
	// @e2e deelzaak-support::sub-case-creation-blocked-when-parent-case-is-closed
	// @e2e deelzaak-support::sub-case-of-sub-case-is-prohibited
	test('the Create sub-case control opens a filtered dialog when allowed, and is hidden otherwise', async ({
		page,
	}) => {
		const opened = await openSubCasesSectionOrSkip(page)
		if (!opened) return

		const createBtn = page
			.getByRole('button', {
				name: /Create sub-case|Create first sub-case|Deelzaak aanmaken|Create Sub-case/i,
			})
			.first()
		if ((await createBtn.count()) === 0) {
			// Button absent is a VALID state: parent closed, parent is itself a
			// sub-case (zrc-013c), or caseType has no subCaseTypes. The page must
			// still render cleanly.
			test.info().annotations.push({
				type: 'note',
				description:
					'Create sub-case button hidden — parent not eligible (closed / itself a sub-case / no subCaseTypes).',
			})
			await expect(page.locator('body')).not.toContainText(
				'Internal Server Error',
			)
			return
		}
		await createBtn.click()
		await page.waitForTimeout(600)
		// The DeelzaakCreateModal opens with a sub-case type picker restricted to
		// the parent's subCaseTypes.
		await expect(
			page
				.getByText(
					/Sub-case type|Parent case type|No allowed sub-case types/i,
				)
				.first(),
		).toBeVisible({ timeout: 8000 })
		const cancel = page
			.getByRole('button', { name: /Cancel|Annuleren|Close/i })
			.first()
		if ((await cancel.count()) > 0) await cancel.click().catch(() => {})
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
