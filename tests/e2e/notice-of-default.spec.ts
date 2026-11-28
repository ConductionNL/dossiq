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

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
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
// A term's startDate is a date-time (60-termijnbewaking.json); its end dates are dates.
function day(offset: number): string {
	const d = new Date()
	d.setDate(d.getDate() + offset)
	return d.toISOString().slice(0, 10)
}

let token = ''

/**
 * Give a case's statutory decision term the dates a scenario needs.
 *
 * A new case already carries its statutory term (the case type's decision
 * period from today), and the notice controller reads THAT term. Seeding a
 * second one beside it left the controller on the real one (term-running
 * until eight weeks out), so the scenario's own dates never counted.
 *
 * @param api    The API context.
 * @param caseId The case.
 * @param fields The term's dates and status.
 * @return The term's id.
 */
async function setStatutoryTerm(
	api: APIRequestContext,
	caseId: string,
	fields: Record<string, unknown>,
): Promise<string> {
	const terms = (await listObjects(api, 'deadlineInstance', { case: caseId })).filter(
		(term: any) => term.kind === 'statutory',
	)
	if (terms.length === 0) {
		return objectId(
			await createObject(api, token, 'deadlineInstance', {
				case: caseId,
				kind: 'statutory',
				...fields,
			}),
		)
	}
	const id = objectId(terms[0])
	const res = await api.patch(`/index.php/apps/openregister/api/objects/${REGISTER}/deadlineInstance/${id}`, {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: fields,
	})
	expect(res.ok(), `patch deadlineInstance -> ${res.status()} ${await res.text()}`).toBeTruthy()
	return id
}

test.describe('Notice of default from the case', () => {
	test.setTimeout(120_000)

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

		lateTerm = await setStatutoryTerm(api, lateCase, {
			startDate: `${day(-60)}T09:00:00+00:00`,
			endDateCalculated: day(-3),
			endDateCurrent: day(-3),
			status: 'exceeded',
		})
		await setStatutoryTerm(api, runningCase, {
			startDate: `${day(-46)}T09:00:00+00:00`,
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
			const list = await (
				await request.get(`${APP}/deadlines/notices`, { headers: { requesttoken: token } })
			).json()
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
