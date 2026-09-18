/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-DT-30: the process-mining report counts working hours, says which clock
 * it counted them on, and can be read by handler as well as by phase.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT SUITE. The unit suites prove the
 * arithmetic on both clocks and the label for each. Neither can prove the two
 * things below, because both are decided by the running instance.
 *
 *  - WHICH CLOCK THIS INSTANCE IS ACTUALLY ON. `WorkingClock` resolves
 *    OpenRegister's calculator by name, so the answer depends on what is
 *    installed. A report that silently fell back would serve plausible numbers
 *    and no error, which is the whole failure this change is about. So the
 *    first assertion is that the payload NAMES a clock, and that the name is
 *    one of the three the page knows how to label. An older dossiq answers no
 *    `clock` key at all and fails here rather than passing vacuously.
 *  - THE TWO NUMBERS ARE BOTH ON THE ROW. A server that computed working
 *    hours and dropped them on the way out renders the wall clock under a
 *    working-hours header, and every screenshot of it looks right.
 *
 * THE LEAST PRIVILEGED PRINCIPAL. The report is an organisation-wide view of
 * how long every case took and who held it, which is exactly the kind of page
 * that must not answer a request with no session. That probe runs from a
 * context that never signed in.
 *
 * WHAT THIS SUITE DOES NOT DO. It seeds nothing and deletes nothing. The
 * report is a read over whatever the instance holds, so a fixture would only
 * add residue to somebody's case load without making any assertion here
 * stronger.
 *
 * @spec openspec/changes/dwell-time-on-the-working-calendar/specs/doorlooptijd-dashboard/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

/** The report endpoint. */
const REPORT = '/index.php/apps/dossiq/api/reports/process-mining'

/** The three clocks the page knows how to label. */
const CLOCKS = ['working-calendar', 'working-days-times-eight', 'wall-clock']

test.describe('REQ-DT-30 the report says which clock it counted on', () => {
	test.setTimeout(180_000)

	let api: APIRequestContext
	let anonymous: APIRequestContext

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		anonymous = await playwright.request.newContext({ baseURL })
	})

	test.afterAll(async () => {
		await api.dispose()
		await anonymous.dispose()
	})

	/**
	 * The report for the widest period the page offers.
	 *
	 * @return The parsed payload.
	 */
	async function report(): Promise<any> {
		const res = await api.get(`${REPORT}?period=all`)
		expect(res.status(), await res.text()).toBe(200)
		return res.json()
	}

	test('the payload names the clock, and names one the page can label', async () => {
		const body = await report()

		expect(
			typeof body?.clock,
			'the report names its clock; a dossiq without this change answers no key and fails here',
		).toBe('string')
		expect(CLOCKS).toContain(body.clock)
	})

	test('every dwell row carries both numbers, not one under two names', async () => {
		const body = await report()
		const rows = (body?.caseTypes ?? []).flatMap(
			(caseType: any) => caseType?.dwellTime ?? [],
		)

		// The control. With no case in the period there is nothing to assert
		// about, and an empty list must not read as a pass.
		test.skip(rows.length === 0, 'no dwell rows in the period on this instance')

		for (const row of rows) {
			expect(typeof row.medianHours).toBe('number')
			expect(typeof row.medianWorkingHours).toBe('number')
			// Working time can never exceed wall-clock time on any of the
			// three clocks, so a row where it does is a mixed-up pair rather
			// than a slow case.
			expect(row.medianWorkingHours).toBeLessThanOrEqual(row.medianHours + 0.05)
		}
	})

	test('the same intervals are readable by handler', async () => {
		const body = await report()
		const caseTypes = body?.caseTypes ?? []

		test.skip(caseTypes.length === 0, 'no case types in the period on this instance')

		for (const caseType of caseTypes) {
			expect(
				Array.isArray(caseType.dwellByAssignee),
				'each case type carries a by-handler grouping, even when it is empty',
			).toBe(true)
			for (const row of caseType.dwellByAssignee) {
				expect(row).toHaveProperty('actor')
				expect(typeof row.medianWorkingHours).toBe('number')
			}
		}
	})

	test('a stranger with no session cannot read the report', async () => {
		const attempted = await anonymous.get(`${REPORT}?period=all`)

		// 401 without a session, 403 when the instance answers that way, 412
		// on the missing request token. A 200 is an organisation's whole case
		// load, with handler names on it, served to nobody in particular.
		expect(
			[401, 403, 412],
			`anonymous read answered ${attempted.status()}: ${await attempted.text()}`,
		).toContain(attempted.status())
	})

	test('the page prints the clock in the column header', async ({ page }) => {
		const body = await report()
		const expected: Record<string, string> = {
			'working-calendar': 'Working hours',
			'working-days-times-eight': 'Working hours (working days x 8)',
			'wall-clock': 'Hours, wall clock',
		}

		await page.goto('/index.php/apps/dossiq/#/process-mining')
		const table = page.getByTestId('pm-dwell-by-assignee')
		await expect(table).toBeVisible({ timeout: 30_000 })

		// The header is the disclosure, so it is read off the rendered page
		// rather than inferred from the payload: a page that computed the
		// label and then failed to render it looks the same as one that never
		// had it.
		await expect(table.locator('thead')).toContainText(expected[body.clock])
		await expect(table.locator('thead')).toContainText('Wall-clock hours')
	})
})
