/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case identity row, the breadcrumb and the tab order (placement A01, A33).
 *
 * Everything here is a seam a unit test cannot reach. The manifest spec
 * already pins the declarations; this spec asks whether the page built from
 * them says which case you are on.
 *
 *  - the row turns a stored uuid into a status NAME and a stored date into a
 *    number of days, both of which need a live register;
 *  - the breadcrumb's last crumb is the current page, which is a rendered
 *    ARIA property and not a manifest key;
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

/** The tab order placement row A33 asks for, leading the strip. */
const WORK_TABS = [
	/Data|Gegevens/,
	/Documents|Documenten/,
	/Parties|Betrokkenen/,
	/Tasks|Taken/,
	/Communication|Communicatie/,
]

/** The four conditional tabs, which close the strip until `visibleIf` lands. */
const CONDITIONAL_TABS = [
	/Sub-cases|Deelzaken/,
	/Locations|Locaties/,
	/Appointments|Afspraken/,
	/Decisions|Besluiten|Besluitvorming/,
]

test.describe('Case header — identity, breadcrumb and tab order', () => {
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
		const caseType = await showObject(api, 'caseType', machine.caseTypeId)
		caseTypeTitle = String(caseType.title ?? caseType.name ?? '')

		// A TERM ON THE CASE TYPE, or there is no deadline to count down to.
		// `deadline` is `startDate + caseType.processingDeadline`, materialised
		// by OpenRegister, and `seedStateMachine` declares no term at all — so
		// the case below stored no deadline, the header rendered no countdown,
		// and the scenario failed on an element that was correctly absent.
		// Measured against a running register: with `P56D` and a start of
		// 2024-01-15 the stored deadline is 2024-03-11, which is behind us.
		await updateObject(api, token, 'caseType', machine.caseTypeId, {
			processingDeadline: 'P56D',
		})

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

	// @e2e openspec/changes/case-header/specs/case-dashboard-view/spec.md#the-number-reads-under-the-title
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

	// @e2e openspec/changes/case-header/specs/case-dashboard-view/spec.md#status-and-deadline-sit-in-the-header-row
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

	// @e2e openspec/changes/case-header/specs/case-dashboard-view/spec.md#a-case-without-a-status-or-a-deadline-still-has-a-header
	test('a case with no status and no deadline still has a header', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${bareCaseId}`)
		await dismissSupportDialog(page)

		const header = page.getByTestId('case-header')
		await expect(header).toBeVisible({ timeout: 30_000 })

		// Unknown, not nothing: an absent badge and an unset status look
		// identical, and only one of the two is a data problem.
		//
		// The surrounding whitespace is matched rather than assumed away.
		// `toHaveText` normalises whitespace for a STRING and compares a
		// REGEXP against the raw text, and the badge renders a leading space
		// from its icon slot, so `/^Unknown$/` fails on a badge that reads
		// correctly. Anchored either side all the same, so this still tells
		// Unknown from a badge carrying some other status.
		await expect(page.getByTestId('case-header-status')).toHaveText(
			/^\s*(Unknown|Onbekend)\s*$/,
			{ timeout: 20_000 },
		)
		// And no countdown at all. "0 days left" would be a claim this case
		// has not made.
		await expect(page.getByTestId('case-header-countdown')).toHaveCount(0)
	})

	// @e2e openspec/changes/case-header/specs/case-dashboard-view/spec.md#the-current-crumb-is-not-a-link
	test('the breadcrumb ends on the case, unlinked and marked current', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await dismissSupportDialog(page)
		await expect(page.getByTestId('case-header')).toBeVisible({
			timeout: 30_000,
		})

		const trail = page.getByTestId('case-header-breadcrumbs')
		await expect(trail).toBeVisible({ timeout: 20_000 })

		const current = trail.locator('[aria-current="page"]')
		await expect(current).toHaveCount(1)
		await expect(current).toContainText(caseTitle)
		// The current location is not somewhere to navigate to.
		expect(await current.getAttribute('href')).toBeNull()
	})

	// @e2e openspec/changes/case-header/specs/case-dashboard-view/spec.md#cases-is-one-click-away
	test('the first crumb opens the case list, carrying the query it had', async ({
		page,
	}) => {
		// The Cases lenses are chip state rather than a query parameter today,
		// so there is no `?lens=` to preserve. What IS assertable, and what
		// keeps the crumb honest the day a lens lands on the URL, is that a
		// query on the case route survives the trip back.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}?lens=mine`)
		await dismissSupportDialog(page)
		await expect(page.getByTestId('case-header')).toBeVisible({
			timeout: 30_000,
		})

		const trail = page.getByTestId('case-header-breadcrumbs')
		await trail.getByRole('link').first().click()

		await expect(page).toHaveURL(/\/cases(\?|$)/, { timeout: 20_000 })
		await expect(page).toHaveURL(/lens=mine/)
		// The list, not an empty shell: an unknown subpath answers 200 with the
		// SPA shell, so the URL alone is not evidence the page rendered.
		await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/changes/case-header/specs/case-dashboard-view/spec.md#the-five-work-tabs-come-first-in-order
	test('the work tabs lead the strip and the conditional four close it', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await dismissSupportDialog(page)

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		const labels = await strip.getByRole('tab').allInnerTexts()

		for (const [index, pattern] of WORK_TABS.entries()) {
			expect(labels[index], `tabs: ${labels.join(' | ')}`).toMatch(pattern)
		}

		// The conditional tabs close the strip. Asserted as "after every work
		// tab" rather than "the last four", because `custom-objects-on-the-case`
		// added an Objects tab of the same kind after REQ-CDV-16 was written,
		// and a slice of a fixed length would fail on a correct strip.
		const lastWork = Math.max(
			...WORK_TABS.map((pattern) => labels.findIndex((l) => pattern.test(l))),
		)
		for (const pattern of CONDITIONAL_TABS) {
			const at = labels.findIndex((label) => pattern.test(label))
			expect(
				at,
				`${pattern} is absent: ${labels.join(' | ')}`,
			).toBeGreaterThan(-1)
			expect(
				at,
				`${pattern} sits among the work tabs: ${labels.join(' | ')}`,
			).toBeGreaterThan(lastWork)
		}
	})

	// @e2e openspec/changes/case-header/specs/case-dashboard-view/spec.md#the-work-tabs-fit-a-laptop-screen
	test('the work tabs share one line at 1024, above the fold', async ({
		page,
	}) => {
		// This is the whole of row A33. Ten tabs wrapped onto three lines at
		// 1440 and the strip fell below the fold at 1024, so the tabs a handler
		// works in were the ones they could not see.
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

		// The work tabs share one line: same offsetTop, to the pixel.
		const tops = await strip
			.getByRole('tab')
			.evaluateAll(
				(tabs, count) =>
					tabs
						.slice(0, count)
						.map(
							(tab) =>
								(tab as HTMLElement).getBoundingClientRect().top,
						),
				WORK_TABS.length,
			)
		expect(
			new Set(tops.map((top) => Math.round(top))).size,
			`work-tab tops: ${tops.join(', ')}`,
		).toBe(1)
	})
})
