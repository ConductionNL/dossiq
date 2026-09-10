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
import { dismissSupportDialog } from './helpers/nav.ts'

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
	test('the case number, type, status and assignee read under the title', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await dismissSupportDialog(page)

		const header = page.getByTestId('case-header')
		await expect(header).toBeVisible({ timeout: 30_000 })

		await expect(page.getByTestId('case-header-identifier')).toHaveText(
			caseIdentifier,
			{ timeout: 20_000 },
		)
		await expect(page.getByTestId('case-header-casetype')).toHaveText(
			caseTypeTitle,
			{ timeout: 20_000 },
		)
		await expect(page.getByTestId('case-header-assignee')).toHaveText('admin')

		// The row sits ABOVE the tab strip: an identity a reader has to scroll
		// to is the state this row replaced.
		const headerBox = await header.boundingBox()
		const stripBox = await page.locator('.cn-tabs-widget').boundingBox()
		expect(headerBox, 'the identity row must have a box').not.toBeNull()
		expect(stripBox, 'the tab strip must have a box').not.toBeNull()
		expect(headerBox!.y).toBeLessThan(stripBox!.y)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#status-and-deadline-sit-in-the-header-row
	test('the status badge and the overdue countdown sit in the row', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await dismissSupportDialog(page)
		await expect(page.getByTestId('case-header')).toBeVisible({
			timeout: 30_000,
		})

		// The case carries a status UUID. A badge showing the uuid would pass
		// "renders something" and fail the feature.
		const badge = page.getByTestId('case-header-status')
		await expect(badge).toHaveText(statusName, { timeout: 20_000 })
		await expect(badge).not.toContainText(
			/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/,
		)

		// A start date in early 2024 puts the computed deadline behind us
		// whatever term the case type carries, so the countdown reads overdue
		// and paints in the danger band.
		const countdown = page.getByTestId('case-header-countdown')
		await expect(countdown).toBeVisible({ timeout: 20_000 })
		await expect(countdown).toHaveClass(/is-danger/)
		await expect(countdown).toContainText(/\d+ (days?|dagen?)/)

		// And the tile the row replaced is gone from the page. Asserting only
		// that the row shows the deadline would still pass if the tile had
		// stayed, which is the duplication this fold retires.
		await expect(page.locator('.cn-countdown-widget')).toHaveCount(0)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#a-case-without-a-status-or-a-deadline-still-has-a-header
	test('a case with no status and no deadline still has a header', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${bareCaseId}`)
		await dismissSupportDialog(page)

		const header = page.getByTestId('case-header')
		await expect(header).toBeVisible({ timeout: 30_000 })

		// Unknown, not nothing: an absent badge and an unset status look
		// identical, and only one of the two is a data problem.
		// `\s*` on both sides: CnStatusBadge contributes a leading space, and
		// `toHaveText` compares the element's WHOLE text, so a bare anchor
		// fails on a correct badge — the run reports `" Unknown"` against
		// `/^(Unknown|Onbekend)$/`. Same trap decidiq hit on its status chips.
		await expect(page.getByTestId('case-header-status')).toHaveText(
			/^\s*(Unknown|Onbekend)\s*$/,
			{ timeout: 20_000 },
		)
		// And no countdown at all. "0 days left" would be a claim this case
		// has not made.
		await expect(page.getByTestId('case-header-countdown')).toHaveCount(0)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#no-trail-is-rendered
	test('renders no breadcrumb trail, and the title appears once', async ({
		page,
	}) => {
		// The trail's LAST crumb was the case title, one line under the page
		// header that already printed it: `Cases > Dakkapel Kerkstraat 12`
		// directly below `Dakkapel Kerkstraat 12`. A repeat, not a location, and
		// it cost the top of the page a row.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await dismissSupportDialog(page)
		await expect(page.getByTestId('case-header')).toBeVisible({
			timeout: 30_000,
		})

		await expect(page.getByTestId('case-header-breadcrumbs')).toHaveCount(0)

		// The point of removing it: the title is stated once above the fold. An
		// exact-text locator, because the case title is also a substring of the
		// browser tab title and of the sidebar heading.
		await expect(page.getByText(caseTitle, { exact: true })).toHaveCount(1)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-strip-holds-six-tabs-and-no-more
	test('the work tabs ARE the strip, in order, with nothing after them', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
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
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
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
