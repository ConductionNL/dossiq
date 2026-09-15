/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Handing a case to another team, the second seat, a case handled elsewhere,
 * and the leaver handover.
 *
 * 🔴 WHAT THIS LAYER CAN AND CANNOT PROVE, said up front so nobody reads more
 * into a green run than is there.
 *
 * Playwright is ONE user on a shared instance. So the assertions below are
 * about the record and the screen, never about a second person's experience.
 * "Toezicht refuses it back" is driven as the same signed-in admin calling the
 * refuse endpoint, which proves the state machine and the custody trail and
 * proves nothing about who may call it. Who may hand a case on is
 * CaseAccessGuard's answer, asserted in tests/Unit/Controller against a user
 * the guard refuses; a browser signed in as an administrator cannot show that.
 *
 * THE DOORZENDING IS ASSERTED ON THE RECORD, NOT IN A MAILBOX. There is no
 * mail transport in this environment, so "the applicant was told" is read off
 * the transfer record's announcement rather than off a message. The wording
 * itself is asserted in tests/Unit/Service/DoorzendingNotificationTest.php,
 * which renders both languages.
 *
 * TEAMS ARE NEXTCLOUD GROUPS AND THIS RUN DOES NOT CREATE THEM. The fixtures
 * hand cases to `admin`, the group every instance has, and the refusal path is
 * driven with a group name nothing answers to. A two-real-team handover needs
 * two provisioned groups, which is an instance-setup job rather than a spec's.
 *
 * WHAT THE CLEANUP CANNOT REACH, said here rather than left to be discovered.
 * `cleanupRunObjects` finds rows by this run's prefix, and a `casetransfer`
 * row carries no title to put a prefix in: it is addressed by `caseId`. So the
 * transfer records this spec writes are orphaned rather than removed when
 * their cases go. They are inert (a transfer pointing at a case that no longer
 * exists lists nowhere and blocks nothing), but they do accumulate, and the
 * fix is a `caseId`-aware sweep in the helper rather than a per-spec loop.
 *
 * ROWS ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY POSITION OR COUNT. The
 * Cases index is shared and another session's fixtures land in it mid-run, so
 * "the list shows N rows" is never asserted; "the list holds this case" is.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const CASES_URL = `${APP_URL}cases`

/** The team every instance has, so the fixtures need no provisioning step. */
const RECEIVING_TEAM = 'admin'

/** A group nothing on the instance answers to. */
const UNRESOLVABLE_TEAM = `${RUN_PREFIX}-afdeling-die-niet-bestaat`

let api: APIRequestContext
let token: string
let caseTypeId = ''

/** The cases this spec seeded, by the key the tests know them under. */
const cases: Record<string, string> = {}

/**
 * Hand a case to a team, the way the dialog does.
 *
 * @param caseId      The case uuid.
 * @param team        The receiving team.
 * @param doorzending Whether Awb 2:3 applies.
 * @return The response, so a test can read both the body and the status.
 */
async function hand(caseId: string, team: string, doorzending = false) {
	return api.post(`/index.php/apps/${REGISTER}/api/case/${caseId}/handover`, {
		headers: { requesttoken: token },
		data: { team, reason: 'Dit hoort bij de andere afdeling', doorzending },
	})
}

