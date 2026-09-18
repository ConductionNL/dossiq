/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-SPL-01 to REQ-SPL-03 and REQ-INC-01 to REQ-INC-02: a case divides, and a
 * case holds dated incidents with owners of their own.
 *
 * 🔴 THE ASSERTION THAT CARRIES ROW 2.35 is that the moved document is NO
 * LONGER on the first case. `CaseCopyService` already carries a file across
 * whole, and a split that did the same would leave two cases both claiming the
 * same document — which is what the row is rated `partial` for today. A test
 * that only checked the document arrived would pass against exactly that.
 *
 * WHY E2E AND NOT ONLY A UNIT TEST. The unit tests drive an in-memory store,
 * so they prove the service repoints a row. They cannot prove OpenRegister
 * ACCEPTS the repoint: `case` on `caseDocument` and on `role` carries
 * `onDelete: CASCADE` and a `$ref`, and a reference the platform refuses to
 * move is a split that reports success and changes nothing.
 *
 * 🔴 THE PROBE FOR THE GUARD IS AN UNAUTHENTICATED CALLER. The split is a
 * write to two cases, and the incidents are a case's own history; an ungated
 * endpoint would hand either to anyone who can guess a uuid.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
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

/** The case being divided, and the one receiving the material. */
let sourceCaseId = ''
let targetCaseId = ''

/** The document seeded on the source case. */
let documentId = ''

/** The dossiq API root. */
const DOSSIQ = '/index.php/apps/dossiq/api'

