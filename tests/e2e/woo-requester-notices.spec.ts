/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A Woo requester hears from us, or the case says they did not.
 *
 * The channel order, the refusals and the record are asserted in the unit
 * tests (RequesterNoticeSenderTest, AcknowledgementDutyTest,
 * WOOAssessmentControllerExtensionNoticeTest). What only the real stack can
 * show is that the stored case carries the record OpenRegister accepted, that
 * a case with no address never reads the duty as met, and that no public
 * timeline line says a notice went out that did not.
 *
 * Not run in a build lane (decision 139): the live pass runs it.
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

test.describe('A Woo requester hears from us, or the case says they did not', () => {
	test.setTimeout(240_000)

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#scenario-no-address-at-all
	// @e2e openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#scenario-the-duty-is-not-recorded-as-met-when-nothing-went-out
	// @e2e openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#scenario-the-extension-notice-could-not-go-out
	test('A Woo case with no address never reads as told', async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const identifier = `${RUN_PREFIX.toLowerCase()}-woo-notices`
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
			title: `${RUN_PREFIX} Woo-verzoek zonder adres`,
			caseType: objectId(caseType),
			intakeChannel: 'website',
		})
		const caseId = objectId(created)

		// The acknowledgement runs from a queued job: wait for its first attempt.
		await expect
			.poll(async () => (await showObject(api, 'case', caseId)).acknowledgementDuty?.status, { timeout: 150_000 })
			.toMatch(/^(pending|unmet)$/)

		const extend = await api.post(`/index.php/apps/dossiq/api/cases/${caseId}/woo/extend-deadline`, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			data: { reason: 'Veel documenten van derden, zienswijzen nodig' },
		})
		expect(extend.status(), await extend.text()).toBe(200)
		const extended = await extend.json()
		expect(extended.countExtensions).toBe(1)
		expect(extended.noticeStatus).toBe('not-sent')
		expect(extended.noticeReasonCode).toBe('no-channel')

		const stored = await showObject(api, 'case', caseId)
		expect(stored.acknowledgementDuty.status).not.toBe('met')
		const records = (stored.outboundCommunications ?? []) as Array<Record<string, unknown>>
		expect(records.length).toBeGreaterThanOrEqual(2)
		for (const record of records) {
			expect(record.status).toBe('not-sent')
			expect(record.messageId).toBeUndefined()
		}

		const timeline = await api.get(`/index.php/apps/dossiq/api/cases/${caseId}/timeline`, {
			headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
		})
		if (timeline.ok()) {
			const entries = JSON.stringify(await timeline.json())
			expect(entries).not.toContain('Ontvangstbevestiging verzonden')
		}

		await api.dispose()
	})
})
