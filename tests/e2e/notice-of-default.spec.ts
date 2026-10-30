/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A notice of default registered from the case, and the payout of its
 * definitive penalty (notice-of-default-from-the-case REQ-ING-CASE-01/02).
 *
 * These go through the routes the two dialogs call, because the verdicts are
 * the server's: which term the notice lands on, the refusal while the term
 * runs, and the payout raised through the payout route. The dialogs' own
 * wiring is covered by tests/vitest/noticeOfDefaultFromTheCase.spec.js.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	seedCase,
	trackCreatedObject,
} from './helpers/fixtures.ts'

const APP = '/index.php/apps/dossiq/api'

/**
 * A day relative to today, as YYYY-MM-DD.
 *
 * @param offset Days from today.
 * @return The calendar day.
 */
function day(offset: number): string {
	const d = new Date()
	d.setDate(d.getDate() + offset)
	return d.toISOString().slice(0, 10)
}

test.describe('Notice of default from the case', () => {
	test.setTimeout(120_000)

	let token = ''
	let lateCase = ''
	let runningCase = ''
	let lateTerm = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)

		lateCase = objectId(
			await seedCase(api, token, {
				title: 'Notice after the term',
				caseType: caseType.id,
			}),
		)
		runningCase = objectId(
			await seedCase(api, token, {
				title: 'Notice before the term ends',
				caseType: caseType.id,
			}),
		)

		lateTerm = objectId(
			await createObject(api, token, 'deadlineInstance', {
				case: lateCase,
				kind: 'statutory',
				startDate: day(-60),
				endDateCalculated: day(-3),
				endDateCurrent: day(-3),
				status: 'exceeded',
			}),
		)
		await createObject(api, token, 'deadlineInstance', {
			case: runningCase,
			kind: 'statutory',
			startDate: day(-46),
			endDateCalculated: day(10),
			endDateCurrent: day(10),
			status: 'lopend',
		})
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		await cleanupRunObjects(api, await getRequestToken(api), [
			'dwangsomUitbetaling',
			'penaltyPaymentCalculation',
			'noticeOfDefault',
			'deadlineInstance',
			'case',
		])
	})

	test('a notice after the term starts the accrual (REQ-ING-CASE-01)', async ({
		request,
	}) => {
		const res = await request.post(`${APP}/notices-of-default`, {
			headers: { requesttoken: token, 'OCS-APIREQUEST': 'true' },
			data: {
				caseId: lateCase,
				receiptDate: day(0),
				notificationChannel: 'post',
				sender: 'Applicant',
			},
		})
		expect(res.status(), await res.text()).toBe(201)
		const notice = await res.json()
		trackCreatedObject('noticeOfDefault', String(notice.id))
		expect(notice.validityStatus).toBe('valid')
		expect(notice.sender).toBe('Applicant')
		expect(notice.penaltyPaymentCalculation?.startDate >= day(14)).toBe(true)
	})

	test('a notice before the term ends is refused (REQ-ING-CASE-01)', async ({
		request,
	}) => {
		const res = await request.post(`${APP}/notices-of-default`, {
			headers: { requesttoken: token, 'OCS-APIREQUEST': 'true' },
			data: {
				caseId: runningCase,
				receiptDate: day(0),
				notificationChannel: 'post',
			},
		})
		expect(res.status()).toBe(409)
		const body = await res.json()
		expect(body.refusal).toBe('term-running')
		expect(body.termEnds).toBe(day(10))
	})

	test('preparing the payout sends the signal (REQ-ING-CASE-02)', async ({
		request,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const notice = await createObject(api, token, 'noticeOfDefault', {
			deadlineInstance: lateTerm,
			receiptDate: day(-40),
			notificationChannel: 'post',
			validityStatus: 'valid',
		})
		const calculation = await createObject(
			api,
			token,
			'penaltyPaymentCalculation',
			{
				noticeOfDefault: objectId(notice),
				deadlineInstance: lateTerm,
				startDate: day(-26),
				status: 'completed',
				cumulativeAmount: 144200,
				definitiveAmount: 144200,
			},
		)

		const rowOf = async () => {
			const list = await (await request.get(`${APP}/deadlines/notices`)).json()
			return (list.notices || []).find(
				(row: any) => row.id === objectId(notice),
			)
		}
		expect((await rowOf())?.financeState).toBe('payout-due')

		const res = await request.post(
			`${APP}/accruing-amounts/${objectId(calculation)}/payout`,
			{
				headers: { requesttoken: token, 'OCS-APIREQUEST': 'true' },
				data: {
					accountHolderName: 'J. de Vries',
					iban: 'NL91ABNA0417164300',
				},
			},
		)
		expect(res.status(), await res.text()).toBe(201)
		const payout = await res.json()
		if (payout.id && !payout.payableId) {
			trackCreatedObject('dwangsomUitbetaling', String(payout.id))
		}

		// With a finance app the signal went out; without one the payout is prepared here.
		expect((await rowOf())?.financeState).toBe(
			payout.payableId ? 'signal-sent' : 'prepared',
		)
	})
})
