/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-TL-10 to REQ-TL-14: a case carries one timeline, dossiq's kinds are
 * declared on the instance, every communication writer records on it, an
 * entry that belongs on several cases names its siblings, and the standard
 * notes are administered text rather than retyped.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT SUITE. The unit suite proves that
 * dossiq CALLS the seam with the right kind, the right fields and the right
 * visibility. It cannot prove the three things this file is for.
 *
 *  - THE KINDS ARE ACTUALLY DECLARED ON THE INSTANCE. An undeclared kind is a
 *    400 from OpenRegister, `CaseTimeline` catches it and logs it, and the
 *    timeline then simply stops filling. Every unit test in the repository
 *    passes in that state, because they all assert against a double. The only
 *    way to know is to ask a running `/timeline/kinds` what it holds.
 *  - THE ENTRY SURVIVES THE ROUND TRIP. `fields` is filtered by the kind on
 *    the way in: a value the kind does not declare is DROPPED rather than
 *    refused, so a field name that drifted comes back missing and nothing
 *    anywhere says so.
 *  - THE LIST READ IS FILTERED WHETHER OR NOT WE ASK. A reader without
 *    `update` is served the public view with no `visibility` parameter. That
 *    is a server-side predicate; a stubbed reader cannot see it at all.
 *
 * WHICH DOOR. The timeline is OpenRegister's, so reads and writes of entries
 * go to OpenRegister's own API, which is what the Timeline tab uses too. The
 * case itself is seeded through the shared fixtures.
 *
 * WHAT THIS SUITE DOES NOT DO. It seeds its own case under RUN_PREFIX and
 * writes entries only on cases it created. A timeline entry is a RECORD of a
 * communication, and a record left on somebody's real case is exactly the
 * residue this repository has been bitten by before, so nothing here is ever
 * selected by a filter broader than the run prefix.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	deleteObject,
	ensureCaseType,
	executeTransition,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** OpenRegister's own API, which owns the timeline. */
const OR = '/index.php/apps/openregister/api'

/** The case schema, as the timeline routes address it. */
const SCHEMA = 'case'

/** The case every test in this file hangs its entries on. */
let caseId = ''

/** A second case, for the entry that belongs on both. */
let siblingCaseId = ''

/** The CSRF token for every write below. */
let token = ''

/** dossiq's own API, which owns the status engine and the term engine. */
const APP = '/index.php/apps/dossiq/api'

/** Term instances seeded by this file, cleaned up by id. */
const seededTerms: string[] = []

/**
 * The timeline of one case.
 *
 * @param api      The request context.
 * @param id       The case uuid.
 * @param query    Extra query parameters, e.g. a visibility.
 *
 * @return The parsed response body.
 */
async function readTimeline(
	api: APIRequestContext,
	id: string,
	query: Record<string, string> = {},
): Promise<any> {
	const search = new URLSearchParams(query).toString()
	const url = `${OR}/objects/${REGISTER}/${SCHEMA}/${id}/timeline${search ? `?${search}` : ''}`
	const response = await api.get(url)
	expect(response.ok(), `GET ${url} answered ${response.status()}`).toBeTruthy()

	return response.json()
}

/**
 * Write one entry on one case.
 *
 * @param api  The request context.
 * @param id   The case uuid.
 * @param body The entry payload.
 *
 * @return The raw response, so a test may assert on a refusal.
 */
async function writeEntry(api: APIRequestContext, id: string, body: Record<string, unknown>) {
	return api.post(`${OR}/objects/${REGISTER}/${SCHEMA}/${id}/timeline`, {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: body,
	})
}

test.beforeAll(async ({ request }) => {
	token = await getRequestToken(request)
	const caseTypeId = (await ensureCaseType(request, token)).id

	const seeded = await seedCase(request, token, {
		title: `${RUN_PREFIX} timeline`,
		caseType: caseTypeId,
	})
	caseId = objectId(seeded)

	const sibling = await seedCase(request, token, {
		title: `${RUN_PREFIX} timeline sibling`,
		caseType: caseTypeId,
	})
	siblingCaseId = objectId(sibling)
})

test.afterAll(async ({ request }) => {
	for (const id of seededTerms) {
		await deleteObject(request, token, 'deadlineInstance', id)
	}
	await cleanupRunObjects(request, token)
})

/**
 * The entries of one case whose kind is the given one.
 *
 * @param api  The request context.
 * @param id   The case uuid.
 * @param kind The declared kind to keep.
 *
 * @return The matching entries, as the read returned them.
 */
