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
 * Seed a parent and an exact set of sub-cases, and read the children BACK.
 *
 * Separate from `seedParentWithSubCase` above, which the orphan-deletion legs
 * own and which only needs "a child or no child". The section, roll-up and
 * breadcrumb scenarios each name a specific shape (one child with known
 * fields, two children with one completed, none at all), and a fixture that
 * cannot express the shape is what left those citations asserting existence.
 *
 * 🔴 THE CHILDREN ARE READ BACK RATHER THAN ASSUMED, and that is not caution,
 * it is required: `case.deadline` IS READ-ONLY. It is materialised from
 * `startDate` plus the case type's `processingDeadline`, so a test that
 * asserts the deadline it "seeded" asserts a value it never wrote. Measured
 * 2026-09-12: a child seeded with `startDate: 2026-09-01` stored
 * `deadline: 2026-10-27`. The caller gets the STORED row and asserts that.
 *
 * @param  options           Seeding options.
 * @param  options.children  One entry per sub-case; the fields are merged over
 *                           the defaults, so a `{}` entry is a plain child.
 * @param  options.title     Title for the parent.
 * @return The parent id and the stored child rows, in seeding order.
 */
async function seedParentWithChildren(options: {
	children: Array<Record<string, unknown>>
	title?: string
}): Promise<{ parentId: string; children: any[]; statusName: string }> {
	const api = await request.newContext({ storageState: STORAGE_STATE })
	try {
		const token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)

		// A status of this run's own, so the Status column can be asserted
		// against a value nothing else on the instance holds. `case.status` is
		// a `$ref` to a statusType, so the column cannot be checked without
		// one, and picking whatever status the demo data happens to use would
		// make the assertion pass on another case's row.
		//
		// ONE PER RUN, cached, and not one per call. Six calls would leave six
		// statusType rows on an instance whose teardown deliberately sweeps no
		// cases (see `afterAll`), and every seeded case REFERENCES this row, so
		// a teardown that removed it would leave dangling references behind
		// rather than clean up — the failure mode `sweepPrefix`'s child-first
		// ordering exists to avoid. One row is the smallest residue that still
		// makes the column assertable.
		const { id: statusId, name: statusName } = await ensureSubCaseStatus(
			api,
			token,
			caseType.id,
		)

		const parent = await seedCase(api, token, {
			title: options.title ?? `${RUN_PREFIX} deelzaak parent`,
			caseType: caseType.id,
			description: 'Seeded by deelzaak-support.spec.ts (section legs).',
		})
		const parentId = objectId(parent)

		const children: any[] = []
		for (const [index, fields] of options.children.entries()) {
			const created = await seedCase(api, token, {
				title: `${RUN_PREFIX} deelzaak child ${index + 1}`,
				caseType: caseType.id,
				parentCase: parentId,
				status: statusId,
				description: 'Seeded by deelzaak-support.spec.ts (section legs).',
				...fields,
			})
			children.push(await showObject(api, 'case', objectId(created)))
		}
		return { parentId, children, statusName }
	} finally {
		await api.dispose()
	}
}

/**
 * The sub-cases SECTION inside the open Related panel.
 *
 * Scoped exactly as `openSubCasesSectionOrSkip` scopes its wait, and for the
 * reason its comment records: since the strip came down to six tabs the
 * Related panel holds the related-cases list ABOVE this one, so a page-wide
 * `table` locator is satisfied by the neighbouring table and every assertion
 * below would pass with the sub-cases list missing entirely.
 *
 * @param  page The page, with the Related tab already open.
 * @return The section locator.
 */
function subCasesSection(page) {
	return page.locator(
		'.cn-tabs-widget .cn-tabs__content > [role="tabpanel"]:not([hidden]) [data-testid="case-section-case-sub-cases"]',
	)
}

/** The uid the sub-case fixtures assign, so the Assignee column has a value. */
const SUB_CASE_ASSIGNEE = 'admin'

/** The one statusType this run's sub-cases share, created on first use. */
let subCaseStatus: { id: string; name: string } | null = null

/**
 * The statusType every seeded sub-case in this file points at.
 *
 * Created once per run and memoised. See the call site for why one shared row
 * rather than one per fixture: the seeded cases reference it, and this file
 * deliberately sweeps no cases, so each extra statusType would be residue that
 * cannot safely be removed while a case still names it.
 *
 * @param  api        Authenticated request context.
 * @param  token      CSRF request-token.
 * @param  caseTypeId The case type the status belongs to.
 * @return The status id and its name.
 */
