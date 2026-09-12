/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case identity card, the absent breadcrumb and the tab order (A01, A33).
 *
 * Everything here is a seam a unit test cannot reach. The manifest spec
 * already pins the declarations; this spec asks whether the page built from
 * them says which case you are on.
 *
 *  - the row turns a stored uuid into a status NAME and a stored date into a
 *    number of days, both of which need a live register;
 *  - the breadcrumb is GONE, and only a rendered page can show that the title
 *    is now stated once rather than twice;
 *  - the tab strip's geometry at 1024 is the whole point of row A33, and
 *    geometry only exists in a browser.
 *
 * Assert ids and testids, not labels, wherever a label would do. The instance
 * may run in Dutch: five of seven specs went red once on a correct navigation
 * because they asserted English. The two places a label IS asserted here (the
 * status name, the case type title) are seeded by this spec, so they read the
 * same in either locale.
 */

import type {Locator, Page} from '@playwright/test';

import { expect, test } from '@playwright/test'
import {
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

/**
 * The tab order placement row A33 asks for, which is now the WHOLE strip.
 *
 * These are exact strings rather than locale alternatives because a tab label
 * is not translated: `CnTabsWidget` reads `content.tabs[].label` out of the
 * manifest verbatim, with no `t()` on the path.
 *
 * Row A33 asked for six tabs and the strip carried fourteen. The five "work
 * tabs" and the "conditional four" that used to be tracked separately here are
 * one list now: there is nothing left after the work tabs to close the strip
 * with. Sub-cases, Locations, Appointments and Decisions are not gone from the
 * page. Decisions moved to the sidebar tab that already carried it; the other
 * three became sections of Related, Objects and locations, and Work.
 */
const WORK_TABS = [
	'Data',
	'Documents',
	'People',
	'Work',
	'Related',
	'Objects and locations',
]

/**
 * One KPI tile in the case's top row, found by its label.
 *
 * The row is five built-in tiles now (`stat` in object-field mode and one
 * `countdown`), not the custom `case-header` widget with its testids, so a
 * tile is addressed by the label it prints, in either language.
 *
 * @param page The page.
 * @param label The tile's label.
 * @return The tile.
 */
function tile(page: Page, label: RegExp): Locator {
	return page
		.locator('.cn-kpi-card')
		.filter({ has: page.locator('.cn-kpi-card__title', { hasText: label }) })
}

test.describe('Case header — identity, no breadcrumb, and tab order', () => {
	test.setTimeout(180_000)

	/** The case with a status, an assignee and a deadline behind it. */
	let caseId = ''
	let caseIdentifier = ''
	let caseTitle = ''
	let statusName = ''
	let caseTypeTitle = ''

	/** The case with neither a status record nor a deadline. */
	let bareCaseId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// A throwaway state machine gives this spec a status type it OWNS, so
		// the badge assertion reads a name this spec wrote rather than whatever
		// the instance happens to be seeded with.
		const machine = await seedStateMachine(api, token)
		const inProgress = await showObject(
			api,
			'statusType',
			machine.statusInProgress,
		)
		statusName = String(inProgress.name ?? '')
		// GIVE THE TYPE A TERM. `case.deadline` is computed declaratively as
		// `startDate + caseType.processingDeadline`, and `seedStateMachine`
		// creates its case type WITHOUT one — so without this the register has
		// nothing to compute from, `deadline` stays empty, and the countdown
		// element is never rendered at all. The spec then reads as "the
		// countdown is missing from the header" when the header is correct and
		// the FIXTURE never gave it a deadline to show.
		//
		// Set here rather than in `seedStateMachine`, which many specs share:
		// each call mints its own case type, so this touches only this run's.
		await updateObject(api, token, 'caseType', machine.caseTypeId, {
			processingDeadline: 'P30D',
		})

		const caseType = await showObject(api, 'caseType', machine.caseTypeId)
		caseTypeTitle = String(caseType.title ?? caseType.name ?? '')

		caseTitle = `${RUN_PREFIX} Aanbouw Beethovenlaan 8`
		const seeded = await seedCase(api, token, {
			title: caseTitle,
			caseType: machine.caseTypeId,
			status: machine.statusInProgress,
			assignee: 'admin',
			// A start date well behind us, so the deadline the register COMPUTES
			// from the case type lands in the past whatever its term. The
			// expected words are read back off the stored deadline below rather
			// than assumed, because `deadline` is readOnly and calculated: an
			// assertion on a number this spec wrote would test the fixture.
			startDate: '2024-01-15',
		})
		caseId = objectId(seeded)
		caseIdentifier = String(seeded.identifier ?? '')

		// The second case: a case type with no status types at all, so the case
		// gets no status prefill and no processing deadline, which is the pair
		// REQ-CDV-14's third scenario is about.
		const bareType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Bare`,
			identifier: `${RUN_PREFIX.toLowerCase()}-bare`,
			description:
				'Case type with no statuses and no term, for the header row.',
		})
		const bare = await seedCase(api, token, {
			title: `${RUN_PREFIX} Zaak zonder status`,
			caseType: objectId(bareType),
		})
		bareCaseId = objectId(bare)

		await api.dispose()
	})

	// No afterAll. The `case` schema is archival, so a user-driven DELETE is
	// refused with 403 by design, and deleting the case TYPE out from under an
	// undeletable case leaves every reader of it resolving a dangling
	// reference. A fixture that cannot clean up after itself breaks its
	// neighbours; this one leaves nothing dangling instead.

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-number-reads-under-the-title
	//
	// 🔴 THIS GUARDED THE MECHANISM THE SCENARIO DOES NOT NAME. It measured as
	// verified on 2026-09-11 and as partial on 2026-09-12. The scenario has two
	// THENs: the page TITLE reads the case title, and the SUBTITLE under it
	// reads the identifier. This test asserted neither. It read
	// `case-header-identifier`, a `dd` in `CaseHeaderRow.vue`, so two
	// mechanisms print the case number and the test guarded the one the
	// scenario is not about; the page title was never asserted at all.
	//
	// ⚠️ AND THE MECHANISM REQ-CDV-14 NAMES RENDERS NOTHING. The requirement
	// says `CaseDetail` SHALL set `config.subtitleField` to `identifier`, "so
	// the case number reads under the title". The manifest does set it. Nothing
	// consumes it: in @conduction/nextcloud-vue 2.41 `subtitleField` is read by
	// `CnObjectRow`, `CnIndexPage` and `CnObjectList`, and by no detail-page
	// code at all, so a `type: "detail"` page's `config.subtitleField` is inert.
	// That is why deleting it from the manifest changes nothing on screen, and
	// it is a finding about the product rather than about this test.
	//
	// ✅ MUTATION CHECK RUN 2026-09-12, with `tests/e2e/helpers/mutate-bundle.ts`:
	//
	//   find    /displayTitle\(\)\{return this\.objectDisplayName\|\|this\.resolvedTitle\}/
	//   replace 'displayTitle(){return this.resolvedTitle}'
	//   page    the header read "Case", the manifest's page title
	//   red on  "the case page must name the case in its own title"
	//
	// The assertions this test carried before proved nothing about the page
	// title at all, so they stay green through that break.
	//
	// So the clause is asserted by what it MEANS rather than by the key that
	// was supposed to deliver it: the case number is on the page, and it is
	// BELOW the title. Both halves, because either alone is satisfied by a
	// layout the requirement exists to rule out. The page title is asserted
	// outright, which closes the THEN that was never covered by anything.
	test('the case number, type, status and assignee read under the title', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const number = tile(page, /^(Case number|Zaaknummer)$/)
		await expect(number).toBeVisible({ timeout: 30_000 })

		// THEN the page title reads the case title.
		const pageTitle = page.locator('.cn-detail-page__title')
		await expect(
			pageTitle,
			'the case page must name the case in its own title',
		).toHaveText(caseTitle, { timeout: 20_000 })

		await expect(number.locator('.cn-kpi-card__value')).toHaveText(
			caseIdentifier,
			{ timeout: 20_000 },
		)

		// AND the number reads UNDER it: the tile is where the number renders,
		// so this is the clause the scenario states, measured against the
		// element that delivers it.
		const titleBox = await pageTitle.boundingBox()
		const numberBox = await number.boundingBox()
		expect(titleBox, 'the page title must have a box').not.toBeNull()
		expect(numberBox, 'the case number must have a box').not.toBeNull()
		expect(
			numberBox!.y,
			`the case number must read under the title, not beside or above it: `
				+ `title at y=${titleBox!.y}, number at y=${numberBox!.y}`,
		).toBeGreaterThan(titleBox!.y)
		await expect(
			tile(page, /^(Case type|Zaaktype)$/).locator('.cn-kpi-card__value'),
		).toHaveText(caseTypeTitle, { timeout: 20_000 })
		await expect(
			tile(page, /^(Assignee|Behandelaar)$/).locator('.cn-kpi-card__value'),
		).toHaveText('admin')

		// The tiles sit ABOVE the tab strip: an identity a reader has to
		// scroll to is the state this row replaced.
		const stripBox = await page.locator('.cn-tabs-widget').boundingBox()
		expect(stripBox, 'the tab strip must have a box').not.toBeNull()
		expect(numberBox!.y).toBeLessThan(stripBox!.y)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#status-and-deadline-sit-in-the-header-row
	test('the status tile and the overdue countdown sit in the row', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		// The case carries a status UUID. A tile showing the uuid would pass
		// "renders something" and fail the feature.
		const status = tile(page, /^Status$/)
		await expect(status.locator('.cn-kpi-card__value')).toHaveText(statusName, {
			timeout: 30_000,
		})
		await expect(status).not.toContainText(
			/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/,
		)

		// A start date in early 2024 puts the computed deadline behind us
		// whatever term the case type carries, so the countdown reads overdue
		// and paints in the danger band.
		const countdown = page.locator('.cn-countdown-widget')
		await expect(countdown).toHaveCount(1)
		await expect(countdown).toBeVisible({ timeout: 20_000 })
		await expect(countdown).toHaveClass(/cn-countdown-widget--(danger|error)/)
		await expect(countdown).toContainText(/\d+ (days?|dagen?)/)

		// The deadline is the countdown tile and nothing else: no second tile
		// labelled Time left, which is the duplication the earlier fold retired.
		await expect(page.getByText(/^(Time left|Resterende tijd)$/)).toHaveCount(0)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#a-case-without-a-status-or-a-deadline-still-has-a-header
	test('a case with no status and no deadline still has its tiles', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${bareCaseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		// The tile is there, and it names nothing it does not know: no uuid, and
		// no name the record does not carry. An absent tile and an unset status
		// look identical, and only one of the two is a data problem.
		const status = tile(page, /^Status$/)
		await expect(status).toBeVisible({ timeout: 30_000 })
		await expect(status).not.toContainText(
			/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/,
		)
		// And no count of days at all. "0 days left" would be a claim this case
		// has not made.
		const countdown = page.locator('.cn-countdown-widget')
		await expect(countdown).toHaveCount(1)
		await expect(countdown).not.toContainText(/\d+ (days?|dagen?)/)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#no-trail-is-rendered
	test('renders no breadcrumb trail above the case title', async ({ page }) => {
		// The trail's LAST crumb was the case title, one line under the page
		// header that already printed it: `Cases > Dakkapel Kerkstraat 12`
		// directly below `Dakkapel Kerkstraat 12`. A repeat, not a location, and
		// it cost the top of the page a row.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(tile(page, /^(Case number|Zaaknummer)$/)).toBeVisible({
			timeout: 30_000,
		})

		await expect(page.getByTestId('case-header-breadcrumbs')).toHaveCount(0)

		// 🔑 THE TRAIL'S SIGNATURE, NOT A GLOBAL COUNT OF THE TITLE.
		//
		// This asserted `getByText(caseTitle, { exact: true })` had count 1, on
		// the reasoning that the title should now be stated once. That is a
		// claim about the WHOLE PAGE, and the whole page is not this test's
		// business: `CnObjectSidebar` renders `:name="sidebarTitle"`, so an open
		// sidebar prints the case title a second time and the count is 2 with
		// the breadcrumb correctly absent. The assertion would have failed for a
		// reason that has nothing to do with the breadcrumb.
		//
		// What the removed trail actually contributed was a node carrying BOTH
		// the title and `aria-current="page"` — CnBreadcrumbs marks its last
		// crumb that way. Nothing else on the page does. Asserting that exact
		// pair is gone is the regression, and it cannot be confounded by a
		// sidebar, a tab panel or the browser tab title.
		await expect(
			page.locator('[aria-current="page"]').filter({ hasText: caseTitle }),
		).toHaveCount(0)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-strip-holds-six-tabs-and-no-more
	test('the work tabs ARE the strip, in order, with nothing after them', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		const labels = (await strip.getByRole('tab').allInnerTexts()).map((l) =>
			l.trim(),
		)

		// An exact list, not a prefix. The old shape of this test asserted the
		// work tabs came FIRST and the conditional four came after them, which
		// said nothing at all about the eight tabs that could sit between them,
		// and eight is roughly what accumulated.
		expect(labels).toEqual(WORK_TABS)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-six-tabs-fit-a-laptop-screen
	test('every work tab is reachable at 1024, with the strip above the fold', async ({
		page,
	}) => {
		// Row A33 asked for one line. It cannot be had: measured 2026-09-09, six
		// tabs need 661px on one line and this strip's tab row has about 280, and
		// even a full-width strip yields roughly 570. `CnTabs` wraps rather than
		// scrolls on purpose, because a scrolling strip hides tabs behind an edge
		// with nothing to say they are there. So the thing worth guarding is the
		// one the handler actually loses when this breaks: a tab that is clipped,
		// off-screen, or below the fold.
		await page.setViewportSize({ width: 1024, height: 768 })
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })

		const stripBox = await strip.boundingBox()
		expect(stripBox, 'the strip must have a box').not.toBeNull()
		expect(
			stripBox!.y,
			`the tab strip starts at y=${stripBox!.y}, below a 768px fold`,
		).toBeLessThan(768)

		const boxes = await strip.getByRole('tab').evaluateAll(
			(tabs, count) =>
				tabs.slice(0, count).map((tab) => {
					const box = (tab as HTMLElement).getBoundingClientRect()
					return {
						left: box.left,
						right: box.right,
						width: box.width,
						height: box.height,
					}
				}),
			WORK_TABS.length,
		)

		// A strip that rendered no tabs at all would otherwise pass every
		// assertion below by having nothing to assert on.
		expect(boxes, 'every work tab must be on the strip').toHaveLength(
			WORK_TABS.length,
		)

		boxes.forEach((box, index) => {
			const tab = WORK_TABS[index]
			expect(box.width, `${tab} has no width`).toBeGreaterThan(0)
			expect(box.height, `${tab} has no height`).toBeGreaterThan(0)
			expect(
				box.left,
				`${tab} starts at x=${box.left}, off the left edge`,
			).toBeGreaterThanOrEqual(0)
			expect(
				box.right,
				`${tab} ends at x=${box.right}, past the 1024px viewport`,
			).toBeLessThanOrEqual(1024)
		})
	})
})
