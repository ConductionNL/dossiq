/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-request-takes-over-from-opencatalogi: a Woo request stored in
 * opencatalogi moves to a dossiq case through the generic record import
 * (`occ dossiq:case:import-records woo-verzoek`), keeps its old reference, and
 * a handler finds the case by that old number. A second run writes nothing.
 *
 * NOT RUN IN THIS LANE (decision 139). No Playwright runs here. The suite is
 * written so the live pass owns it.
 *
 * THE IMPORT READS EVERY opencatalogi wooRequest ON THE INSTANCE, not only the
 * one this spec seeds. On a shared instance that would move other people's
 * requests, so the spec refuses to run there and needs an instance it owns.
 */

import { expect, test } from '@playwright/test'
import { IS_SHARED_INSTANCE } from './base-url.ts'
import {
	cleanupRunObjects,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	trackCreatedObject,
} from './helpers/fixtures.ts'
import { occRun } from './helpers/occ.ts'

/** The Woo request case type the app seeds (WooRequestIntake::CASE_TYPE_ID). */
const WOO_CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001'

/** The status the import maps opencatalogi's `received` onto ("Ontvangst"). */
const STATUS_RECEIVED = '3c0f5a00-0000-4000-a000-00000000b001'

/** Where opencatalogi keeps its Woo requests. */
const SOURCE = '/index.php/apps/openregister/api/objects/publication/wooRequest'

/** An old number nothing else on the instance carries. */
const REFERENCE = `WOO-2026-${RUN_PREFIX.replace(/[^a-z0-9]/gi, '').slice(-6).toUpperCase()}`

test.describe('A Woo request moves from opencatalogi to a dossiq case', () => {
	test.describe.configure({ mode: 'serial' })

	test.skip(
		IS_SHARED_INSTANCE,
		'The import moves every opencatalogi wooRequest on the instance; run it on an instance this suite owns.',
	)

	let sourceId = ''

	test.afterAll(async ({ request }) => {
		const token = await getRequestToken(request)
		if (sourceId !== '') {
			await request.delete(`${SOURCE}/${sourceId}`, {
				headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			})
		}
		await cleanupRunObjects(request, token)
	})

	// @e2e openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#scenario-a-running-request-moves-with-its-remaining-time
	// @e2e openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#scenario-running-the-import-twice-changes-nothing
	// @e2e openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#scenario-a-citizens-old-number-still-finds-the-case
	// @e2e openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#scenario-every-declared-shape-lands-on-the-case
	// @e2e openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#scenario-running-the-import-twice-changes-nothing
	test('import once, find the case by its old number, import again and nothing moves (REQ-WTO-004)', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true' }

		// A running request in opencatalogi's shape. Without opencatalogi
		// there is nothing to take over, and the spec says so instead of
		// passing on an empty import.
		const seeded = await request.post(SOURCE, {
			headers,
			data: {
				reference: REFERENCE,
				requestedInformation: `${RUN_PREFIX} Alle adviezen over de Stationsweg 2025`,
				requesterName: 'J. de Vries',
				requesterEmail: 'j@example.nl',
				channel: 'web',
				status: 'received',
				receivedAt: '2026-10-05T09:00:00+02:00',
				dueAt: '2026-11-02',
				extensionCount: 0,
			},
		})
		test.skip(
			seeded.status() === 404,
			'opencatalogi (register publication, schema wooRequest) is not installed on this instance.',
		)
		expect(seeded.ok(), `seed wooRequest -> ${seeded.status()} ${await seeded.text()}`).toBeTruthy()
		sourceId = objectId(await seeded.json())

		const first = await occRun(['dossiq:case:import-records', 'woo-verzoek'])
		expect(first.code, first.output).toBe(0)
		expect(first.output).toContain(`moved ${REFERENCE} to case `)

		// The case carries the request, its old number and its remaining time.
		const caseId = (first.output.match(new RegExp(`moved ${REFERENCE} to case ([0-9a-f-]{36})`)) ?? [])[1] ?? ''
		expect(caseId).not.toBe('')
		// The import wrote it, not createObject, so teardown is told here.
		trackCreatedObject('case', caseId)
		const cases = await listObjects(request, 'case', { 'wooRequest.originReference': sourceId })
		expect(cases).toHaveLength(1)
		const imported = cases[0]
		expect(objectId(imported)).toBe(caseId)
		expect(imported.caseType).toBe(WOO_CASE_TYPE)
		expect(imported.status).toBe(STATUS_RECEIVED)
		expect(String(imported.startDate).slice(0, 10)).toBe('2026-10-05')
		expect(String(imported.deadline).slice(0, 10)).toBe('2026-11-02')
		expect(imported.intakeChannel).toBe('website')
		expect(imported.wooRequest.origin).toBe('opencatalogi')
		expect(imported.wooRequest.verzoekerEmail).toBe('j@example.nl')
		expect(imported.formerReferences).toEqual([
			{ application: 'opencatalogi', reference: REFERENCE },
		])

		// The source is stamped with the case it moved to.
		const source = await (await request.get(`${SOURCE}/${sourceId}`, { headers })).json()
		expect(source.migratedTo ?? source.object?.migratedTo).toBe(caseId)

		// A handler searching for the citizen's old number finds the case.
		const found = await listObjects(request, 'case', { _search: REFERENCE })
		expect(found.map((row: any) => objectId(row))).toContain(caseId)

		// A second run moves nothing and writes no second case.
		const second = await occRun(['dossiq:case:import-records', 'woo-verzoek'])
		expect(second.code, second.output).toBe(0)
		expect(second.output).toMatch(/opencatalogi-woo-requests: 0 imported, \d+ already imported, 0 failed, 0 not moved\./)
		expect(
			await listObjects(request, 'case', { 'wooRequest.originReference': sourceId }),
		).toHaveLength(1)
	})
})