async function ensureSubCaseStatus(
	api: APIRequestContext,
	token: string,
	caseTypeId: string,
): Promise<{ id: string; name: string }> {
	if (subCaseStatus !== null) return subCaseStatus
	const name = `${RUN_PREFIX} Deelzaak status`
	const created = await createObject(api, token, 'statusType', {
		name,
		caseType: caseTypeId,
		order: 1,
		isFinal: false,
	})
	subCaseStatus = { id: objectId(created), name }
	return subCaseStatus
}

/**
 * Quote a value for use inside a `RegExp`.
 *
 * @param  value The literal text.
 * @return The escaped text.
 */
function escapeForRegExp(value: string): string {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * The spellings a stored ISO date may be printed in.
 *
 * The cell may carry the ISO string the server stored or the localised form
 * the renderer chose, and which of the two is a rendering decision rather than
 * something this requirement has an opinion about. What it does have an
 * opinion about is that the deadline is SHOWN, so both spellings pass and an
 * empty cell, or another date, fails.
 *
 * @param  iso The stored date, `YYYY-MM-DD`.
 * @return A pattern matching either spelling.
 */
function deadlineSpellings(iso: string): RegExp {
	const [year, month, day] = iso.slice(0, 10).split('-')
	return new RegExp(
		[
			escapeForRegExp(`${year}-${month}-${day}`),
			escapeForRegExp(`${day}-${month}-${year}`),
			escapeForRegExp(`${Number(day)}-${Number(month)}-${year}`),
			escapeForRegExp(`${day}/${month}/${year}`),
		].join('|'),
	)
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
async function openSubCasesSectionOrSkip(page, onCase?: string) {
	// 🔴 THE CASE IS NOW THE CALLER'S TO NAME. It used to be whatever
	// `ensureCaseId` found first, so every assertion below was made against an
	// arbitrary row of the register: a parent with children, a childless case
	// or a demo case, whichever the listing happened to return. That is why the
	// two tests this helper served could only ever assert "a table OR an empty
	// state", and why neither could tell the two scenarios apart. Passing the
	// seeded id makes the precondition the scenario names an established fact
	// rather than a coincidence.
	const caseId = onCase ?? (await ensureCaseId(page))
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
	//
	// 🔴 `delete-case-without-sub-cases-proceeds-normally` WAS CITED HERE TOO
	// AND HAS BEEN TAKEN DOWN. This test seeds a parent WITH a sub-case and
	// only ever exercises the orphan branch, so nothing in it says what a
	// childless case's delete dialog looks like: breaking the plain
	// confirmation left every assertion in here green. The scenario keeps two
	// citations that do prove it, the sibling test directly below and
	// `deleting a case with no sub-cases takes the plain confirmation` further
	// down this file, so nothing is lost by removing the claim that was false.
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
	//
	// ✅ MUTATION CHECK RUN 2026-09-12, with `tests/e2e/helpers/mutate-bundle.ts`:
	// the served bundle was rewritten on its way to the browser, so the broken
	// fork really ran while nothing on disk moved.
	//
	//   find    /onDeleteParent\(\)\{!function\(\w+\)\{const \w+=Number\(\w+\);return Number\.isFinite\(\w+\)&&\w+>0\}/
	//   replace 'onDeleteParent(){!function(){return true}'
	//   red on  "a childless case takes the standard deletion confirmation"
	//
	// `requiresOrphanWarning()` forced true sends a childless case down the
	// orphan branch, which is the state this scenario forbids. That is also
	// why the citation was taken off the orphan-branch test above: this break
	// leaves every assertion in that one green.
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
			'a childless case takes the standard deletion confirmation',
		).toBeVisible({ timeout: 15_000 })
		// And NOT the orphan copy.
		await expect(
			page.getByText(/unlink the sub-cases from their parent/i),
			'a case with nothing hanging off it must not be told its sub-cases will be unlinked',
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
	// 🔴 ONE TEST USED TO CARRY BOTH CITATIONS ABOVE, AND SETTLED THEM WITH
	// `expect(hasTable || hasEmpty).toBeTruthy()`. Two scenarios describing
	// OPPOSITE states, satisfied by either branch, on whatever case
	// `ensureCaseId` returned first. It could not distinguish a parent that
	// lists its children from a childless one, and it could not fail: a build
	// that rendered an empty state for a parent WITH sub-cases passed, and so
	// did one that rendered a table of the wrong case's rows.
	//
	// They are two tests now, each on its own seeded shape, and each asserts
	// the half the other one must not have.
	//
	// ✅ MUTATION CHECKED 2026-09-12 with `helpers/mutate-bundle.ts`, so the
	// broken code really ran while nothing on disk moved. Relabelling the
	// Deadline column in the served manifest,
	//
	//   find    /"key":"deadline","label":"Deadline"/
	//   replace "key":"deadline","label":"Zzz"
	//
	// reddens this test on `the section must show each sub-case's deadline`,
	// expected 1 and received 0. The assertion it replaced could not see that
	// at all: a section with no Deadline column still rendered a table, and a
	// table was the whole of what `hasTable || hasEmpty` asked for.
	test('a parent lists each of its sub-cases with title, status, assignee and deadline', async ({
		page,
	}) => {
		// The scenario asks for title, status, assignee and deadline, so the
		// child is seeded with all four. `deadline` is NOT among the seeded
		// fields because it cannot be: it is read-only and materialised from
		// `startDate`, so the fixture reads the stored row back and this test
		// asserts the value the server computed.
		const { parentId, children, statusName } = await seedParentWithChildren({
			children: [{ assignee: SUB_CASE_ASSIGNEE, startDate: '2026-09-01' }],
		})
		const child = children[0]

		const opened = await openSubCasesSectionOrSkip(page, parentId)
		expect(
			opened,
			'the seeded parent must open, or nothing below is an observation about the section',
		).toBe(true)

		const section = subCasesSection(page)

		// THE COLUMNS THE REQUIREMENT NAMES, as headings. A row carrying the
		// right values under the wrong headings is a different surface, and a
		// section that quietly drops the Deadline column would otherwise still
		// pass on the three values that remain.
		for (const heading of ['Title', 'Status', 'Assignee', 'Deadline']) {
			await expect(
				section.getByRole('columnheader', {
					name: new RegExp(heading, 'i'),
				}),
				`the section must show each sub-case's ${heading.toLowerCase()}`,
			).toHaveCount(1, { timeout: 20_000 })
		}

		// THAT child, by its seeded title, not "a row".
		const row = section
			.locator('.viewTableRow, tbody tr')
			.filter({ hasText: String(child.title) })
		await expect(
			row,
			'the section must list the sub-case this test seeded',
		).toHaveCount(1, { timeout: 20_000 })

		await expect(
			row,
			'the row must carry the assignee the sub-case was seeded with',
		).toContainText(SUB_CASE_ASSIGNEE)

		// The SERVER's deadline, which is the only one that exists. Asserted
		// against the stored value rather than the string this test passed in,
		// because the two are deliberately different.
		expect(
			String(child.deadline ?? ''),
			'the seeded startDate must have materialised a deadline, or the column below proves nothing',
		).not.toBe('')
		// Either spelling: the cell may print the stored ISO date or the
		// localised form, and which one is the renderer's business rather than
		// this requirement's. An empty cell matches neither.
		await expect(
			row,
			'the row must carry the deadline the server materialised from startDate',
		).toContainText(deadlineSpellings(String(child.deadline)))

		// Status is a `$ref` to a statusType, and CnIndexPage renders a $ref
		// column raw, so the cell holds the uuid today and the status NAME once
		// nextcloud-vue renders reference columns by their label field. Either
		// is the right answer; an empty cell and another case's status are not.
		await expect(
			row,
			'the row must carry the status the sub-case was seeded with',
		).toContainText(
			new RegExp(
				`${escapeForRegExp(String(child.status))}|${escapeForRegExp(statusName)}`,
			),
		)

		await expect(page.locator('body')).not.toContainText('TypeError')
	})

	test('a parent with no sub-cases shows the empty state and no rows at all', async ({
		page,
	}) => {
		const { parentId } = await seedParentWithChildren({ children: [] })

		const opened = await openSubCasesSectionOrSkip(page, parentId)
		expect(opened, 'the seeded parent must open').toBe(true)

		const section = subCasesSection(page)

		await expect(
			section.getByText(/No sub-cases yet|Nog geen deelzaken|geen deelzaken/i),
			'a parent with no sub-cases must say so',
		).toHaveCount(1, { timeout: 20_000 })

		// BOTH HALVES, because the empty state alone is what the old `||` had.
		// A section that renders the empty message ABOVE a table of some other
		// case's rows satisfies the first assertion and fails this one.
		await expect(
			section.locator('.viewTableRow, tbody tr'),
			'a parent with no sub-cases must list no sub-case rows',
		).toHaveCount(0)

		await expect(page.locator('body')).not.toContainText('TypeError')
	})
})

test.describe('Sub-case breadcrumb + roll-up (deelzaak-support REQ — navigation / progress)', () => {
	// @e2e deelzaak-support::sub-case-shows-parent-breadcrumb
	// @e2e deelzaak-support::top-level-case-has-no-breadcrumb
	// @e2e deelzaak-support::roll-up-shows-completion-progress
	// @e2e deelzaak-support::roll-up-with-no-completed-sub-cases
	// 🔴 FOUR CITATIONS ON ONE TEST THAT PROVED NONE OF THEM, and the reason is
	// worth keeping: it looked for the roll-up and the breadcrumb on the CASE
	// DETAIL, and neither is there. The roll-up is `DeelzaakList`'s header, on
	// `/cases/:id/deelzaken`; the breadcrumb is `DeelzaakDetail`'s, on
	// `/cases/:parentId/deelzaken/:id`. `CaseHeaderRow` deliberately removed
	// its own breadcrumb ("the trail said Cases > X one line below X"). So both
	// counts were structurally zero, both `if (count > 0)` guards took their
	// empty branch every run, and the test passed by not looking.
	//
	// Every guard below is gone rather than tightened. A guard that skips when
	// the feature is absent IS the defect: it reports the same green whether
	// the surface works or was never built.
	//
	// ✅ BOTH HALVES MUTATION CHECKED 2026-09-12 with `helpers/mutate-bundle.ts`.
	//
	//   completedCount(){return this.subCases.filter(e=>e.endDate).length}
	//     -> completedCount(){return this.subCases.length}
	//   reddens the roll-up test: the header printed 2/2, and
	//   "a parent with two sub-cases, one carrying an endDate, must roll up as
	//    1/2" failed, expected 1 received 0.
	//
	//   the parent crumb's `parent.title||parent.identifier||` fallback chain
	//   cut to its last term reddens the breadcrumb test:
	//   Expected substring "…breadcrumb parent",
	//   received " Parent case › …deelzaak child 1".
	//
	// Both are defects the old test could not have caught. `\(\d+\/\d+
	// completed\)` matches 2/2 exactly as happily as 1/2, and a breadcrumb
	// printing the literal "Parent case" satisfies a `count() > 0` check.
	test('the roll-up counts completed sub-cases exactly', async ({ page }) => {
		// The scenario's shape, not "some X/Y": two sub-cases, one completed.
		// `completedCount` is `subCases.filter((sc) => sc.endDate).length`, so
		// completion is an endDate and nothing else.
		const { parentId } = await seedParentWithChildren({
			children: [{ endDate: '2026-09-05' }, {}],
		})

		await page.goto(`/index.php/apps/dossiq/cases/${parentId}/deelzaken`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissSupportDialog(page).catch(() => {})

		// EXACTLY 1/2. The old assertion matched `\(\d+\/\d+ completed\)`, which
		// any parent on the instance satisfies and which cannot tell a correct
		// roll-up from one that counts every sub-case as done.
		await expect(
			page.getByText(/\(1\/2 (completed|voltooid)\)/i),
			'a parent with two sub-cases, one carrying an endDate, must roll up as 1/2',
		).toHaveCount(1, { timeout: 30_000 })

		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('a parent with no completed sub-cases rolls up as none done', async ({
		page,
	}) => {
		const { parentId } = await seedParentWithChildren({
			children: [{}, {}],
		})

		await page.goto(`/index.php/apps/dossiq/cases/${parentId}/deelzaken`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissSupportDialog(page).catch(() => {})

		await expect(
			page.getByText(/\(0\/2 (completed|voltooid)\)/i),
			'two sub-cases and no endDate between them must roll up as 0/2',
		).toHaveCount(1, { timeout: 30_000 })

		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('a sub-case names its parent in the breadcrumb', async ({ page }) => {
		const parentTitle = `${RUN_PREFIX} breadcrumb parent`
		const { parentId, children } = await seedParentWithChildren({
			title: parentTitle,
			children: [{}],
		})
		const childId = objectId(children[0])

		await page.goto(
			`/index.php/apps/dossiq/cases/${parentId}/deelzaken/${childId}`,
			{ waitUntil: 'domcontentloaded' },
		)
		await dismissSupportDialog(page).catch(() => {})

		const breadcrumb = page.locator(
			'nav[aria-label="breadcrumb"], .deelzaak-detail__breadcrumb',
		)
		await expect(
			breadcrumb,
			'a sub-case must display a breadcrumb back to its parent',
		).toHaveCount(1, { timeout: 30_000 })

		// THE PARENT BY NAME. A breadcrumb that renders but names the wrong
		// case, or renders the literal fallback "Parent case", is not the
		// requirement: "the breadcrumb MUST show the parent case's title".
		await expect(
			breadcrumb,
			'the breadcrumb must name the parent case this sub-case belongs to',
		).toContainText(parentTitle)

		// And it must be the way BACK, not a label.
		await expect(
			breadcrumb.getByRole('link', {
				name: new RegExp(escapeForRegExp(parentTitle)),
			}),
			'the parent crumb must be a link to the parent case',
		).toHaveCount(1)

		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('a top-level case shows no parent breadcrumb', async ({ page }) => {
		// The other direction, without which `top-level-case-has-no-breadcrumb`
		// is cited by a test that only ever looks at sub-cases. The parent
		// seeded here has `parentCase` null, which is exactly the scenario's
		// precondition.
		const { parentId } = await seedParentWithChildren({ children: [{}] })

		await page.goto(`/index.php/apps/dossiq/cases/${parentId}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissSupportDialog(page).catch(() => {})
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await expect(
			page.locator(
				'nav[aria-label="breadcrumb"], .deelzaak-detail__breadcrumb',
			),
			'a case with no parent must display no parent breadcrumb',
		).toHaveCount(0)

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
		// SET ON PURPOSE, and it hides a product defect this test is not
		// about. `DeelzaakCreateModal` copies the child type's confidentiality
		// onto the new case and falls back to 'public' when there is none, but
		// the case schema's enum is Dutch ('openbaar', 'intern', ...) and
		// refuses 'public' with a 400. Without this line the create fails
		// before `parentCase` is ever written. Measured 2026-09-11.
		confidentiality: 'openbaar',
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
		// A describe-level `setTimeout` governs TESTS, not HOOKS: a hook keeps
		// the config's 30s until it is widened from inside itself. Seeding a
		// dozen objects on a loaded instance does not fit in 30s, and the
		// failure then reads `"beforeAll" hook timeout` against whichever test
		// ran first, which points at the wrong thing entirely.
		test.setTimeout(300_000)
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
		// NO SWEEP HERE, by the same rule `case-documents.spec.ts` follows. The
		// cases are archival, so removing them takes an occ purge per row, and
		// on a loaded instance that walk overruns the 120s the helper allows
		// itself: the run then reports `"afterAll" hook timeout` against the
		// last test, whose assertions all passed. Every row here carries the
		// family prefix, and global-setup's residue sweep removes cases before
		// their case types, which is the order a teardown here would need too.
		//
		// THE SECTION FIXTURES KEEP TO THAT RULE RATHER THAN BENDING IT, and the
		// decision is recorded because the obvious alternative is worse. They
		// seed parents and children, which are cases and so archival, plus ONE
		// statusType per run. Sweeping the statusType here would remove a row
		// that every seeded case still references, which is not cleanup: it is
		// the dangling-reference state `sweepPrefix`'s child-first ordering
		// exists to prevent, and it reddens unrelated specs rather than this
		// one. So the fixture minimises what it leaves instead of removing it
		// unsafely: the status is created once and memoised, not once per call,
		// which is six rows fewer than the naive version.
		await api?.dispose()
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
			'an allowed child type on an open, top-level parent must validate: '
				+ JSON.stringify(allowed.body),
		).toBe(200)
		expect(allowed.body.ok).toBe(true)

		// A type the parent does NOT list is refused by the same endpoint, so
		// the 200 above is a verdict rather than a rubber stamp.
		const stranger = await validateSubCase(
			page,
			eligibleParentId,
			strangerTypeId,
		)
		expect(stranger.status, JSON.stringify(stranger.body)).toBe(409)
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
			// By TITLE, not by parentCase. Filtering on the field under test
			// would turn "stored without parentCase" into "not stored at all",
			// and the red would name the wrong defect.
			const rows = await listObjects(api, 'case', {
				title: subTitle,
				_limit: '50',
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
		expect(openTwin.status, JSON.stringify(openTwin.body)).toBe(200)

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
		// EXACT, because the empty state's description also contains the words
		// "no sub-cases yet" and a loose match resolves to both.
		await expect(
			page.getByText(/^(No sub-cases yet|Nog geen deelzaken)$/).first(),
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
