/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-TERM-040 and REQ-TERM-041: a case records when it arrived and when its
 * clock starts, and the applicant is told both.
 *
 * 🔴 WHY THIS IS E2E. `IntakeTermStartTest` proves the arithmetic and the
 * sentence; neither can prove the stamp is WRITTEN. The listener fires on
 * `ObjectCreatedEvent` and writes back through OpenRegister, and that seam has
 * failed silently in this repository before: a sibling termijn listener spent
 * months never firing because it read `@self.schemaSlug`, which answers an
 * empty string for every object. A listener that never fires and one whose
 * calendar declines look identical from a unit test — both leave the case
 * unstamped.
 *
 * 🔴 WHAT IT CAN AND CANNOT ASSERT ON A SHARED RIG. Which minutes the
 * organisation works is administered in OpenRegister and this suite does not
 * own that calendar, so it cannot force a Sunday. What it CAN assert is the
 * invariant that holds on any calendar: `receivedAt` is always stamped, and
 * `receivedOutsideWorkingHours` agrees with whether the two moments differ.
 * That is the bug this change exists to prevent — the two disagreeing — and it
 * is checkable without dictating the working week.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
} from './helpers/fixtures.ts'

/** An admin request context, reused across the suite. */
let api: APIRequestContext | null = null

/** The CSRF token for that context. */
let token = ''

/** The seeded case every assertion reads. */
let caseId = ''

test.describe('What a case records about when its clock starts', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const machine = await seedStateMachine(api, token)
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} Aanvraag met een klok`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		caseId = objectId(seeded)
		expect(caseId, 'the fixture case must have an id').not.toBe('')
	})

	test.afterAll(async ({ request }) => {
		await api?.dispose()
		await cleanupRunObjects(request)
	})

	// @e2e openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#scenario-a-filing-inside-the-working-window-starts-at-once
	test('a created case is stamped with when it arrived', async () => {
		// The listener writes after the create event, so the read is polled
		// rather than taken once: a single read racing the write would report
		// the feature missing on a loaded rig.
		await expect
			.poll(
				async () => String((await showObject(api!, 'case', caseId))?.receivedAt ?? ''),
				{ timeout: 30_000, message: 'the case must be stamped with when it arrived' },
			)
			.not.toBe('')

		const stored = await showObject(api!, 'case', caseId)
		expect(
			Number.isNaN(Date.parse(String(stored.receivedAt))),
			`receivedAt must be a real moment, got "${stored.receivedAt}"`,
		).toBe(false)
	})

	// @e2e openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#scenario-a-sunday-filing-starts-on-monday
	test('the flag and the two moments never disagree', async () => {
		const stored = await showObject(api!, 'case', caseId)
		const received = String(stored.receivedAt ?? '')
		const starts = String(stored.termStartsAt ?? '')

		if (starts === '') {
			// An absent start is a legitimate state: the organisation calendar
			// did not answer, and dossiq keeps no second calendar to guess
			// with. What it must NOT do is claim the request was out of hours
			// while naming no start to point at.
			expect(
				Boolean(stored.receivedOutsideWorkingHours),
				'a case with no stored start must not claim it arrived out of hours',
			).toBe(false)
			return
		}

		// 🔴 THE INVARIANT, on whatever calendar this rig administers. The flag
		// is the answer to "do these two moments differ", and a flag that
		// disagrees with its own two moments is the defect this change exists
		// to prevent.
		const differ = Date.parse(starts) !== Date.parse(received)
		expect(Boolean(stored.receivedOutsideWorkingHours)).toBe(differ)

		// And forward only: a clock that started before the request arrived
		// would hand the applicant a deadline earlier than the one they have.
		expect(Date.parse(starts)).toBeGreaterThanOrEqual(Date.parse(received))
	})

	// @e2e openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#scenario-the-citizen-is-told-on-screen
	test('the confirmation names the four, and explains only when it must', async () => {
		const response = await api!.get(
			`/index.php/apps/dossiq/api/case/${encodeURIComponent(caseId)}/intake-confirmation`,
		)
		expect(
			response.ok(),
			`the confirmation must be readable; got ${response.status()}`,
		).toBeTruthy()

		const confirmation = (await response.json())?.confirmation
		expect(confirmation, 'the endpoint must answer a confirmation').toBeTruthy()

		for (const key of ['reference', 'receivedAt', 'termStartsAt', 'deadline']) {
			expect(
				Object.hasOwn(confirmation, key),
				`the confirmation must name ${key}`,
			).toBe(true)
		}

		const stored = await showObject(api!, 'case', caseId)
		// The screen says what the case STORED, never a fresh computation: a
		// calendar that gained a holiday since must not change what the
		// applicant was told.
		expect(confirmation.receivedAt).toBe(String(stored.receivedAt ?? ''))
		expect(confirmation.termStartsAt).toBe(String(stored.termStartsAt ?? ''))

		if (confirmation.outsideWorkingHours === true) {
			expect(confirmation.explanation).not.toBe('')
		} else {
			// 🔴 ABSENT, not merely different. An explanation on every
			// confirmation is one people stop reading.
			expect(confirmation.explanation).toBe('')
		}
	})

	// @e2e openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#scenario-the-stamp-does-not-move-afterwards
	test('the stamp does not move when the case is read again', async () => {
		const first = await showObject(api!, 'case', caseId)
		const again = await showObject(api!, 'case', caseId)

		expect(String(again.receivedAt ?? '')).toBe(String(first.receivedAt ?? ''))
		expect(String(again.termStartsAt ?? '')).toBe(String(first.termStartsAt ?? ''))
	})

	// @e2e openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md#scenario-the-citizen-is-told-on-screen
	test('a case nobody may read does not hand over its dates', async ({
		request,
	}) => {
		// `request` carries no session. The four values say something about a
		// case, so an ungated read would hand them to anyone who can guess a
		// uuid.
		const response = await request.get(
			`/index.php/apps/dossiq/api/case/${encodeURIComponent(caseId)}/intake-confirmation`,
		)

		expect(
			response.status(),
			'an unauthenticated caller must not be given the confirmation',
		).toBeGreaterThanOrEqual(400)
	})
})