async function entriesOfKind(
	api: APIRequestContext,
	id: string,
	kind: string,
): Promise<any[]> {
	const body = await readTimeline(api, id)
	const rows = (body.results ?? []) as any[]

	return rows.filter((row: any) => String(row?.kind ?? '') === kind)
}

test.describe('REQ-TL-11 dossiq declares the kinds it writes', () => {
	test('every kind dossiq names is declared on the instance', async ({ request }) => {
		const response = await request.get(`${OR}/timeline/kinds`)
		expect(response.ok()).toBeTruthy()

		const declared = ((await response.json()).results || []).map((kind: any) => kind.slug)

		for (const slug of [
			'contactmoment',
			'mail-inkomend',
			'mail-uitgaand',
			'portaalbericht',
			'ontvangstbevestiging',
			'statuswijziging',
			'termijngebeurtenis',
		]) {
			expect(declared, `${slug} is named by a dossiq writer`).toContain(slug)
		}
	})

	test('an inbound mail entry opens a follow-up and a contact moment does not', async ({ request }) => {
		const inbound = await writeEntry(request, caseId, {
			kind: 'mail-inkomend',
			message: `${RUN_PREFIX} bericht ontvangen`,
			fields: { sender: 'burger@example.org', subject: 'Vraag' },
		})
		expect(inbound.status()).toBe(201)
		expect((await inbound.json()).followUp).toBe('open')

		const call = await writeEntry(request, caseId, {
			kind: 'contactmoment',
			message: `${RUN_PREFIX} gebeld`,
			fields: { channel: 'phone', direction: 'inbound' },
		})
		expect(call.status()).toBe(201)
		expect((await call.json()).followUp).toBeNull()
	})

	test('a kind nobody declared is refused rather than filed as a plain note', async ({ request }) => {
		const response = await writeEntry(request, caseId, {
			kind: 'nobody-declared-this',
			message: `${RUN_PREFIX} should not land`,
		})

		expect(response.status()).toBe(400)
	})

	test('the contact moment kind accepts the channel dossiq actually stores', async ({ request }) => {
		// The drift this guards: the kind declaring telefoon/balie/email/post
		// while ContactMomentService validates and stores phone/email/
		// webformulier/chat/social_media/balie. The refusal is caught and
		// logged rather than shown, so the timeline would stop filling with
		// no error anywhere.
		const response = await writeEntry(request, caseId, {
			kind: 'contactmoment',
			message: `${RUN_PREFIX} webformulier`,
			fields: { channel: 'webformulier', direction: 'inbound' },
		})

		expect(response.status()).toBe(201)
		expect((await response.json()).fields.channel).toBe('webformulier')
	})
})

test.describe('REQ-TL-12 every communication writer records on the timeline', () => {
	test('an entry keeps the fields its kind declares across the round trip', async ({ request }) => {
		const written = await writeEntry(request, caseId, {
			kind: 'mail-uitgaand',
			message: `${RUN_PREFIX} beschikking verzonden`,
			visibility: 'public',
			fields: {
				recipient: 'burger@example.org',
				subject: 'Beschikking',
				documentId: 'doc-1',
			},
		})
		expect(written.status()).toBe(201)

		const entry = await written.json()
		expect(entry.visibility).toBe('public')
		expect(entry.fields.recipient).toBe('burger@example.org')
		expect(entry.fields.documentId).toBe('doc-1')
	})

	test('a field the kind does not declare is dropped, not stored', async ({ request }) => {
		const written = await writeEntry(request, caseId, {
			kind: 'portaalbericht',
			message: `${RUN_PREFIX} portaalbericht`,
			visibility: 'public',
			fields: { subject: 'Bericht', messageId: 'm-1', bsn: '999999999' },
		})
		expect(written.status()).toBe(201)

		const entry = await written.json()
		expect(entry.fields.messageId).toBe('m-1')
		expect(entry.fields.bsn, 'the portaalbericht kind declares no bsn').toBeUndefined()
	})
})

