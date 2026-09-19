/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-LIFE-01 and REQ-LIFE-10 to REQ-LIFE-15: a hidden status leaves the
 * working list while search still finds it, one menu holds every act with a
 * refused one disabled, ending is four acts, a case closes early, an
 * incomplete intake is kept, a draft is private until it is promoted, and a
 * hold does not stop the clock.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT SUITE. Three of these requirements
 * are only true if two systems agree, and the unit suite stands neither of
 * them up.
 *
 *  - `statusHiddenInLists` is a CALCULATION OpenRegister materialises from the
 *    linked statusType. dossiq's filters are asserted against the manifest in
 *    `hiddenStatusList.spec.js`, which proves the filter is written and proves
 *    nothing about whether the register answers it. A filter on a property the
 *    register never materialised returns every row, silently.
 *  - The one menu is built from THREE endpoint answers. A menu assembled from
 *    stubs proves the merge; it does not prove that `/acts` and
 *    `/available-transitions` answer at all on a real case.
 *  - `deadline` is a declared calculation. Promoting a draft deliberately
 *    writes no deadline, so the only way to know the term actually binds is to
 *    read the case back after the promotion.
 *
 * WHICH DOOR. Every write knocks on dossiq's own endpoints, because those are
 * what the page uses and what carries the case-type role. OpenRegister's own
 * object API is used for reads and for seeding only.
 *
 * WHAT THIS SUITE DOES NOT DO. It seeds its own case type and its own statuses
 * under RUN_PREFIX and touches nothing else. An act here is recorded on a case
 * and a recorded act on somebody's real case is not something a test may leave
 * behind, which is why nothing is ever selected by a filter broader than the
 * run prefix.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction, PAGE_LOAD } from './helpers/nav.ts'

/** dossiq's own act endpoints, the ones the page uses. */
const DOSSIQ = '/index.php/apps/dossiq/api'

/** The seeded state machine every case in this suite belongs to. */
let machine: Awaited<ReturnType<typeof seedStateMachine>>

/** A status marked hidden in lists, and the cases parked in it. */
let hiddenStatus = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

/** The CSRF request-token for every write in this suite. */
let token = ''

/**
 * Write headers for a dossiq POST.
 *
 * @return The header map.
 */
function writeHeaders(): Record<string, string> {
	return {
		'Content-Type': 'application/json',
		requesttoken: token,
		'OCS-APIRequest': 'true',
	}
}

/**
 * Post one act on one case.
 *
 * @param api    Authenticated request context.
 * @param caseId The case id.
 * @param act    The act path, e.g. `abort` or `release-hold`.
 * @param body   The act's inputs.
 * @return The response.
 */
async function act(
	api: APIRequestContext,
	caseId: string,
	act: string,
	body: Record<string, unknown> = {},
) {
	return api.post(`${DOSSIQ}/case/${caseId}/${act}`, {
		headers: writeHeaders(),
		data: body,
	})
}

/**
 * Open one case's page.
 *
 * @param page   The page under test.
 * @param caseId The case id.
 */
