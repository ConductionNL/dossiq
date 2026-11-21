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
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { occRunQueuedJobs } from './helpers/occ.ts'
import { asResident, portaliqEnabled, residentSession } from './helpers/portal-resident.ts'

const ACKNOWLEDGEMENT_JOB = 'OCA\\Dossiq\\BackgroundJob\\AcknowledgementDispatchJob'

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
	test('A Woo case with no address never reads as told', async ({
		playwright,
		baseURL,
	}) => {
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

		// The acknowledgement runs from a queued job: wait for its first attempt,
		// running the job by hand while it waits for a cron that may run late.
		await expect
			.poll(
				async () => {
					await occRunQueuedJobs(ACKNOWLEDGEMENT_JOB, caseId).catch(() => [])
					return (await showObject(api, 'case', caseId)).acknowledgementDuty?.status
				},
				{ timeout: 150_000, intervals: [5_000] },
			)
			.toMatch(/^(pending|unmet)$/)

		const extend = await api.post(
			`/index.php/apps/dossiq/api/cases/${caseId}/woo/extend-deadline`,
			{
				headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
				data: { reason: 'Veel documenten van derden, zienswijzen nodig' },
			},
		)
		expect(extend.status(), await extend.text()).toBe(200)
		const extended = await extend.json()
		expect(extended.countExtensions).toBe(1)
		expect(extended.noticeStatus).toBe('not-sent')
		expect(extended.noticeReasonCode).toBe('no-channel')

		const stored = await showObject(api, 'case', caseId)
		expect(stored.acknowledgementDuty.status).not.toBe('met')
		const records = (stored.outboundCommunications ?? []) as Array<
			Record<string, unknown>
		>
		expect(records.length).toBeGreaterThanOrEqual(2)
		for (const record of records) {
			expect(record.status).toBe('not-sent')
			expect(record.messageId).toBeUndefined()
		}

		const timeline = await api.get(
			`/index.php/apps/dossiq/api/cases/${caseId}/timeline`,
			{
				headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			},
		)
		if (timeline.ok()) {
			const entries = JSON.stringify(await timeline.json())
			expect(entries).not.toContain('Ontvangstbevestiging verzonden')
		}

		await api.dispose()
	})

	// @e2e openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#scenario-a-portal-requester-gets-the-notice-in-the-portal-inbox
	// @e2e openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#scenario-the-duty-is-met-when-the-acknowledgement-went-out
	test('A Woo request from the portal is acknowledged in the portal inbox', async ({
		playwright,
		baseURL,
	}) => {
		test.skip(!(await portaliqEnabled()), 'portaliq is not installed on this instance')

		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const identifier = `${RUN_PREFIX.toLowerCase()}-woo-portal`
		const caseType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Woo-verzoek portaal`,
			identifier,
			isDraft: false,
			processingDeadline: 'P28D',
		})
		await createObject(api, token, 'deadlineDefinition', {
			caseType: identifier,
			legalBasis: 'Woo art 4.4',
			validFrom: '2026-01-01',
			standardDurationDays: 28,
		})

		const subject = `${RUN_PREFIX.toLowerCase()}-inwoner`
		const created = await seedCase(api, token, {
			title: `${RUN_PREFIX} Woo-verzoek uit het portaal`,
			caseType: objectId(caseType),
			intakeChannel: 'website',
			portalSubject: subject,
		})
		const caseId = objectId(created)

		await expect
			.poll(
				async () => {
					await occRunQueuedJobs(ACKNOWLEDGEMENT_JOB, caseId).catch(() => [])
					return (await showObject(api, 'case', caseId)).acknowledgementDuty?.status
				},
				{ timeout: 150_000, intervals: [5_000] },
			)
			.toBe('met')

		const inbox = await listObjects(api, 'portaalBericht', { recipientRef: subject })
		const notice = inbox.find((row: any) => row.caseId === caseId)
		expect(notice, 'the acknowledgement is in the requester\'s portal inbox').toBeTruthy()
		expect(notice.direction).toBe('handler_to_citizen')

		const stored = await showObject(api, 'case', caseId)
		const records = (stored.outboundCommunications ?? []) as Array<Record<string, unknown>>
		const sent = records.find((record) => record.status === 'sent')
		expect(sent, JSON.stringify(records)).toBeTruthy()
		expect(sent?.channel).toBe('portal-inbox')
		expect(sent?.messageId).toBe(objectId(notice))

		// The resident reads it in the portal inbox, as the resident.
		const session = await residentSession(api, subject)
		expect(session, 'portaliq dev-login mints a resident session').not.toBeNull()
		const inboxRead = await asResident(api, session!, '/inbox')
		expect(inboxRead.status, JSON.stringify(inboxRead.body)).toBe(200)
		expect(JSON.stringify(inboxRead.body.messages ?? [])).toContain(objectId(notice))

		await api.dispose()
	})
})