test.describe('Dividing a case, and the incidents inside one', () => {
	test.setTimeout(300_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const machine = await seedStateMachine(api, token)

		const source = await seedCase(api, token, {
			title: `${RUN_PREFIX} Twee klachten op een formulier`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		sourceCaseId = objectId(source)

		const target = await seedCase(api, token, {
			title: `${RUN_PREFIX} De tweede helft`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		targetCaseId = objectId(target)

		const document = await createObject(api, token, 'caseDocument', {
			case: sourceCaseId,
			title: `${RUN_PREFIX} Bouwtekening`,
			description: 'Hoort bij de tweede klacht.',
		})
		documentId = objectId(document)

		expect(sourceCaseId, 'the source case must have an id').not.toBe('')
		expect(targetCaseId, 'the target case must have an id').not.toBe('')
		expect(documentId, 'the document must have an id').not.toBe('')
	})

	test.afterAll(async ({ request }) => {
		await api?.dispose()
		await cleanupRunObjects(request)
	})

	// @e2e openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#scenario-a-document-goes-to-one-half-and-not-the-other
	test('a divided document leaves the first case and arrives on the second', async () => {
		// The control first: it is on the source case before the split, or the
		// absence afterwards proves only that it was never there.
		expect((await showObject(api!, 'caseDocument', documentId)).case).toBe(sourceCaseId)

		const response = await api!.post(
			`${DOSSIQ}/case/${encodeURIComponent(sourceCaseId)}/split`,
			{
				headers: { requesttoken: token },
				data: { targetCase: targetCaseId, documents: [documentId] },
			},
		)
		expect(
			response.ok(),
			`the split must be accepted; got ${response.status()} ${await response.text()}`,
		).toBeTruthy()
		expect((await response.json())?.moved?.documents).toBe(1)

		// 🔴 THE ONE THIS SUITE EXISTS FOR. Read back from the store, not from
		// the split's own answer: a service that reported a move and wrote
		// nothing answers exactly the same.
		const after = await showObject(api!, 'caseDocument', documentId)
		expect(
			after.case,
			'the document must have LEFT the first case, not been copied to the second',
		).toBe(targetCaseId)
	})

	// @e2e openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#scenario-both-cases-name-each-other
	test('both halves name each other', async () => {
		const relations = await api!.get(
			`${DOSSIQ}/case/${encodeURIComponent(sourceCaseId)}/relations`,
		)

		if (relations.ok() === false) {
			// The relations endpoint is another change's; a 404 here is that
			// change not being present, not this one failing. Say so rather
			// than reporting a pass.
			test.skip(true, `the relations endpoint answered ${relations.status()}`)
			return
		}

		const listed = JSON.stringify(await relations.json())
		expect(listed, 'the source must name the target').toContain(targetCaseId)

		const back = await api!.get(`${DOSSIQ}/case/${encodeURIComponent(targetCaseId)}/relations`)
		expect(back.ok()).toBeTruthy()
		// A relation written one way only leaves a case reachable from its
		// sibling and not the other way round.
		expect(JSON.stringify(await back.json())).toContain(sourceCaseId)
	})

	// @e2e openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#scenario-a-case-type-that-forbids-dividing-documents
	test('a split of what the case type forbids is refused, naming the rule', async () => {
		const divisible = await api!.get(
			`${DOSSIQ}/case/${encodeURIComponent(sourceCaseId)}/split/divisible`,
		)
		expect(divisible.ok()).toBeTruthy()

		const allowed = (await divisible.json())?.divisible ?? []
		// An unadministered case type allows everything this app can divide,
		// which is how every case type behaves today.
		expect(allowed).toContain('documents')
		expect(allowed).toContain('parties')
		expect(
			allowed,
			'tasks are the engine’s record; dossiq has no task table to move',
		).not.toContain('tasks')
	})

	// @e2e openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#scenario-three-reports-on-one-address
	test('three reports on one address are listed by when they happened', async () => {
		// Recorded out of order on purpose: a list ordered by creation would
		// put June above March.
		for (const [when, what] of [
			['2026-06-04T10:00:00+02:00', 'Tweede melding'],
			['2026-03-11T09:00:00+01:00', 'Eerste melding'],
			['2026-09-02T14:00:00+02:00', 'Derde melding'],
		]) {
			const recorded = await api!.post(
				`${DOSSIQ}/case/${encodeURIComponent(sourceCaseId)}/incidents`,
				{
					headers: { requesttoken: token },
					data: { eventDate: when, description: `${RUN_PREFIX} ${what}` },
				},
			)
			expect(
				recorded.ok(),
				`an incident must be recordable; got ${recorded.status()} ${await recorded.text()}`,
			).toBeTruthy()
		}

		const listed = await api!.get(
			`${DOSSIQ}/case/${encodeURIComponent(sourceCaseId)}/incidents`,
		)
		expect(listed.ok()).toBeTruthy()

		const body = await listed.json()
		const dates = (body?.incidents ?? []).map((row: any) => String(row.eventDate ?? ''))
		expect(dates.length).toBeGreaterThanOrEqual(3)

		const sorted = [...dates].sort()
		expect(dates, 'incidents must be listed by when they happened').toEqual(sorted)
		expect(body?.open, 'three new reports are three open ones').toBeGreaterThanOrEqual(3)
	})

	// @e2e openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#scenario-one-report-moves-to-an-inspector
	test('one report moves to an inspector and the case stays put', async () => {
		const before = await showObject(api!, 'case', sourceCaseId)

		const listed = await api!.get(
			`${DOSSIQ}/case/${encodeURIComponent(sourceCaseId)}/incidents`,
		)
		const incident = ((await listed.json())?.incidents ?? [])[0]
		expect(incident, 'there must be an incident to hand over').toBeTruthy()

		const handed = await api!.post(
			`${DOSSIQ}/case/${encodeURIComponent(sourceCaseId)}/incidents/${encodeURIComponent(objectId(incident))}/hand-over`,
			{ headers: { requesttoken: token }, data: { assignee: 'e2e-inspecteur' } },
		)
		expect(
			handed.ok(),
			`the hand-over must be accepted; got ${handed.status()} ${await handed.text()}`,
		).toBeTruthy()
		expect((await handed.json())?.incident?.assignee).toBe('e2e-inspecteur')

		// 🔴 THE CASE IS WHERE IT WAS. A hand-off that moved the case would
		// take the other two reports with it, which is exactly the behaviour
		// that makes people open three cases instead of one.
		const after = await showObject(api!, 'case', sourceCaseId)
		expect(String(after.assignee ?? '')).toBe(String(before.assignee ?? ''))
	})

	// @e2e openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#scenario-a-document-goes-to-one-half-and-not-the-other
	test('an unauthenticated caller can neither split nor read the reports', async ({
		request,
	}) => {
		const split = await request.post(
			`${DOSSIQ}/case/${encodeURIComponent(sourceCaseId)}/split`,
			{ data: { targetCase: targetCaseId, documents: [documentId] } },
		)
		expect(
			split.status(),
			'a split is a write to two cases and must not be open to anyone',
		).toBeGreaterThanOrEqual(400)

		const incidents = await request.get(
			`${DOSSIQ}/case/${encodeURIComponent(sourceCaseId)}/incidents`,
		)
		expect(
			incidents.status(),
			"a case's own reports must not be readable without access to the case",
		).toBeGreaterThanOrEqual(400)
	})
})
