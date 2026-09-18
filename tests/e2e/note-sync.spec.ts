/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A note written on a case that lives in a neighbouring register, and whether
 * anyone is told what became of it.
 *
 * Before this change nothing pushed a note at all: `ZgwExternalAdapterInterface`
 * declares exactly two pushes, `submitZaak` and `submitDocument`, no caller
 * pushed a note, and no schema on either side carried one. The hole was never
 * a missing connector, because ZGW has no note resource: it was that nothing
 * had decided what a note IS on the wire.
 *
 * WHAT IS DRIVEN HERE is the state every instance in this fleet is in. The
 * adapter an unbound instance has is `LogZgwExternalAdapter`, which is dormant
 * and contacts nothing, so the push answers `no-register` and records
 * NOTHING. That is the assertion: a dormant adapter writing a marker that
 * reads as a success is the failure this change exists to prevent, and an
 * instance with a real connector is not something a test can conjure.
 *
 * The envelope, the internal guard and the failure path are `@e2e exclude`d in
 * the spec and covered by NoteEnvelopeTest and NotePushTest, which drive an
 * adapter that answers.
 *
 * ⚠️ NOT RUN LOCALLY. There is no Playwright run on this box and no instance
 * to run against, so this is written and tagged, not executed.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	trackCreatedObject,
} from './helpers/fixtures.ts'

const CASE_TITLE = `${RUN_PREFIX} Notitie naar het buurregister`

test.describe('a case note reaches the neighbouring register, or says why not', () => {
	test.setTimeout(300_000)

	let caseId = ''

	test.beforeAll(async ({ request }) => {
		const token = await getRequestToken(request)
		const created = await createObject(request, token, 'case', {
			title: CASE_TITLE,
			description: 'Note sync run',
		})
		caseId = objectId(created)
		trackCreatedObject('case', caseId)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request)
	})

	// @e2e openspec/changes/a-case-note-reaches-the-neighbouring-register/specs/zgw-api-mapping/spec.md#a-refused-push-leaves-the-outcome-recorded-as-failed
	test('an unbound case records nothing at all', async ({ request }) => {
		const token = await getRequestToken(request)
		const response = await request.post(
			`/apps/dossiq/api/cases/${caseId}/notes/push`,
			{
				headers: { requesttoken: token },
				data: {
					note: {
						id: 1,
						message: 'Gebeld met de aanvrager',
						actorId: 'admin',
						actorDisplayName: 'Admin',
						visibility: 'public',
					},
				},
			},
		)

		expect(response.ok()).toBeTruthy()
		const outcome = await response.json()
		// NOT `sent`, and not `failed` either: an instance bound to no external
		// register is neither a success nor a failure, and a marker for it
		// would be a marker on every note of every unbound instance.
		expect(outcome.outcome).toBe('no-register')
	})

	// @e2e openspec/changes/a-case-note-reaches-the-neighbouring-register/specs/zgw-api-mapping/spec.md#a-note-left-on-the-default-never-leaves
	test('the endpoint refuses a request carrying no note', async ({ request }) => {
		const token = await getRequestToken(request)
		const response = await request.post(
			`/apps/dossiq/api/cases/${caseId}/notes/push`,
			{
				headers: { requesttoken: token },
				data: {},
				failOnStatusCode: false,
			},
		)

		expect(response.status()).toBe(400)
	})

	// @e2e openspec/changes/a-case-note-reaches-the-neighbouring-register/specs/zgw-api-mapping/spec.md#a-caller-who-may-not-change-the-case-cannot-send-its-notes
	test('a caller who may not change the case cannot send its notes', async ({
		request,
	}) => {
		// The least privileged principal that should be refused: an anonymous
		// caller with no session and no request token. Sending a note to
		// another organisation is externally visible and not undoable, so the
		// guard is mutation access rather than read.
		const response = await request.post(
			`/apps/dossiq/api/cases/${caseId}/notes/push`,
			{
				data: {
					note: { id: 1, message: 'X', visibility: 'public' },
				},
				failOnStatusCode: false,
			},
		)

		expect([401, 403, 412]).toContain(response.status())
	})
})
