/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every procedure-named address the UI used moved to a generic one, and the
 * old address stays routed: a bookmarked call, an external report reader or a
 * payment provider configured with it keeps getting the same answer.
 *
 * An unmatched GET under /apps/dossiq is answered by the app's catch-all with
 * the HTML page and HTTP 200, so "not 404" proves nothing for a read. Each pair
 * is compared on status AND on answering JSON.
 */

import type {APIRequestContext} from '@playwright/test';

import { expect, test } from '@playwright/test'
import { getRequestToken } from './helpers/fixtures.ts'

const API = '/index.php/apps/dossiq/api'
const NOBODY = '00000000-0000-0000-0000-000000000000'

/** [verb, generic address, earlier address]. */
const PAIRS: Array<[string, string, string]> = [
	['GET', '/checklists', '/vth/checklists'],
	['GET', `/cases/${NOBODY}/checklist-results`, `/vth/cases/${NOBODY}/inspection-results`],
	['POST', `/cases/${NOBODY}/checklist-results`, `/vth/cases/${NOBODY}/inspection-result`],
	['GET', '/deadlines/dashboard/kpi', '/termijn/dashboard/kpi'],
	['GET', '/deadlines/reports/quarterly?period=2026-Q1', '/termijn/reports/kwartaal?periode=2026-Q1'],
	['GET', '/deadlines/reports/annual?year=2026', '/termijn/reports/jaarrekening?jaar=2026'],
	['GET', '/deadlines/reports/first-response', '/termijn/reports/eerste-reactie'],
	['GET', '/deadlines/reports/open-workload-age', '/termijn/reports/open-workload-age'],
	['GET', '/deadlines/definitions', '/termijn/definities'],
	['GET', '/deadlines/calendar', '/termijn/calendar'],
	['GET', `/deadlines/instances/${NOBODY}`, `/termijn/instances/${NOBODY}`],
	['POST', `/deadlines/instances/${NOBODY}/pause`, `/termijn/instances/${NOBODY}/pauze`],
	['POST', `/deadlines/instances/${NOBODY}/resume`, `/termijn/instances/${NOBODY}/hervat`],
	['POST', `/deadlines/instances/${NOBODY}/extend`, `/termijn/instances/${NOBODY}/verleng`],
	['POST', `/deadlines/instances/${NOBODY}/complete`, `/termijn/instances/${NOBODY}/voltooi`],
	['POST', '/payments/callback', '/dossiq/openconnector/dwangsom-payment-callback'],
	['GET', '/document-review/reports/throughput?from=2026-01-01&to=2026-01-31', '/woo/reports/throughput?from=2026-01-01&to=2026-01-31'],
]

let api: APIRequestContext
let token = ''

/**
 * Call one address and say how it answered.
 *
 * @param verb The verb.
 * @param url  The address under the app's API.
 * @return The status and whether the body is JSON.
 */
async function call(verb: string, url: string): Promise<{ status: number, json: boolean }> {
	const response = await api.fetch(API + url, {
		method: verb,
		headers: { requesttoken: token, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
		data: verb === 'GET' ? undefined : {},
	})
	return {
		status: response.status(),
		json: (response.headers()['content-type'] ?? '').includes('application/json'),
	}
}

test.describe('Renamed API addresses keep their old address', () => {
	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		await api.dispose()
	})

	// @e2e openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md#an-old-address-answers-as-the-new-one
	for (const [verb, generic, earlier] of PAIRS) {
		test(`${verb} ${earlier} answers as ${generic}`, async () => {
			const now = await call(verb, generic)
			const before = await call(verb, earlier)

			expect(now.status, `${verb} ${generic} is routed`).not.toBe(404)
			expect(now.json, `${verb} ${generic} answers JSON, not the app page`).toBe(true)
			expect(before).toEqual(now)
		})
	}

	// @e2e openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md#a-bookmarked-page-address-lands-on-the-renamed-page
	test('the earlier reports page address lands on the document review reports page', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq/woo-reports')
		await expect(page).toHaveURL(/\/document-review-reports$/)
	})
})
