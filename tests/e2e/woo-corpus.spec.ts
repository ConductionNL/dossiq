/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-request-corpus-collection: a handler records a search plan, gathers
 * with a custodian, adds a duplicate and sees it listed, excludes a document,
 * reads the reconciliation, re-runs the query, then starts a new request from
 * this one.
 *
 * NOT RUN IN THIS LANE (decision 139). No Playwright runs here. The suite is
 * written so the live pass owns it.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'

/** The Woo request case type the app seeds (WooRequestIntake::CASE_TYPE_ID). */
const WOO_CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001'

/** The account the suite runs as. */
const ADMIN = process.env.NC_USER ?? 'admin'

/** A word nothing else on the instance contains. */
const TERM = `stationsweg${RUN_PREFIX.replace(/[^a-z0-9]/gi, '').toLowerCase()}`

test.describe('The corpus of a Woo request', () => {
	test.describe.configure({ mode: 'serial' })

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, await getRequestToken(request))
	})

	// @e2e openspec/specs/woo-case-type/spec.md#no-plan-no-collection
	// @e2e openspec/specs/woo-case-type/spec.md#the-plan-is-held-with-the-request
	// @e2e openspec/specs/woo-case-type/spec.md#volumes-per-custodian
	// @e2e openspec/specs/woo-case-type/spec.md#a-duplicate-is-listed-not-silently-dropped
	// @e2e openspec/specs/woo-case-type/spec.md#what-arrived-reconciles-with-what-was-reviewed
	// @e2e openspec/specs/woo-case-type/spec.md#a-colleague-re-runs-the-query-and-sees-what-is-new
	// @e2e openspec/specs/woo-case-type/spec.md#start-from-the-last-request
	test('plan, gather, exclude, reconcile, re-run and start again', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true' }
		const api = (caseId: string, tail: string) =>
			`/index.php/apps/dossiq/api/cases/${caseId}${tail}`

		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} Woo-verzoek Stationsweg`,
			caseType: WOO_CASE_TYPE,
		})
		const caseId = objectId(created)

		// No plan, no collection.
		const early = await request.post(api(caseId, '/woo/sources/add'), {
			headers,
			data: { picks: [{ source: 'files', key: '1', custodian: 'A' }] },
		})
		expect(early.status()).toBe(409)

		// The plan is held with the request.
		const plan = await request.put(api(caseId, '/woo/plan'), {
			headers,
			data: {
				custodians: [
					{ name: 'Wethouder Ruimte' },
					{ name: 'Afdeling Vergunningen' },
				],
				systems: ['files'],
				periodFrom: '2025-01-01',
				periodTo: '2025-12-31',
				terms: TERM,
			},
		})
		expect(plan.ok()).toBeTruthy()
		expect((await plan.json()).plan.recordedBy).toBe(ADMIN)

		// Two files with the same bytes in two folders.
		const ids: number[] = []
		for (const folder of ['Team Ruimte', 'Team Verkeer']) {
			const dir = `/remote.php/dav/files/${encodeURIComponent(ADMIN)}/${encodeURIComponent(folder)}`
			await request.fetch(dir, { method: 'MKCOL', headers })
			const put = await request.put(`${dir}/${TERM}.txt`, {
				headers,
				data: `Notities over ${TERM}`,
			})
			expect(put.ok()).toBeTruthy()
			ids.push(
				Number(
					put
						.headers()
						['oc-fileid']?.replace(/^0+/, '')
						.replace(/oc.*$/, '') ?? 0,
				),
			)
		}

		const add = await request.post(api(caseId, '/woo/sources/add'), {
			headers,
			data: {
				terms: TERM,
				picks: ids.map((key) => ({
					source: 'files',
					key: String(key),
					custodian: 'Wethouder Ruimte',
				})),
			},
		})
		const statuses = ((await add.json()).results ?? []).map(
			(result: any) => result.status,
		)
		expect(statuses).toEqual(['added', 'excluded'])

		// Exclude the added document and read the reconciliation.
		const added = (await add.json()).results[0].documentId
		const excluded = await request.post(
			api(caseId, `/woo/documents/${added}/exclude`),
			{
				headers,
				data: { reason: 'out-of-scope', note: 'Gaat over iets anders' },
			},
		)
		expect(excluded.status()).toBe(201)
		const report = await (
			await request.get(api(caseId, '/woo/collection'), { headers })
		).json()
		expect(report.arrived).toBe(2)
		expect(report.excluded).toBe(2)
		expect(report.arrived).toBe(
			report.assessed + report.excluded + report.outstanding,
		)
		const custodians = Object.fromEntries(
			report.custodians.map((row: any) => [row.custodian, row]),
		)
		expect(custodians['Afdeling Vergunningen'].documents).toBe(0)

		// A stored query is re-run.
		await request.post(api(caseId, '/woo/collection/queries'), {
			headers,
			data: { source: 'files', terms: TERM, resultKeys: [String(ids[0])] },
		})
		const queries = (
			await (
				await request.get(api(caseId, '/woo/collection/queries'), {
					headers,
				})
			).json()
		).queries
		const rerun = await (
			await request.post(
				api(caseId, `/woo/collection/queries/${objectId(queries[0])}/rerun`),
				{ headers },
			)
		).json()
		expect(rerun.rows.some((row: any) => row.new === true)).toBeTruthy()

		// Start a new request from this one.
		const next = await seedCase(request, token, {
			title: `${RUN_PREFIX} Woo-verzoek Stationsweg 2`,
			caseType: WOO_CASE_TYPE,
			wooStartFrom: caseId,
		})
		const drafts = await listObjects(request, 'wooSearchPlan', {
			case: objectId(next),
		})
		expect(drafts).toHaveLength(1)
		expect(drafts[0].terms).toBe(TERM)
		expect(drafts[0].periodFrom ?? null).toBeNull()
	})
})
