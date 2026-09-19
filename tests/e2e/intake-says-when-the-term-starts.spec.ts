/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A request filed when the office is shut, and the date the applicant is given.
 *
 * 🔴 THE TWO MOMENTS ARE ASSERTED AGAINST EACH OTHER, NEVER ALONE. A spec that
 * checked "termStartsAt is set" would pass on a case whose clock starts the
 * instant the form was submitted, which is the defect this change exists to
 * end. So every assertion here compares the received moment with the start,
 * and the flag between them.
 *
 * 🔴 THE INSIDE-THE-WEEK CASE IS THE CONTROL. Without it, a stamp that always
 * said "outside working hours" would pass the Sunday scenario and nothing
 * would report it, and every applicant would be told their clock starts
 * tomorrow. The two cases together are what say the calendar is deciding.
 *
 * 🔴 THE FILING MOMENT IS SEEDED, NOT WAITED FOR. A spec that ran only on a
 * Sunday would be green six days a week by never running, which is the same
 * green as passing. `registrationDate` is what the stamp reads, so the run
 * chooses the day rather than the clock choosing it.
 *
 * ⚠️ NOT RUN IN THIS PHASE. The integration branch defers Playwright to the
 * nightly; this spec is written, tagged and left for it. The same scenarios
 * are watched against a fake engine calendar by IntakeTermStartTest and
 * IntakeConfirmationTextTest.
 *
 * @spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

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
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

let api: APIRequestContext
let token: string
let caseTypeId = ''

/** A Sunday evening, and the Monday the week reopens. */
const SUNDAY = '2026-03-08T20:14:00+01:00'

/** A Tuesday morning, inside anybody's working week. */
const TUESDAY = '2026-03-10T10:00:00+01:00'

test.beforeAll(async ({ playwright }) => {
	api = await playwright.request.newContext()
	token = await getRequestToken(api)
	const machine = await seedStateMachine(api, token)
	caseTypeId = machine.caseTypeId
})

test.afterAll(async () => {
	await cleanupRunObjects(api, token)
	await api.dispose()
})

/**
 * File one request at a chosen moment and read the stamped case back.
 *
 * @param registrationDate The moment the submission arrived.
 * @param title A short title, prefixed with this run's marker by seedCase.
 */
async function filedAt(registrationDate: string, title: string) {
	const created = await seedCase(api, token, {
		title: `${RUN_PREFIX} ${title}`,
		caseType: caseTypeId,
		registrationDate,
	})

	// The stamp is written by a listener on the create, so the case is read
	// back rather than asserted from the create response: asserting the
	// response would test what this spec sent, not what the listener wrote.
	return showObject(api, 'case', objectId(created))
}

test.describe('@spec REQ-TERM-040 when the clock starts', () => {
	test('a Sunday filing starts on the first working day', async () => {
		const stamped = await filedAt(SUNDAY, 'sunday filing')

		expect(
			new Date(stamped.receivedAt).toISOString(),
			'What they did is recorded as when they did it.',
		).toBe(new Date(SUNDAY).toISOString())

		expect(
			new Date(stamped.termStartsAt).getTime(),
			'The clock cannot start before the request arrived.',
		).toBeGreaterThan(new Date(SUNDAY).getTime())

		expect(
			stamped.receivedOutsideWorkingHours,
			'Without this flag nothing anywhere says the two moments differ.',
		).toBe(true)
	})

	test('a filing inside the working week starts at once', async () => {
		const stamped = await filedAt(TUESDAY, 'tuesday filing')

		expect(
			new Date(stamped.termStartsAt).toISOString(),
			'The clock started when they pressed send.',
		).toBe(new Date(stamped.receivedAt).toISOString())

		expect(stamped.receivedOutsideWorkingHours).toBe(false)
	})

	test('the stamp does not move afterwards', async () => {
		const stamped = await filedAt(SUNDAY, 'stamp is stable')
		const caseId = objectId(stamped)

		await api.put(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case/${caseId}`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { ...stamped, title: `${RUN_PREFIX} stamp is stable, edited` },
			},
		)

		const again = await showObject(api, 'case', caseId)

		expect(
			again.termStartsAt,
			'A recomputation would quietly agree with whatever the calendar says today.',
		).toBe(stamped.termStartsAt)
		expect(again.receivedAt).toBe(stamped.receivedAt)
	})
})

test.describe('@spec REQ-TERM-041 what the applicant is told', () => {
	test('the case page names when it arrived and when the clock started', async ({
		page,
	}) => {
		const stamped = await filedAt(SUNDAY, 'confirmation says so')

		await page.goto(`/apps/${REGISTER}/cases/${objectId(stamped)}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await page
			.getByRole('tab', { name: /^(Communication|Communicatie)$/ })
			.click()
		const panel = page.locator('[data-testid="case-detail"]')

		// BOTH MOMENTS OR NEITHER. A page showing only the start would read as
		// "your clock starts Monday" with nothing saying why, which is the
		// same confusion in the other direction.
		await expect(panel).toContainText('08-03-2026')
		await expect(panel).toContainText('09-03-2026')
	})

	// THE MAIL BODY IS NOT PROBED HERE, and the reason is mechanical rather
	// than a preference: the ontvangstbevestiging is sent by
	// AcknowledgementDispatchJob, a background job with no HTTP trigger, so
	// there is nothing for a browser to press. Its four facts and its
	// conditional sentence are asserted in
	// tests/Unit/Service/IntakeConfirmationTextTest.php, in both languages.
	// Naming the file is the point: "covered elsewhere" with no address is how
	// seven requirements went untested for eight months in this repository.
})
