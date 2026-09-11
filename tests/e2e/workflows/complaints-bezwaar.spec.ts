/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent coverage of the complaint-family workflow: bezwaren
 * (objections), stored as `objectionProceeding` objects linked to a case.
 *
 * WHAT CHANGED UNDER IT. This file was written against the Bezwaren INDEX
 * page. That page was retired on 2026-09-02 (dossiq#1682): it filtered `case`
 * on a bezwaar case type that ships disabled, so it listed nothing, and it was
 * deleted from the manifest rather than hidden. The whole describe had been
 * `describe.fixme` since procest#675, with its reason in a comment where the
 * Playwright report could not record it, so both tests ran nothing and read
 * as coverage.
 *
 * What survives is the objection's OWN page, BezwaarDetail (`/bezwaren/:id`,
 * `type: detail` over `objectionProceeding`), whose core data widget shows
 * the AWB reference and the workflow status. So:
 *
 *   - the status test drives that page: a seeded bezwaar shows its AWB
 *     reference and status there, a status change walked through the
 *     schema's lifecycle PERSISTS, and the page renders the new status; and
 *   - the list test is gone. It was an unconditional `test.fixme` against the
 *     retired index, so its body never ran and it could not redden, yet it
 *     still carried the anchor for the bezwaarzaken index scenario. That
 *     scenario now carries a reason-bearing `@e2e exclude` in
 *     bezwaar-beroep-workflow/spec.md instead, until it is re-scoped to a page
 *     the manifest declares.
 *
 * Fixtures seed and clean through the OpenRegister object API.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	createObject,
	deleteObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from '../helpers/fixtures.ts'
import { navToRoute } from '../helpers/nav.ts'

let api: APIRequestContext
let token: string
let caseTypeId: string
let caseTypeSeeded = false
let caseId: string

/** The status a bezwaar is filed in, in either locale the app renders. */
const RECEIVED = /^\s*(Received|Ontvangen)\s*$/

/** The status the test walks it to, in either locale. */
const IN_HANDLING = /^\s*(In handling|In behandeling)\s*$/

test.describe('Complaint-family workflow: bezwaren (objections)', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		const ct = await ensureCaseType(api, token)
		caseTypeId = ct.id
		caseTypeSeeded = ct.seeded
		const kase = await seedCase(api, token, {
			title: `${RUN_PREFIX} Bezwaar parent case`,
			caseType: caseTypeId,
		})
		caseId = objectId(kase)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		if (caseTypeSeeded) await deleteObject(api, token, 'caseType', caseTypeId)
		await api.dispose()
	})

	/**
	 * Seed a bezwaar linked to the shared parent case.
	 *
	 * @param awb    The AWB reference (unique, RUN_PREFIX-tagged).
	 * @param status The initial workflow status.
	 */
	async function seedBezwaar(awb: string, status = 'Received'): Promise<any> {
		// `receiptDate`, which the schema REQUIRES. The fixture still wrote the
		// retired `receipt_date` (lib/Repair/RenameDutchColumns.php), so every
		// seed would have been refused had the describe ever run.
		return createObject(api, token, 'objectionProceeding', {
			case: caseId,
			receiptDate: '2026-06-01',
			status,
			awbReference: awb,
		})
	}

	/**
	 * Open one bezwaar's own page and wait for its core data widget.
	 *
	 * @param page The page.
	 * @param id   The objectionProceeding uuid.
	 */
	async function openBezwaar(page: Page, id: string): Promise<void> {
		await navToRoute(page, `/bezwaren/${id}`)
		// navToRoute leaves the page wherever the router put it, and the
		// catch-all route REDIRECTS to the dashboard, which renders fine. A
		// path still carrying the id is the proof the route resolved.
		expect(
			new URL(page.url()).pathname,
			'The bezwaar detail route must resolve rather than fall through to the dashboard.',
		).toMatch(new RegExp(`/bezwaren/${id}$`))
		await expect(
			page.locator('.cn-object-data-widget__cell').first(),
		).toBeVisible({
			timeout: 15000,
		})
	}

	// @e2e exclude No canonical spec covers a workflow status changed from the
	// bezwaar UI and surviving a re-render. bezwaar-beroep-workflow specifies
	// the transitions themselves, not the list re-rendering after one. This
	// test pins the behaviour until a scenario exists.
	//
	// It re-renders on the bezwaar's own page now, because the list it used
	// to read is retired (see the header).
	test('changing the bezwaar workflow status persists and re-renders', async ({
		page,
	}) => {
		const awb = `${RUN_PREFIX}-AWB-STATUS`
		const bz = await seedBezwaar(awb, 'Received')
		const bzId = objectId(bz)
		expect(bzId, 'The seeded bezwaar must carry an id.').not.toBe('')

		// BEFORE: the bezwaar's own page shows it, in the status it was filed
		// in. Read off the data widget's VALUE cells, so a label or a lifecycle
		// action that happens to share a word cannot satisfy the assertion.
		await openBezwaar(page, bzId)
		const values = page.locator('.cn-object-data-widget__value')
		await expect(values.filter({ hasText: awb })).toHaveCount(1, {
			timeout: 15000,
		})
		await expect(values.filter({ hasText: RECEIVED })).toHaveCount(1)

		// Advance the workflow status along the schema's own lifecycle. There
		// is no one-step edge from `Received` to `In handling`: the
		// x-openregister-lifecycle on objectionProceeding goes through the
		// admissibility check first (ontvankelijkheidstoets_starten, then
		// in_behandeling_nemen), and a write that skips it is refused.
		for (const status of ['AdmissibilityCheck', 'In handling']) {
			await updateObject(api, token, 'objectionProceeding', bzId, { status })
		}

		// PERSISTENCE: re-read confirms the new status was written.
		await expect
			.poll(
				async () =>
					String(
						(await showObject(api, 'objectionProceeding', bzId)).status
							?? '',
					),
				{
					timeout: 15000,
					message: 'bezwaar status persisted',
				},
			)
			.toBe('In handling')

		// AFTER: the page, loaded afresh, renders the new status and no
		// longer the old one.
		await openBezwaar(page, bzId)
		await expect(values.filter({ hasText: IN_HANDLING })).toHaveCount(1, {
			timeout: 15000,
		})
		await expect(values.filter({ hasText: RECEIVED })).toHaveCount(0)
	})
})