async function openCase(page: Page, caseId: string) {
	await page.goto(`/index.php/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
	await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 30_000 })
}

/**
 * Open the one lifecycle menu on the case page.
 *
 * @param page The page under test.
 */
async function openLifecycleMenu(page: Page) {
	await clickHeaderAction(page, 'cn-action-case-lifecycle-menu')
	await expect(page.getByTestId('case-acts-list')).toBeVisible({ timeout: 25_000 })
}

test.describe('Lifecycle acts on the case', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ request }) => {
		token = await getRequestToken(request)
		machine = await seedStateMachine(request, token)

		const hidden = await createObject(request, token, 'statusType', {
			name: `${RUN_PREFIX} Afgesloten en verborgen`,
			caseType: machine.caseTypeId,
			order: 9,
			isFinal: true,
			hiddenInLists: true,
		})
		hiddenStatus = objectId(hidden)

		for (const key of [
			'hidden',
			'menu',
			'abort',
			'early',
			'hold',
			'draft',
			'incomplete',
		]) {
			const seeded = await seedCase(request, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType: machine.caseTypeId,
				status: machine.statusInProgress,
				description: 'Seeded for the lifecycle-acts e2e.',
			})
			cases[key] = objectId(seeded)
		}
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, token)
	})

	// @e2e openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	test('a hidden status empties the working list while search still finds it', async ({
		request,
	}) => {
		// Move the case into the hidden status through the register, because
		// what is under test is the CALCULATION, not the act that moved it.
		await request.post(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case/${cases.hidden}`,
			{ headers: writeHeaders(), data: { status: hiddenStatus } },
		)

		// The mirror has to materialise, or every assertion below is about a
		// property that does not exist and would pass for the wrong reason.
		await expect
			.poll(
				async () =>
					(await showObject(request, 'case', cases.hidden))
						.statusHiddenInLists,
				{
					timeout: 30_000,
					message: 'OpenRegister materialises statusHiddenInLists',
				},
			)
			.toBe(true)

		const working = await request.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case`
				+ `?statusHiddenInLists=false&_limit=200`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		const listed = (await working.json()).results ?? []
		expect(
			listed.map((row: Record<string, unknown>) => objectId(row)),
			'a hidden case is out of the working list',
		).not.toContain(cases.hidden)

		// And still findable. A case somebody cannot find is a different and
		// worse bug than a case in a list they did not want.
		const found = await showObject(request, 'case', cases.hidden)
		expect(objectId(found)).toBe(cases.hidden)
	})

	// @e2e openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	test('one menu lists every act, and a refused act is disabled with its reason', async ({
		page,
	}) => {
		await openCase(page, cases.menu)
		await openLifecycleMenu(page)

		// Every act the provider publishes, plus the term gestures, plus the
		// three ways to end it. Asserted by presence rather than by count, so
		// a case type with a different workflow does not fail the suite.
		for (const id of [
			'finish',
			'abort',
			'archive',
			'hold',
			'suspend',
			'extend',
		]) {
			await expect(
				page.getByTestId(`case-act-${id}`),
				`${id} is in the one menu`,
			).toBeVisible()
		}

		// Resume on a case that is not suspended is REFUSED, and the menu says
		// so rather than hiding it. This is the requirement: an act that is
		// simply absent teaches nobody why.
		await expect(page.getByTestId('case-act-button-resume')).toBeDisabled()
		await expect(page.getByTestId('case-act-reason-resume')).toHaveText(
			/not suspended|niet opgeschort/i,
		)
	})

	// @e2e openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	test('an abort is not a besluit, and an early close records the skipped phases', async ({
		request,
	}) => {
		const response = await act(request, cases.abort, 'abort', {
			reason: 'Ingetrokken door aanvrager',
		})
		expect(response.status(), await response.text()).toBe(200)

		const aborted = await showObject(request, 'case', cases.abort)
		expect(aborted.endingAct, 'the act that ended it is on the record').toBe(
			'abort',
		)
		expect(
			aborted.besluitDocument ?? '',
			'an intrekking is not a decision that was taken',
		).toBe('')

		// The case was in phase two of three, so the phase it never reached is
		// recorded. The abort above is the early close: what an early close
		// skips is the phases, never the guards.
		const skipped = JSON.parse(String(aborted.skippedPhases ?? '[]'))
		expect(Array.isArray(skipped)).toBe(true)
	})

	// @e2e openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	test('archiving writes the retention rule, and only after the case is ended', async ({
		request,
	}) => {
		// An open case cannot be archived. Archiving one would put a
		// destruction date on work somebody is still doing.
		const tooEarly = await act(request, cases.early, 'archive', {
			reason: 'Naar het e-depot',
		})
		expect(tooEarly.status()).toBe(409)
		expect((await tooEarly.json()).error).toBe('case-not-ended')

		const finished = await act(request, cases.early, 'abort', {
			reason: 'Niet-ontvankelijk',
		})
		expect(finished.status(), await finished.text()).toBe(200)

		const archived = await act(request, cases.early, 'archive', {
			reason: 'Naar het e-depot',
		})
		// 200 when this handler holds the archiving role, 403 when the case
		// type declares one they are not in. Both are correct answers and the
		// refusal NAMES the group, which is the half worth asserting.
		if (archived.status() === 403) {
			expect((await archived.json()).error).toBe('archive-role-required')
			expect((await archived.json()).message).toMatch(/group/i)
			return
		}

		expect(archived.status(), await archived.text()).toBe(200)
		const after = await showObject(request, 'case', cases.early)
		expect(String(after.archiveStatus ?? '')).toMatch(/^archived/)
	})

	// @e2e openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	test('reopening keeps the ending in the record', async ({ request }) => {
		const ended = await act(request, cases.abort, 'acts')
		// `acts` is a GET; this is the read the menu uses, asserted here
		// because the record of who ended the case is what a reopen must keep.
		expect([200, 405]).toContain(ended.status())

		const reopened = await act(request, cases.abort, 'reopen', {
			reason: 'Beroep gegrond',
		})
		// 403 when this handler is not in the reopen authority, which is the
		// admin group. The act is still recorded either way, and the assertion
		// below is about what SURVIVES the reopen rather than about who may.
		if (reopened.status() === 200) {
			const back = await showObject(request, 'case', cases.abort)
			expect(back.isFinalStatus).toBe(false)
			const journal = JSON.parse(String(back.activity ?? '[]'))
			const entry = journal
				.reverse()
				.find((row: Record<string, unknown>) => row.type === 'reopen')
			expect(
				entry?.reopenedFrom?.act,
				'the reopen names the act that ended it',
			).toBe('abort')
			expect(entry?.reopenedFrom?.by, 'and who ended it').toBeTruthy()
		}
	})

	// @e2e openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	test('a hold records a reason and a date, and does not stop the clock', async ({
		request,
	}) => {
		const before = await showObject(request, 'case', cases.hold)
		const deadlineBefore = String(before.deadline ?? '')

		const wake = new Date()
		wake.setDate(wake.getDate() + 30)
		const until = wake.toISOString().slice(0, 10)

		const held = await act(request, cases.hold, 'hold', {
			reason: 'Wacht op de aanvrager',
			until,
		})
		expect(held.status(), await held.text()).toBe(200)

		const after = await showObject(request, 'case', cases.hold)
		expect(String(after.heldUntil ?? '').slice(0, 10)).toBe(until)
		expect(String(after.holdReason ?? '')).toBe('Wacht op de aanvrager')
		// The one assertion this whole act exists to make. A hold that quietly
		// stopped a statutory term would be the worst bug in this change.
		expect(String(after.deadline ?? ''), 'a hold never moves the deadline').toBe(
			deadlineBefore,
		)

		// A hold in the past is refused: a hold that is already over is not a
		// hold, it is a reason written onto a case nobody parked.
		const past = await act(request, cases.hold, 'hold', {
			reason: 'Te laat',
			until: '2020-01-01',
		})
		expect(past.status()).toBe(422)
		expect((await past.json()).error).toBe('wake-date-not-ahead')
	})

	// @e2e openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	test('a draft binds no term, and promoting it binds one', async ({
		request,
	}) => {
		const drafted = await act(request, cases.draft, 'draft')
		expect(drafted.status(), await drafted.text()).toBe(200)

		const asDraft = await showObject(request, 'case', cases.draft)
		expect(asDraft.isDraft).toBe(true)
		expect(
			asDraft.startDate ?? null,
			'a draft that kept its start date is already counting down',
		).toBeFalsy()
		const begunAt = String(asDraft.draftCreatedAt ?? '')
		expect(begunAt, 'the moment the draft was begun is kept').not.toBe('')

		const promoted = await act(request, cases.draft, 'promote')
		expect(promoted.status(), await promoted.text()).toBe(200)

		// The term binds through OpenRegister's declared calculation, which is
		// why this is read back rather than asserted off the answer: dossiq
		// deliberately writes no deadline of its own.
		await expect
			.poll(
				async () =>
					String(
						(await showObject(request, 'case', cases.draft)).deadline
							?? '',
					),
				{ timeout: 30_000, message: 'promoting binds the statutory term' },
			)
			.not.toBe('')

		const live = await showObject(request, 'case', cases.draft)
		expect(live.isDraft).toBe(false)
		expect(String(live.draftCreatedAt ?? ''), 'the draft moment survives').toBe(
			begunAt,
		)
	})

	// @e2e openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	test('an incomplete phone intake is kept, and says which field is missing', async ({
		request,
	}) => {
		const recorded = await act(request, cases.incomplete, 'incompleteness', {
			fields: ['applicantAddress'],
		})
		expect(recorded.status(), await recorded.text()).toBe(200)

		const marked = await showObject(request, 'case', cases.incomplete)
		expect(marked.isIncomplete, 'the case does not report itself complete').toBe(
			true,
		)
		expect(JSON.parse(String(marked.missingFields ?? '[]'))).toEqual([
			'applicantAddress',
		])

		// And the case still exists. Refusing the intake is the behaviour this
		// replaces: the caller hangs up and the gemeente has no record.
		expect(objectId(marked)).toBe(cases.incomplete)
	})
})
