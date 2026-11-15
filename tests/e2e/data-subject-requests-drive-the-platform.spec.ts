/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A data subject request, handled as a case, driving OpenRegister's erasure
 * preview and its subject export.
 *
 * WHY THIS NEEDS A REAL INSTANCE. The rules are unit-tested to the sentence:
 * `PlatformDataSubjectRightsTest` proves each act resolves to an OpenRegister
 * service and that the platform's refusal keeps its own rule,
 * `DataSubjectRequestCaseTest` proves a run without the approving act is
 * refused and that an approved run lands on the case, and
 * `ErasureCompletePreconditionTest` proves both sides of the close guard. What
 * none of those can show is that the two apps MEET: that dossiq's endpoint
 * really reaches OpenRegister's preview, that `report.protected` survives the
 * hop with its grounds intact, and that the engine really withholds the close
 * from a case whose run came back incomplete. Each of those seams sits between
 * two apps.
 *
 * 🔴 NOTHING HERE SEEDS AN `erasureOutcome` OR AN `erasurePreviewId` ONTO A
 * CASE DIRECTLY in the first two tests, and nothing may: those are values only
 * the platform produces, and a fixture that wrote one would be testing a shape
 * dossiq never receives. The close-guard test is the deliberate exception and
 * says so at its own seam: it is asserting the ENGINE's reading of a report,
 * not the production of one, and seeding the report is the only way to pin an
 * incomplete run without an unerasable fixture in the PII index.
 *
 * 🔴 THE PII INDEX HAS NO HTTP WRITE DOOR FOR OBJECTS. OpenRegister's own spec
 * records this (REQ-DSR-001, REQ-DSR-002 both carry an `@e2e exclude` for it),
 * so a preview taken here legitimately reports zero erasable rows. That is the
 * point of these assertions: they pin the SHAPE and the PLUMBING, and the
 * counting itself is asserted in openregister's
 * `ErasurePreviewServiceTest::testTheGemeenteCanAnswerTheSubjectHonestly`.
 *
 * ASSERT IDS AND STORED FACTS, NOT LABELS: nothing forces the language of the
 * e2e instance, so the only text asserted is text this fixture seeded, which
 * carries RUN_PREFIX and reads the same in either locale.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'

const DOSSIQ_API = '/index.php/apps/dossiq/api'

