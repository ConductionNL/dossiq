/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A Woo term obeys the Algemene termijnenwet through the API, and extends once.
 *
 * The roll and the ceiling are asserted with a fixed calendar in the unit
 * tests (CaseDeadlineMirrorTest, WOODeadlineServiceTest), on the term engine
 * one-term-engine built. What only the real stack can show is that the case
 * OpenRegister stores carries the statutory instance's rolled date, that the
 * fallback calculation does not write the unrolled one back over it, and that
 * the second extension reaches the caller as a 409.
 *
 * Start 2026-11-27 plus four weeks ends on Christmas Day, which every Dutch
 * calendar marks, and Boxing Day and the Sunday after it; the term ends on
 * Monday 2026-12-28. The one extension counts from the end as counted
 * (Friday 2026-12-25), not from the rolled Monday: Friday 2027-01-08.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'

test.describe('A Woo term is computed by law', () => {
	test.setTimeout(180_000)

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/one-term-engine/specs/woo-case-type/spec.md#scenario-a-woo-term-ending-on-a-holiday-rolls
	// @e2e openspec/changes/one-term-engine/specs/woo-case-type/spec.md#scenario-the-extension-counts-from-the-original-end-unrolled
	// @e2e openspec/changes/one-term-engine/specs/woo-case-type/spec.md#scenario-only-one-extension-allowed
	test('The deadline rolls past Christmas, and the term extends once', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const identifier = `${RUN_PREFIX.toLowerCase()}-woo`
		const caseType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Woo-verzoek`,
			identifier,
			isDraft: false,
			processingDeadline: 'P28D',
			extensionAllowed: true,
			extensionPeriod: 'P14D',
		})
		await createObject(api, token, 'deadlineDefinition', {
			caseType: identifier,
			legalBasis: 'Woo art 4.4',
			validFrom: '2026-01-01',
			standardDurationDays: 28,
			countExtensions: 1,
		})

		const created = await seedCase(api, token, {
			title: `${RUN_PREFIX} Woo-verzoek over de ringweg`,
			caseType: objectId(caseType),
			startDate: '2026-11-27',
		})
		const caseId = objectId(created)

		const stored = await showObject(api, 'case', caseId)
		expect(String(stored.deadline).slice(0, 10)).toBe('2026-12-28')

		const extend = (reason: string) =>
			api.post(
				`/index.php/apps/dossiq/api/cases/${caseId}/woo/extend-deadline`,
				{
					headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
					data: { reason },
				},
			)

		const first = await extend('Zienswijzen van derden')
		expect(first.status(), await first.text()).toBe(200)
		const extended = await first.json()
		expect(extended.countExtensions).toBe(1)
		// The counted end 2026-12-25 plus two weeks is Friday 2027-01-08: no roll needed.
		expect(extended.deadline).toBe('2027-01-08')

		const second = await extend('Nog meer tijd nodig')
		expect(second.status()).toBe(409)
		expect((await second.json()).error).toBe('extension-ceiling-reached')

		const after = await showObject(api, 'case', caseId)
		expect(String(after.deadline).slice(0, 10)).toBe('2027-01-08')

		await api.dispose()
	})
})