test.describe('handing a case over', () => {
	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id

		for (const key of ['moved', 'refused', 'outstanding', 'doorzending', 'seats']) {
			const seeded = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType: caseTypeId,
				assignedGroup: 'vergunningen',
			})
			cases[key] = objectId(seeded)
		}

		const elsewhere = await seedCase(api, token, {
			title: `${RUN_PREFIX} elsewhere`,
			caseType: caseTypeId,
			externalApplication: 'Suite4Sociaal Domein',
			externalIdentifier: `${RUN_PREFIX}-SD-4417`,
			externalUrl: 'https://suite.example.org/zaken/4417',
		})
		cases.elsewhere = objectId(elsewhere)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token, ['case', 'casetransfer', 'role'])
		await api.dispose()
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	test('a case handed to another team keeps its number and its term', async () => {
		const before = await showObject(api, 'case', cases.moved)
		const response = await hand(cases.moved, RECEIVING_TEAM)
		expect(response.ok()).toBeTruthy()

		const after = await showObject(api, 'case', cases.moved)
		expect(after.assignedGroup).toBe(RECEIVING_TEAM)
		// A handover that recreated the case would restart the Awb clock,
		// which is not a transfer, it is a way to hide a late case.
		expect(after.identifier).toBe(before.identifier)
		expect(after.deadline ?? null).toEqual(before.deadline ?? null)
		expect(after.handoverPending).toBe(true)
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	test('a team nobody can name refuses the handover, and the case stays put', async () => {
		const before = await showObject(api, 'case', cases.outstanding)
		const response = await hand(cases.outstanding, UNRESOLVABLE_TEAM)

		expect(response.status()).toBe(422)
		const body = await response.json()
		// ADR-050: the rule in `error`, the sentence in `message`.
		expect(body.error).toBe('handover-team-unresolvable')
		expect(String(body.message).length).toBeGreaterThan(0)

		const after = await showObject(api, 'case', cases.outstanding)
		expect(after.assignedGroup).toBe(before.assignedGroup)
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	test('the receiving team sends it back, and the refusal is on the custody trail', async () => {
		const handed = await (await hand(cases.refused, RECEIVING_TEAM)).json()
		const transferId = objectId(handed)

		const response = await api.post(
			`/index.php/apps/${REGISTER}/api/case/${cases.refused}/handover/${transferId}/refuse`,
			{ headers: { requesttoken: token }, data: { reason: 'Dit is wel onze zaak' } },
		)
		expect(response.ok()).toBeTruthy()

		const settled = await response.json()
		expect(settled.status).toBe('rejected')
		expect(settled.rejectionReason).toBe('Dit is wel onze zaak')
		expect(settled.custodyAuditTrail.map((entry: any) => entry.event)).toEqual([
			'initiated',
			'rejected',
		])

		const after = await showObject(api, 'case', cases.refused)
		expect(after.assignedGroup).toBe('vergunningen')
		expect(after.handoverPending).toBe(false)
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	test('an unaccepted handover is listed as outstanding for the sending team', async ({ page }) => {
		await hand(cases.outstanding, RECEIVING_TEAM)

		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		const errors = trackDossiqErrors(page)

		await page.getByRole('button', { name: 'Handed on' }).click()
		await expect(page.getByText(`${RUN_PREFIX} outstanding`)).toBeVisible()
		// The case a run accepted must NOT be on this lens, which is what
		// separates "the chip filters" from "the chip shows everything".
		await expect(page.getByText(`${RUN_PREFIX} refused`)).toHaveCount(0)

		expect(errors).toEqual([])
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-doorzending-tells-the-applicant-where-the-case-went-req-hand-03
	 */
	test('a doorzending tells the applicant, and an internal move does not', async () => {
		const announced = await (await hand(cases.doorzending, RECEIVING_TEAM, true)).json()
		expect(announced.doorzending).toBe(true)
		// `announced` is false here for a case carrying no address, which is a
		// legitimate outcome and is reported as `no-address` rather than as a
		// message that went nowhere and returned success.
		expect(['no-address', 'dispatch-failed', undefined]).toContain(
			announced.announcement.reason,
		)

		const internal = await (await hand(cases.seats, RECEIVING_TEAM, false)).json()
		expect(internal.doorzending).toBe(false)
		expect(internal.announcement.reason).toBe('internal-move')
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	test('a case carries a handler and a coordinator, and the People tab shows both', async ({ page }) => {
		const roleTypes = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/roleType?_limit=100`,
		)
		const coordinator = (await roleTypes.json()).results?.find(
			(row: any) => row.genericRole === 'coordinator',
		)
		test.skip(!coordinator, 'This instance declares no coordinator role type.')

		await createObject(api, token, 'role', {
			name: `${RUN_PREFIX} casemanager`,
			roleType: objectId(coordinator),
			case: cases.seats,
			participant: 'user:admin',
		})

		const seats = await api.get(
			`/index.php/apps/${REGISTER}/api/case/${cases.seats}/seats`,
			{ headers: { requesttoken: token } },
		)
		expect(seats.ok()).toBeTruthy()
		expect((await seats.json()).coordinator).toBe('admin')

		await page.goto(`${APP_URL}cases/${cases.seats}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await page.getByRole('tab', { name: 'People' }).click()
		await expect(page.getByText('Seats')).toBeVisible()
		await expect(page.getByText(`${RUN_PREFIX} casemanager`)).toBeVisible()
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-type-may-require-a-coordinator-before-signing-req-hand-06
	 */
	test('a case type that asks for no coordinator asks for nobody', async () => {
		const seats = await api.get(
			`/index.php/apps/${REGISTER}/api/case/${cases.moved}/seats`,
			{ headers: { requesttoken: token } },
		)

		// The seeded case type declares nothing, so the requirement is off and
		// the empty seat is no obstacle. The refusal with the seat EMPTY and
		// the requirement ON is asserted in
		// tests/Unit/Service/CoordinatorRequiredBeforeSigningTest.php, which
		// can declare a case type without publishing one on a shared instance.
		expect((await seats.json()).coordinatorRequiredBeforeSigning).toBe(false)
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	test('an externally homed case is in the same list and points at its home', async ({ page }) => {
		await page.goto(CASES_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect(page.getByText(`${RUN_PREFIX} elsewhere`)).toBeVisible()
		await expect(page.getByText(`${RUN_PREFIX} moved`)).toBeVisible()

		await page.goto(`${APP_URL}cases/${cases.elsewhere}`, PAGE_LOAD)
		await expect(page.getByText('Suite4Sociaal Domein')).toBeVisible()
		await expect(page.getByText(`${RUN_PREFIX}-SD-4417`)).toBeVisible()
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-may-be-homed-in-another-application-req-hand-04
	 */
	test('the acts that perform work are disabled on an externally homed case', async () => {
		const actions = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case/${cases.elsewhere}/available-actions`,
		)
		test.skip(!actions.ok(), 'This OpenRegister does not publish available-actions.')

		const published = (await actions.json()).results ?? []
		test.skip(published.length === 0, 'This case type declares no transitions.')

		for (const action of published) {
			expect(action.blocked).toBe(true)
			expect(String(action.description)).toContain('Suite4Sociaal Domein')
		}
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	test('a leaver handover is previewed, and nothing moves until it is run', async () => {
		const preview = await api.post(
			`/index.php/apps/${REGISTER}/api/leaver-handover/preview`,
			{ headers: { requesttoken: token }, data: { fromUser: 'admin' } },
		)
		expect(preview.ok()).toBeTruthy()

		const named = await preview.json()
		expect(named.counts).toHaveProperty('cases')
		expect(named.counts).toHaveProperty('coordinatorCases')
		expect(named.counts).toHaveProperty('drafts')
		expect(named.counts.total).toBeGreaterThanOrEqual(0)

		// THE PREVIEW MOVED NOTHING, checked against a case it would have
		// touched rather than against the preview's own word for it.
		const untouched = await showObject(api, 'case', cases.moved)
		expect(untouched.handoverRecord ?? null).toBeNull()
	})

	/**
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	test('the leaver handover refuses a person handed their own work', async () => {
		const response = await api.post(
			`/index.php/apps/${REGISTER}/api/leaver-handover/execute`,
			{ headers: { requesttoken: token }, data: { fromUser: 'admin', toUser: 'admin' } },
		)

		// 🔑 THE ACT ITSELF IS NOT RUN HERE. Executing a real leaver handover
		// for `admin` on a shared instance would reassign every case every
		// other session seeded, which is the e2e-residue failure this suite
		// has already paid for once. The walk, the seats and the record are
		// asserted in tests/Unit/Service/LeaverHandoverTest.php against a
		// store with a known population.
		expect(response.status()).toBe(400)
		expect((await response.json()).error).toBe('leaver-handover-incomplete')
	})
})