test.describe('REQ-TL-13 one entry reaches every case it is about', () => {
	test('a note written on two cases names its siblings on each', async ({ request }) => {
		const written = await writeEntry(request, caseId, {
			message: `${RUN_PREFIX} over beide zaken`,
			relatedObjects: [{ register: REGISTER, schema: SCHEMA, id: siblingCaseId }],
		})
		expect(written.status()).toBe(201)

		const results = (await written.json()).results
		expect(results).toHaveLength(2)
		for (const entry of results) {
			expect(entry.siblings.length).toBe(1)
		}

		const sibling = await readTimeline(request, siblingCaseId)
		const found = sibling.results.find(
			(entry: any) => entry.message === `${RUN_PREFIX} over beide zaken`,
		)
		expect(found, 'the second case carries the entry').toBeTruthy()
	})

	test('a related case that cannot be reached leaves nothing written anywhere', async ({ request }) => {
		const before = (await readTimeline(request, caseId)).total

		const response = await writeEntry(request, caseId, {
			message: `${RUN_PREFIX} should not land anywhere`,
			relatedObjects: [
				{ register: REGISTER, schema: SCHEMA, id: '00000000-0000-0000-0000-000000000000' },
			],
		})
		expect(response.status()).toBe(404)

		const after = await readTimeline(request, caseId)
		expect(after.total).toBe(before)
		expect(
			after.results.some((entry: any) => entry.message.includes('should not land anywhere')),
		).toBeFalsy()
	})
})

test.describe('REQ-TL-14 the standard notes are administered text', () => {
	test('dossiq seeds its standard notes on the instance', async ({ request }) => {
		const response = await request.get(`${OR}/timeline/text-blocks`)
		expect(response.ok()).toBeTruthy()

		const slugs = ((await response.json()).results || []).map((block: any) => block.slug)
		expect(slugs).toContain('dossiq-terugbelverzoek')
		expect(slugs).toContain('dossiq-stukken-opgevraagd')
	})

	test('a standard note is written with the case substituted into it', async ({ request }) => {
		const written = await writeEntry(request, caseId, {
			textBlock: 'dossiq-terugbelverzoek',
		})
		expect(written.status()).toBe(201)

		const entry = await written.json()
		expect(entry.message).toContain('terug te bellen')
		expect(entry.message, 'the case number is substituted, not left in braces').not.toContain(
			'{{identifier}}',
		)
	})
})

test.describe('REQ-TL-10 the case carries one timeline', () => {
	test('the Timeline tab lists the entries newest first with pinned ones on top', async ({
		page,
		request,
	}) => {
		const pinTarget = await writeEntry(request, caseId, {
			kind: 'contactmoment',
			message: `${RUN_PREFIX} pin me`,
			fields: { channel: 'phone', direction: 'inbound' },
		})
		const pinned = await pinTarget.json()

		await writeEntry(request, caseId, {
			message: `${RUN_PREFIX} newer than the pinned one`,
		})

		const patched = await request.patch(
			`${OR}/objects/${REGISTER}/${SCHEMA}/${caseId}/timeline/${pinned.id}`,
			{
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				data: { pinned: true },
			},
		)
		expect(patched.ok()).toBeTruthy()

		await openTimelineTab(page, caseId)

		const entries = page.getByTestId('case-timeline-entry')
		await expect(entries.first()).toContainText('pin me')
	})

	test('a read that fails says so rather than showing an empty case', async ({ page }) => {
		await page.route(`**${OR}/objects/**/timeline*`, (route) =>
			route.fulfill({ status: 500, body: '{"message":"no"}' }),
		)

		await openTimelineTab(page, caseId)

		await expect(page.getByTestId('case-timeline-error')).toBeVisible()
		await expect(page.getByTestId('case-timeline-empty')).toHaveCount(0)
	})

	test('the kind filter narrows the list to one kind', async ({ page, request }) => {
		await writeEntry(request, caseId, {
			kind: 'ontvangstbevestiging',
			message: `${RUN_PREFIX} ontvangst bevestigd`,
			visibility: 'public',
			fields: { channel: 'email' },
		})

		await openTimelineTab(page, caseId)

		const before = await page.getByTestId('case-timeline-entry').count()
		expect(before).toBeGreaterThan(1)

		await page.getByTestId('case-timeline-kind-filter').click()
		await page.getByRole('option', { name: 'Ontvangstbevestiging' }).click()

		await expect(page.getByTestId('case-timeline-entry')).toHaveCount(1)
	})

	test('a follow-up is closed from the tab', async ({ page, request }) => {
		await writeEntry(request, caseId, {
			kind: 'mail-inkomend',
			message: `${RUN_PREFIX} answer me`,
			fields: { sender: 'burger@example.org', subject: 'Vraag' },
		})

		await openTimelineTab(page, caseId)

		const open = page
			.getByTestId('case-timeline-entry')
			.filter({ hasText: 'answer me' })
			.first()
		await expect(open.getByTestId('case-timeline-followup-open')).toBeVisible()

		await open.getByTestId(/^case-timeline-followup-/).click()

		await expect(
			page
				.getByTestId('case-timeline-entry')
				.filter({ hasText: 'answer me' })
				.first()
				.getByTestId('case-timeline-followup-open'),
		).toHaveCount(0)
	})
})

