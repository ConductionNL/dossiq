/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One history of the case, in the sidebar (placement row A05).
 *
 * The case used to carry its history twice: a History tab over
 * OpenRegister's audit trail, and a Version history tab rendering the same
 * rows as a field diff. The second one is gone from this page. What is left
 * has to actually work, and the two things a unit test cannot ask are here:
 *
 *  - the tab set the SIDEBAR renders, which is a manifest declaration turned
 *    into NcAppSidebarTab buttons by a library this repo does not own;
 *  - whether the Action filter narrows the list, which needs a real audit
 *    trail on a real object and a request that comes back.
 *
 * Tabs are addressed by ID, not by label: NcAppSidebarTab renders
 * `#tab-button-<id>` for the manifest tab id, which reads the same on an
 * English and a Dutch instance. A spec that asserted labels here would pass
 * or fail on the instance's locale rather than on the feature.
 */

import { expect, test } from '@playwright/test'
import {
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

/**
 * Open the case page and its sidebar.
 *
 * @param page   The Playwright page.
 * @param caseId The case to open.
 * @return The sidebar locator, visible.
 */
async function openSidebar(page: any, caseId: string) {
	await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
	await dismissSupportDialog(page)
	await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

	// NcAppSidebar renders its own toggle while closed; open it when so.
	const toggle = page.locator('.app-sidebar__toggle')
	if (await toggle.isVisible()) await toggle.click()
	const sidebar = page.locator('.app-sidebar')
	await expect(sidebar).toBeVisible({ timeout: 15_000 })
	return sidebar
}

test.describe('Case timeline — one history, in the sidebar', () => {
	test.setTimeout(180_000)

	let caseId = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const machine = await seedStateMachine(api, token)
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} Zaak met geschiedenis`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		caseId = objectId(seeded)

		// One WRITE after the create, so the trail holds two rows in a known
		// order and the newest is an update rather than the create itself.
		await updateObject(api, token, 'case', caseId, {
			description: `${RUN_PREFIX} description changed by the e2e layer`,
		})

		await api.dispose()
	})

	// No afterAll. The `case` schema is archival, so a user-driven DELETE is
	// refused with 403 by design, and removing the case TYPE would leave the
	// undeletable case pointing at nothing.

	// @e2e openspec/changes/case-timeline/specs/case-dashboard-view/spec.md#one-history-tab-in-the-sidebar
	test('the sidebar offers History and no version history', async ({ page }) => {
		const sidebar = await openSidebar(page, caseId)

		// Both halves. `audit` alone passes if the version-history tab is still
		// there beside it, which is the state this change exists to end.
		await expect(sidebar.locator('#tab-button-audit')).toBeVisible({
			timeout: 15_000,
		})
		await expect(sidebar.locator('#tab-button-version-history')).toHaveCount(0)
	})

	// @e2e openspec/changes/case-timeline/specs/case-dashboard-view/spec.md#the-newest-write-reads-first-with-its-actor
	test('the newest write reads first, with its actor', async ({ page }) => {
		const sidebar = await openSidebar(page, caseId)
		await sidebar.locator('#tab-button-audit').click()

		const rows = sidebar.locator('.cn-audit-entry')
		await expect(rows.first()).toBeVisible({ timeout: 20_000 })

		// The claim is ORDER: the newest write sits above the older one, and it
		// carries its actor. It used to be written as "row 0 says update", and
		// that form could not pass however well the product worked, because
		// OPENING THE PAGE IS ITSELF AN AUDITED EVENT. OpenRegister logs a
		// `read` on every load, so by the time this test can look at the list,
		// the newest row is the read its own navigation just made. CI reported
		// exactly that: `Sep 9, 2026, 07:34 AM` / `admin` / `read`.
		//
		// The reads are not noise to be filtered away at the source. An
		// inzage-log is what a zaaksysteem owes the person the case is about
		// (AVG art. 15), so the tab keeps them and offers the Action filter to
		// narrow — which the third test in this file covers. What changed here
		// is the assertion, not the timeline: it now reads the order of the
		// WRITES within the list instead of demanding that no read be newer
		// than them.
		//
		// It still guards the defect it was written for. Until openregister
		// #3540 (merged into openregister@development on 2026-09-08, the ref
		// CI installs) `CnAuditTrailTab` asked for `_sort[created]=DESC` and
		// the mapper dropped it — the loop building the ORDER BY assigned its
		// `ASC` default over the value it was about to test — so every trail
		// came back oldest-first. Under that defect the create is ABOVE the
		// update and the comparison below is red. Measured: on a dev instance
		// still running the pre-#3540 build this test fails with
		// "the create sits above the update", and on #3540 it passes.
		await expect.poll(() => rows.count(), { timeout: 20_000 }).toBeGreaterThan(1)
		const texts = await rows.allInnerTexts()
		const seen = JSON.stringify(texts)

		const updateAt = texts.findIndex((row) => /update/i.test(row))
		const createAt = texts.findIndex((row) => /create/i.test(row))
		expect(updateAt, `no update row in the trail: ${seen}`).toBeGreaterThan(-1)
		expect(createAt, `no create row in the trail: ${seen}`).toBeGreaterThan(-1)

		// Newest-first: the update this spec made after the create has to read
		// above it. Bottom-up ordering inverts exactly this pair.
		expect(
			updateAt,
			`the create sits above the update, so the trail is bottom-up: ${seen}`,
		).toBeLessThan(createAt)

		// And the write names who made it.
		expect(
			texts[updateAt],
			`the newest write does not name its actor: ${texts[updateAt]}`,
		).toContain('admin')
	})

	// @e2e openspec/changes/case-timeline/specs/case-dashboard-view/spec.md#the-action-filter-narrows-to-updates
	test('the Action filter narrows the list and never sits on Loading', async ({
		page,
	}) => {
		const sidebar = await openSidebar(page, caseId)
		await sidebar.locator('#tab-button-audit').click()

		const rows = sidebar.locator('.cn-audit-entry')
		await expect(rows.first()).toBeVisible({ timeout: 20_000 })
		const before = await rows.count()

		// The Action select is the FIRST of the tab's two, addressed by
		// position rather than by its input label, which nextcloud-vue
		// translates through its own catalogue and which therefore reads Dutch
		// on a Dutch instance.
		const actionSelect = sidebar.locator('.cn-audit-filters__select').first()
		await expect(actionSelect).toBeVisible({ timeout: 15_000 })
		await actionSelect.click()

		// NcEllipsisedOption splits an option's label across elements, so match
		// the option by its TEXT rather than by an accessible name that the
		// split has already broken.
		const option = page
			.locator('[role="option"], .vs__dropdown-option')
			.filter({ hasText: /^\s*update\s*$/i })
			.first()
		await expect(option).toBeVisible({ timeout: 15_000 })

		// The options are a static list on the component, so the filter cannot
		// be waiting on a request. Assert that out loud: the baseline reported
		// both filters stuck on Loading, and this is the assertion that would
		// fail if they were.
		//
		// Read the SPINNER, not the container's text. vue-select renders
		// `<div class="vs__spinner">Loading...</div>` into every select and
		// hides it with `display: none` until it is loading, and Playwright's
		// `toContainText` reads `textContent`, which includes hidden nodes. So
		// `not.toContainText(/Loading/)` failed on a filter that had rendered
		// its four options and was waiting on nothing — measured on a running
		// instance: `display: none`, height 0, text " Loading... ".
		await expect(actionSelect.locator('.vs__spinner')).toBeHidden()

		await option.click()

		// Poll until the list has SETTLED, on a condition the empty state
		// cannot satisfy.
		//
		// `.toBeLessThan(before)` on its own could not do that, and reported a
		// working filter as a broken one. `CnAuditTrailTab.resetAndFetch()`
		// assigns `entries = []` BEFORE it issues the request, so the moment
		// the filter changes the rendered count is zero — and zero is less
		// than `before`, so the poll succeeded on the widget's own reset
		// rather than on its answer. The very next line then read the rows
		// while the request was still in flight and found none, failing with
		// `rows after filtering: 0`.
		//
		// The filter itself is fine. Measured against the API on a case seeded
		// the way this spec seeds one, a create plus one update:
		// `?action=update` on the audit-trails endpoint returns the update
		// rows and nothing else.
		//
		// So require non-empty AND all-updates together. Neither the reset nor
		// a filter that did nothing can satisfy that pair: the reset fails the
		// first half, and an unfiltered trail carrying reads fails the second.
		await expect
			.poll(
				async () => {
					const rendered = await rows.allInnerTexts()
					return (
						rendered.length > 0
						&& rendered.every((t) => /update/i.test(t))
					)
				},
				{
					timeout: 20_000,
					message: 'the filtered list never settled on updates only',
				},
			)
			.toBe(true)

		// And it is NARROWER than it was. Asserted after the list has settled,
		// so this is a claim about the filtered answer rather than about the
		// gap before it arrived.
		const texts = await rows.allInnerTexts()
		expect(texts.length, `rows after filtering: ${texts.length}`).toBeLessThan(
			before,
		)
	})
})
