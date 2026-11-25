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
import { occRun, occRunQueuedJobs } from './helpers/occ.ts'

const ACKNOWLEDGEMENT_JOB = 'OCA\\Dossiq\\BackgroundJob\\AcknowledgementDispatchJob'

test.describe('A Woo requester hears from us, or the case says they did not', () => {
	test.setTimeout(240_000)

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/burger-notifications/spec.md#scenario-no-address-at-all
	// @e2e openspec/specs/burger-notifications/spec.md#scenario-the-duty-is-not-recorded-as-met-when-nothing-went-out
	// @e2e openspec/specs/burger-notifications/spec.md#scenario-the-extension-notice-could-not-go-out
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

	// @e2e openspec/specs/burger-notifications/spec.md#scenario-a-portal-requester-gets-the-notice-in-the-portal-inbox
	// @e2e openspec/specs/burger-notifications/spec.md#scenario-the-duty-is-met-when-the-acknowledgement-went-out
	test('A Woo request from the portal is acknowledged in the portal inbox and the duty reads met', async ({
		playwright,
		baseURL,
	}) => {
		// The portal inbox is portaliq's: without it the sender rightly moves on
		// to the next channel, so this scenario has nothing to show.
		const apps = await occRun(['app:list', '--enabled', '--output=json']).catch(() => null)
		test.skip(
			apps === null || !apps.output.includes('"portaliq"'),
			'portaliq is not enabled on this instance (or occ is unreachable), so there is no portal inbox.',
		)

		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const identifier = `${RUN_PREFIX.toLowerCase()}-woo-portal-notices`
		const caseType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Woo-verzoek portaal`,
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

		const resident = `${RUN_PREFIX}-inwoner-woo`
		const created = await seedCase(api, token, {
			title: `${RUN_PREFIX} Woo-verzoek uit het portaal`,
			caseType: objectId(caseType),
			intakeChannel: 'website',
			portalSubject: resident,
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

		const stored = await showObject(api, 'case', caseId)
		expect(stored.acknowledgementDuty.channel).toBe('portal-inbox')

		const records = (stored.outboundCommunications ?? []) as Array<Record<string, unknown>>
		const sent = records.find((record) => record.status === 'sent' && record.channel === 'portal-inbox')
		expect(sent, `a sent portal-inbox record among ${JSON.stringify(records)}`).toBeTruthy()

		const messages = await listObjects(api, 'portaalBericht', { caseId })
		const inbox = messages.filter(
			(message: any) => message.recipientRef === resident && message.direction === 'handler_to_citizen',
		)
		expect(inbox, 'one acknowledgement in the resident\'s portal inbox').toHaveLength(1)
		expect(objectId(inbox[0])).toBe(String(sent?.messageId ?? ''))

		const timeline = await api.get(
			`/index.php/apps/dossiq/api/cases/${caseId}/timeline`,
			{
				headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			},
		)
		if (timeline.ok()) {
			expect(JSON.stringify(await timeline.json())).toContain('Ontvangstbevestiging verzonden')
		}

		await api.dispose()
	})
})