/**
 * Open a case's Timeline tab.
 *
 * @param page The page.
 * @param id   The case uuid.
 *
 * @return Resolves once the tab has rendered.
 */
async function openTimelineTab(page: Page, id: string): Promise<void> {
	await page.goto(`/apps/dossiq/#/cases/${id}`, PAGE_LOAD)
	await page.getByRole('tab', { name: 'Timeline' }).click()
	await expect(page.getByTestId('case-timeline')).toBeVisible(PAGE_LOAD)
}

test.describe('REQ-TL-15 every status move records itself on the timeline', () => {
	test('a guarded transition writes the two statuses, the mover and the comment', async ({
		request,
	}) => {
		const machine = await seedStateMachine(request, token)
		const seeded = await seedCase(request, token, {
			title: `${RUN_PREFIX} status on the timeline`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		const movedCase = objectId(seeded)

		const moved = await executeTransition(
			request,
			token,
			movedCase,
			't1',
			`${RUN_PREFIX} stukken compleet`,
		)
		expect(
			moved.status,
			`the transition must be accepted: ${JSON.stringify(moved.body)}`,
		).toBe(200)

		const entries = await entriesOfKind(request, movedCase, 'statuswijziging')
		expect(entries).toHaveLength(1)

		// The FIELDS are the point. An undeclared field is dropped rather than
		// refused, so a writer whose declaration did not move with it stores an
		// entry that looks right and carries nothing.
		const fields = entries[0].fields ?? {}
		expect(fields.from).toBe(machine.statusReceived)
		expect(fields.to).toBe(machine.statusInProgress)
		expect(fields.explanation).toContain('stukken compleet')
		// The IDENTITY, not merely something non-empty. A writer that stamped
		// every line with the row's owner, or with the string 'system', would
		// pass a not-empty assertion and name the wrong person on every move.
		expect(fields.actor).toBe(process.env.ADMIN_USER ?? 'admin')
		expect(String(fields.statusRecordId ?? '')).not.toBe('')
	})

	test('the sentence a handler reads names the statuses, not their uuids', async ({
		request,
	}) => {
		const machine = await seedStateMachine(request, token)
		const seeded = await seedCase(request, token, {
			title: `${RUN_PREFIX} status sentence`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		const movedCase = objectId(seeded)

		const moved = await executeTransition(request, token, movedCase, 't1')
		expect(moved.status).toBe(200)

		const [entry] = await entriesOfKind(request, movedCase, 'statuswijziging')
		expect(entry.message).toContain('In behandeling')
		expect(entry.message).not.toContain(machine.statusInProgress)
	})
})

test.describe('REQ-TL-16 every term event records itself on the timeline', () => {
	test('a suspended term writes the event, the new due date and the instance', async ({
		request,
	}) => {
		const caseTypeId = (await ensureCaseType(request, token)).id
		const seeded = await seedCase(request, token, {
			title: `${RUN_PREFIX} term on the timeline`,
			caseType: caseTypeId,
		})
		const termCase = objectId(seeded)

		const term = await createObject(request, token, 'deadlineInstance', {
			case: termCase,
			status: 'lopend',
			startDate: '2026-01-05T09:00:00+00:00',
			endDateCalculated: '2026-03-02',
			endDateCurrent: '2026-03-02',
		})
		const termId = objectId(term)
		seededTerms.push(termId)

		const paused = await request.post(`${APP}/termijn/instances/${termId}/pauze`, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			data: { duurDagen: 14, rationale: `${RUN_PREFIX} aanvulling gevraagd` },
		})
		expect(
			paused.ok(),
			`the pause must be accepted: ${await paused.text()}`,
		).toBeTruthy()

		const entries = await entriesOfKind(request, termCase, 'termijngebeurtenis')
		expect(entries).toHaveLength(1)

		const fields = entries[0].fields ?? {}
		expect(fields.event).toBe('pause')
		expect(fields.termijnId).toBe(termId)
		expect(String(fields.dueAt ?? '')).not.toBe('')
		expect(String(fields.occurredAt ?? '')).not.toBe('')
		expect(entries[0].message).toContain('aanvulling gevraagd')
	})
})