test.describe('data subject requests drive the platform', () => {
	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, await getRequestToken(request))
	})

	test('the preview lands on the case with its grounds', async ({ request }) => {
		const token = await getRequestToken(request)
		const machine = await seedStateMachine(request, token)
		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} verwijderverzoek`,
			caseType: machine.caseTypeId,
			dataSubjectRequestType: 'verwijdering',
			dataSubject: `${RUN_PREFIX}@example.invalid`,
			dataSubjectType: 'email',
		})
		const caseId = objectId(created)

		const res = await request.post(
			`${DOSSIQ_API}/cases/${caseId}/avg/erasure-preview`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
			},
		)

		expect(res.status()).toBe(200)
		const preview = await res.json()

		// The counts come back split three ways over the four kinds of thing,
		// which is the contract openregister#3759 publishes.
		expect(preview.report.counts).toHaveProperty('erasable')
		expect(preview.report.counts).toHaveProperty('pseudonymised')
		expect(preview.report.counts).toHaveProperty('protected')
		expect(preview.report.counts.erasable).toHaveProperty('objects')
		expect(preview.report.counts.erasable).toHaveProperty('timelineEntries')
		expect(preview.digest).toMatch(/^[0-9a-f]{8,}$/)

		// And the case now carries it, so a handler reading this file in a
		// year is not re-running a computation over an index that has moved.
		const stored = await showObject(request, 'case', caseId)
		expect(stored.erasurePreviewId).toBe(preview.uuid)
		expect(stored.erasureDigest).toBe(preview.digest)
		expect(stored.erasureCounts).toBeTruthy()

		// Every protected item is NAMED with its ground, because a count is
		// not an answer a handler can give the person who asked.
		for (const item of stored.erasureProtected ?? []) {
			expect(item.name).toBeTruthy()
			expect(item.ground).toBeTruthy()
		}
	})

	test('a run without the approving act is refused, and nothing is written', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const machine = await seedStateMachine(request, token)
		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} verwijderverzoek ongoedgekeurd`,
			caseType: machine.caseTypeId,
			dataSubjectRequestType: 'verwijdering',
			dataSubject: `${RUN_PREFIX}-2@example.invalid`,
		})
		const caseId = objectId(created)

		await request.post(`${DOSSIQ_API}/cases/${caseId}/avg/erasure-preview`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
		})

		const res = await request.post(
			`${DOSSIQ_API}/cases/${caseId}/avg/erasure-run`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
			},
		)

		// The rule, not just the status: `erasure-not-approved` is fixed by
		// asking a colleague, and `erasure-preview-stale` by taking the
		// preview again. A handler who cannot tell them apart retries for ever.
		expect(res.status()).toBe(409)
		expect((await res.json()).error).toBe('erasure-not-approved')

		const stored = await showObject(request, 'case', caseId)
		expect(stored.erasureOutcome ?? []).toEqual([])
		expect(stored.erasureApprovedBy ?? '').toBe('')
	})

	test('an incomplete erasure withholds the close and names what is left', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const machine = await seedStateMachine(request, token)
		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} verwijderverzoek onvoltooid`,
			caseType: machine.caseTypeId,
			dataSubjectRequestType: 'verwijdering',
			dataSubject: `${RUN_PREFIX}-3@example.invalid`,
		})
		const caseId = objectId(created)

		// The exception this file opens with: the ENGINE's reading of a run
		// report is what is under test, and the report itself is seeded
		// because the platform cannot be made to leave something behind here.
		await updateObject(request, token, 'case', caseId, {
			erasureOutcome: {
				complete: false,
				destroyed: [],
				pseudonymised: [],
				withheld: [{ name: `${RUN_PREFIX} subsidiedossier` }],
				refused: [],
				failed: [],
			},
		})

		const res = await request.get(
			`${DOSSIQ_API}/case/${caseId}/available-transitions`,
			{ headers: { requesttoken: token } },
		)
		expect(res.status()).toBe(200)
		const body = await res.json()

		// The withheld reason NAMES the record, which is what the handler has
		// to answer the data subject about. A reason reading "a condition
		// failed" would pass a weaker assertion and help nobody.
		const reasons = JSON.stringify(body.withheld ?? [])
		expect(reasons).toContain(`${RUN_PREFIX} subsidiedossier`)
	})

	test('an inzage case is handed the platform export, not an erasure', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const machine = await seedStateMachine(request, token)
		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} inzageverzoek`,
			caseType: machine.caseTypeId,
			dataSubjectRequestType: 'inzage',
			dataSubject: `${RUN_PREFIX}-4@example.invalid`,
		})
		const caseId = objectId(created)

		// A preview is refused on a case that did not ask to be erased.
		const refused = await request.post(
			`${DOSSIQ_API}/cases/${caseId}/avg/erasure-preview`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
			},
		)
		expect(refused.status()).toBe(409)
		expect((await refused.json()).error).toBe('not-an-erasure-request')

		const asked = await request.post(
			`${DOSSIQ_API}/cases/${caseId}/avg/subject-export`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
			},
		)
		expect(asked.status()).toBe(200)
		expect((await asked.json()).uuid).toBeTruthy()

		const state = await request.get(
			`${DOSSIQ_API}/cases/${caseId}/avg/subject-export`,
			{
				headers: { requesttoken: token },
			},
		)
		expect(state.status()).toBe(200)
		const read = await state.json()

		// `downloadable` is the platform's answer and never a date comparison
		// here: an export still being assembled is neither downloadable nor
		// expired, and calling it expired sends a handler to ask for a second.
		expect(read).toHaveProperty('downloadable')
		expect(read).toHaveProperty('expired')
		expect(read.downloadable && read.expired).toBe(false)
	})
})
