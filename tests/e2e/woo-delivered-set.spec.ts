/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-delivered-set-is-a-record: a Woo publish writes a frozen set, the set
 * verifies, and an assessment it names refuses change.
 *
 * NOT RUN IN THIS LANE (decision 139). The live pass owns it. The publish
 * needs OpenCatalogi; without it the publishing tests read as skipped.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedDocument,
	updateObject,
} from './helpers/fixtures.ts'

/** The case this suite publishes. */
let caseId = ''

/**
 * The headers a dossiq write needs.
 *
 * @param token The CSRF request token.
 * @return The headers.
 */
function headers(token: string): Record<string, string> {
	return {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		'Content-Type': 'application/json',
	}
}

test.describe('A Woo delivery is a record', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		const caseTypeId = (await ensureCaseType(api, token)).id
		caseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Woo delivered set`,
				caseType: caseTypeId,
				assignee: 'admin',
			}),
		)
		const document = await seedDocument(api, token, {
			title: `${RUN_PREFIX} Openbare brief`,
			case: caseId,
		})
		await api.post(
			`/index.php/apps/${REGISTER}/api/cases/${caseId}/woo/assessment`,
			{
				headers: headers(token),
				data: {
					assessments: [
						{
							documentRef: objectId(document),
							classification: 'openbaar',
						},
					],
				},
			},
		)
		await api.post(
			`/index.php/apps/${REGISTER}/api/cases/${caseId}/woo/decision`,
			{
				headers: headers(token),
				data: {},
			},
		)
		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		await cleanupRunObjects(api, await getRequestToken(api))
		await api.dispose()
	})

	// @e2e openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#scenario-a-publish-writes-a-frozen-set
	// @e2e openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#scenario-an-untouched-set-verifies
	// @e2e openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#scenario-a-delivered-verdict-cannot-be-changed
	test('a publish writes a frozen set that verifies, and its assessment refuses change', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const published = await request.post(
			`/index.php/apps/${REGISTER}/api/cases/${caseId}/woo/publish`,
			{ headers: headers(token), data: {} },
		)
		const body = await published.json()
		test.skip(
			body?.reason === 'opencatalogi_not_installed',
			'OpenCatalogi is not installed on this instance',
		)
		expect(published.ok(), JSON.stringify(body)).toBeTruthy()
		expect(body.deliveredSet).toBeTruthy()

		const sets = await listObjects(request, 'wooDeliveredSet', { case: caseId })
		expect(sets).toHaveLength(1)
		expect(sets[0].status).toBe('frozen')

		const verified = await request.get(
			`/index.php/apps/${REGISTER}/api/cases/${caseId}/woo/delivered-sets/${body.deliveredSet}/verify`,
		)
		const result = await verified.json()
		expect(result.verified).toBe(true)

		const assessments = await listObjects(request, 'wooDocumentAssessment', {
			caseRef: caseId,
		})
		const refused = await updateObject(
			request,
			token,
			'wooDocumentAssessment',
			objectId(assessments[0]),
			{ classification: 'niet_openbaar' },
		).catch((error: Error) => error)
		expect(String(refused)).toMatch(/delivered|aangeleverd/)
	})
})
