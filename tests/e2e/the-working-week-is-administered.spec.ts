/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every working-day answer in this app comes from the administered calendar.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT TEST. The unit suite proves the
 * decision: handed a calendar reader, `WorkingDayCalculator` asks it. What it
 * cannot prove is that the container ever injects one. The reader is an
 * optional constructor argument, deliberately, so that an instance without
 * OpenRegister still computes dates. That same default means a container that
 * fails to wire it leaves every unit assertion green while every deadline in
 * production goes on being computed from a list nobody administers.
 *
 * 🔑 THE FIXTURE DATE IS THE ONE THE TWO LISTS DISAGREE ABOUT. Both the
 * administered `nl-national` calendar and the built-in Dutch list skip
 * Nieuwjaarsdag and both Kerstdagen, so a term across either proves nothing:
 * the answer is the same whichever list decided. They differ on ONE day.
 * Dossiq's built-in list holds 5 May, Bevrijdingsdag, and the seeded calendar
 * does not, because most Dutch employers do not close on it every year and
 * the organisation that does says so on its own calendar.
 *
 * So a complaint received on Monday 3 May 2027 has its Awb acknowledgement
 * term five working days later, and the two lists answer different days:
 * Monday 10 May from the administered calendar, Tuesday 11 May from the
 * built-in list. One assertion, and it can only pass one way.
 *
 * NOTHING HERE WRITES TO A CALENDAR. The openregister suite seeds its own
 * calendar rather than editing `nl-national`, which every other suite reads,
 * and this spec keeps to that by choosing a date that discriminates without
 * any seeding at all.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { getRequestToken, REGISTER, RUN_PREFIX } from './helpers/fixtures.ts'

/** The app's own API, which is the door a handler's action goes through. */
const APP_API = `/index.php/apps/${REGISTER}`

/** Monday, and the Wednesday of that week is 5 May. */
const RECEIVED = '2027-05-03'

/** Five working days on, with 5 May worked. */
const ADMINISTERED_DEADLINE = '2027-05-10'

/** Five working days on, with 5 May treated as a closure. */
const BUILT_IN_DEADLINE = '2027-05-11'

test.describe('The working week is the administered one', () => {
	test.setTimeout(180_000)

	let api: APIRequestContext
	let token = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		await api.dispose()
	})

	// @e2e openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md#every-working-day-answer-reads-the-administered-calendar
	test('a complaint term counts 5 May, because the calendar does', async () => {
		const response = await api.post(`${APP_API}/api/complaints`, {
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
			data: {
				complainant: 'admin',
				subject: `${RUN_PREFIX} werkweek`,
				summary: `${RUN_PREFIX} werkweek`,
				description:
					'A complaint whose acknowledgement term crosses Bevrijdingsdag.',
				receiptDate: RECEIVED,
			},
		})

		expect(response.status(), await response.text()).toBeLessThan(300)
		const complaint = await response.json()
		const deadline = String(
			complaint.acknowledgementOfReceiptDeadline ?? '',
		).slice(0, 10)

		expect(
			deadline,
			`the term landed on ${deadline}. ${BUILT_IN_DEADLINE} means the built-in list `
				+ 'decided and the administered calendar was never asked, which is the wiring '
				+ 'this spec exists to catch',
		).toBe(ADMINISTERED_DEADLINE)
	})

	// @e2e openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md#every-working-day-answer-reads-the-administered-calendar
	test('an ordinary user cannot change the calendar everyone is counted against', async ({
		baseURL,
	}) => {
		// The least privileged principal that should be refused. The calendar
		// decides statutory deadlines for every case on the instance, and the
		// working-calendar schema grants create and update to admin alone. A
		// permission asserted only as an admin success is not asserted.
		const asUser = await playwrightRequest.newContext({
			baseURL,
			httpCredentials: {
				username: process.env.NC_USER ?? 'user1',
				password: process.env.NC_USER_PASSWORD ?? 'user1',
			},
			extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
		})

		const refused = await asUser.post(
			'/index.php/apps/openregister/api/objects/flow-timers/working-calendar',
			{
				headers: { 'Content-Type': 'application/json' },
				data: {
					slug: `${RUN_PREFIX.toLowerCase()}-by-a-user`,
					title: 'Written by someone who may not',
					workingWeekdays: [1, 2, 3, 4, 5, 6, 7],
					hoursPerWorkingDay: 8,
				},
			},
		)

		expect(
			refused.status(),
			'an ordinary user writing a working calendar would move every deadline on the instance',
		).toBeGreaterThanOrEqual(400)

		await asUser.dispose()
	})
})
