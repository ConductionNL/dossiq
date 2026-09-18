/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Decision outcomes on the case: the approval gate, the inadmissible close and
 * the remedy clause, driven through dossiq's own endpoints on a real instance.
 *
 * WHY THIS NEEDS A REAL INSTANCE. The rules are unit-tested to the sentence:
 * `ApprovalGuardTest` drives the gate through the real guard registry,
 * `AdmissibilityJudgementTest` pins the close and the judge,
 * `RemedyClauseDeclarationTest` and `RemedyTermBindingTest` pin the clause and
 * the clock. What none of those can show is that the declarations SURVIVE the
 * register import (OpenRegister drops an undeclared property in silence), and
 * that the transition endpoint the acts dialog posts to really runs the gate.
 *
 * 🔴 THE GATE IS ASSERTED ON THE DOOR THE PAGE USES. dossiq's own
 * `/case/{id}/transition` is what the acts dialog posts to. A gate that only
 * held on OpenRegister's lifecycle provider would pass a provider-only test
 * and let the page send the besluit anyway, which is how this change first
 * shipped on its branch.
 *
 * 🔑 decidiq IS OPTIONAL. A case waiting on an approval decidiq cannot answer
 * is refused either way, so the refusal scenarios run without it. The two that
 * need decidiq to WALK an approval skip with the reason named when it is absent.
 *
 * ASSERT IDS AND STORED FACTS, NOT LABELS: nothing forces the language of the
 * e2e instance, so the only text asserted is text this fixture seeded.
 */

import type { APIRequestContext } from '@playwright/test'
import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	executeTransition,
	getAvailableTransitions,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
	tryDeleteObject,
	updateObject,
} from './helpers/fixtures.ts'

const DOSSIQ_API = '/index.php/apps/dossiq/api'
const APPROVAL_LABEL = `${RUN_PREFIX} goedkeuring teamleider`
const DECIDIQ_ABSENT =
	'decidiq is not enabled on this instance, so no approval can be walked. The refusal scenarios above still ran.'

/** Server-made rows this spec leaves behind and removes itself. */
const serverMade: Array<[string, string]> = []

/**
 * Whether an app is enabled on the instance under test.
 *
 * @param api   Authenticated request context.
 * @param appId The app id.
 * @return Whether it is enabled.
 */
async function appEnabled(api: APIRequestContext, appId: string): Promise<boolean> {
	const res = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(res.ok(), `The enabled-apps list answered ${res.status()}.`).toBeTruthy()
	const apps = ((await res.json())?.ocs?.data?.apps ?? []) as string[]
	return apps.includes(appId)
}

/**
 * JSON write headers for dossiq's own endpoints.
 *
 * @param token CSRF request-token.
 * @return The headers.
 */
function headers(token: string): Record<string, string> {
	return { requesttoken: token, 'Content-Type': 'application/json' }
}

test.describe('decision outcomes on the case', () => {
	test.describe.configure({ mode: 'serial' })

	test.afterAll(async ({ request }) => {
		const token = await getRequestToken(request)
		for (const [schema, id] of serverMade.reverse()) {
			await tryDeleteObject(request, token, schema, id)
		}
		await cleanupRunObjects(request, token)
	})

	/**
	 * A state machine whose first move waits for an approval.
	 *
	 * @param request Authenticated request context.
	 * @param token   CSRF request-token.
	 * @return The machine and a case in its first status.
	 */
	async function gatedCase(request: APIRequestContext, token: string) {
		const machine = await seedStateMachine(request, token)
		await updateObject(request, token, 'caseType', machine.caseTypeId, {
			approvalGates: [
				{ act: 't1', decisionType: 'besluit-approval', label: APPROVAL_LABEL },
			],
		})
		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} dakkapel`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		return { machine, caseId: objectId(created) }
	}

	// @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#scenario-the-case-waits-for-the-signatures
	test('a besluit is refused while its approval is outstanding, and the refusal names it', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseId } = await gatedCase(request, token)

		// The declaration survived the register import. Without the schema
		// property OpenRegister drops it, and every later assertion would pass
		// against a case type that gates nothing.
		const stored = await showObject(
			request,
			'caseType',
			(await showObject(request, 'case', caseId)).caseType,
		)
		expect(stored.approvalGates?.[0]?.act).toBe('t1')

		// Offered, and blocked with the approval named, on the list the acts
		// dialog reads.
		const offered = await getAvailableTransitions(request, token, caseId)
		const move = (offered.body.transitions ?? []).find((t: any) => t.id === 't1')
		expect(move, JSON.stringify(offered.body)).toBeTruthy()
		expect(move.guardsPassed).toBe(false)
		const approval = (move.failedGuards ?? []).find((g: any) => g.type === 'approvalGate')
		expect(approval?.failureMessage).toContain(APPROVAL_LABEL)

		// And refused on the write the dialog posts, with nothing moved.
		const refused = await executeTransition(request, token, caseId, 't1')
		expect(refused.status).toBeGreaterThanOrEqual(400)
		expect(refused.status).toBeLessThan(500)
		expect(JSON.stringify(refused.body)).toContain(APPROVAL_LABEL)
		const after = await showObject(request, 'case', caseId)
		expect(String(after.status ?? '')).not.toBe('')
		expect(after.approvalRefs ?? []).toEqual([])
	})

	// @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#scenario-the-case-says-who-it-is-waiting-for
	test('the case says what it is waiting for, and on whom', async ({ request }) => {
		const token = await getRequestToken(request)
		const { caseId } = await gatedCase(request, token)

		const acts = await request.get(`${DOSSIQ_API}/case/${caseId}/acts`)
		expect(acts.status()).toBe(200)
		const waiting = (await acts.json()).awaitingApproval ?? []
		expect(waiting).toHaveLength(1)
		expect(waiting[0].act).toBe('t1')
		expect(waiting[0].label).toBe(APPROVAL_LABEL)
		// The names are decidiq's. The list is either the people decidiq named
		// or empty with the sentence saying decidiq named nobody; it is never
		// an empty list presented as "waiting on nobody".
		expect(Array.isArray(waiting[0].approvers)).toBe(true)
		expect(String(waiting[0].sentence)).toContain(APPROVAL_LABEL)

		test.skip(!(await appEnabled(request, 'decidiq')), DECIDIQ_ABSENT)

		const raised = await request.post(`${DOSSIQ_API}/case/${caseId}/approvals/t1`, {
			headers: headers(token),
		})
		expect(raised.status(), await raised.text()).toBe(200)
		const link = await raised.json()
		expect(link.decisionRef).toBeTruthy()

		const again = await (await request.get(`${DOSSIQ_API}/case/${caseId}/acts`)).json()
		const row = (again.awaitingApproval ?? []).find((w: any) => w.act === 't1')
		expect(row?.decisionRef).toBe(link.decisionRef)
		// Named people, or the sentence that says decidiq named none: decidiq's
		// outcome envelope does not publish its approvers yet (decidiq#1316).
		if ((row?.approvers ?? []).length > 0) {
			for (const name of row.approvers) {
				expect(String(row.sentence)).toContain(name)
			}
		} else {
			expect(String(row?.sentence ?? '')).toContain('decidiq')
		}
	})

	// @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#scenario-an-approved-case-proceeds
	test('an approval decidiq reports as granted lets the besluit through', async ({ request }) => {
		test.skip(!(await appEnabled(request, 'decidiq')), DECIDIQ_ABSENT)

		const token = await getRequestToken(request)
		const { caseId } = await gatedCase(request, token)

		const raised = await request.post(`${DOSSIQ_API}/case/${caseId}/approvals/t1`, {
			headers: headers(token),
		})
		expect(raised.status(), await raised.text()).toBe(200)
		const { decisionRef } = await raised.json()

		// Walk the decision to decided through decidiq's own endpoint, the same
		// route the live journey spec takes.
		await request.put(
			`/index.php/apps/openregister/api/objects/decidiq/decision/${decisionRef}`,
			{
				headers: { ...headers(token), 'OCS-APIRequest': 'true' },
				data: {
					text: `${RUN_PREFIX} goedgekeurd`,
					outcome: 'adopted',
					decisionDate: new Date().toISOString(),
				},
			},
		)
		for (const action of ['propose', 'deliberate', 'openVoting', 'decide']) {
			await request.post(`/index.php/apps/decidiq/api/decisions/${decisionRef}/transition`, {
				headers: headers(token),
				data: { action },
			})
		}

		const moved = await executeTransition(request, token, caseId, 't1')
		expect(moved.status, JSON.stringify(moved.body)).toBe(200)
	})

	/**
	 * A case type that judges admissibility, with a result to close on and the
	 * moment that tells the applicant.
	 *
	 * @param request Authenticated request context.
	 * @param token   CSRF request-token.
	 * @return The case and the result type it closes on.
	 */
	async function intakeCase(request: APIRequestContext, token: string) {
		const machine = await seedStateMachine(request, token)
		const result = objectId(
			await createObject(request, token, 'resultType', {
				name: `${RUN_PREFIX} Niet-ontvankelijk`,
				caseType: machine.caseTypeId,
			}),
		)
		await updateObject(request, token, 'caseType', machine.caseTypeId, {
			admissibilityJudgement: { enabled: true, inadmissibleResultType: result },
			notificationMoments: [
				{ moment: 'case-inadmissible', template: 'niet-ontvankelijk', enabled: true },
			],
		})
		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} te laat`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
			email: `${RUN_PREFIX.toLowerCase()}@example.invalid`,
		})

		return { caseId: objectId(created), result }
	}

	// @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#scenario-an-inadmissible-aanvraag-does-not-sit-in-intake
	test('an inadmissible verdict closes the case with that result, and records the judge', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseId, result } = await intakeCase(request, token)

		const res = await request.post(`${DOSSIQ_API}/cases/${caseId}/admissibility`, {
			headers: headers(token),
			data: { verdict: 'inadmissible', reason: `${RUN_PREFIX} buiten de termijn ingediend` },
		})
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json()
		expect(body.closed).toBe(true)

		const stored = await showObject(request, 'case', caseId)
		expect(stored.result).toBe(result)
		expect(stored.endDate).toBeTruthy()
		expect(stored.endingAct).toBe('finish')
		expect(stored.admissibility?.verdict).toBe('inadmissible')
		expect(stored.admissibility?.judgedBy).toBeTruthy()
		expect(stored.admissibility?.reason).toContain(RUN_PREFIX)
	})

	// @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#scenario-the-applicant-is-told
	test('the applicant is told through the declared moment', async ({ request }) => {
		const token = await getRequestToken(request)
		const { caseId } = await intakeCase(request, token)

		const res = await request.post(`${DOSSIQ_API}/cases/${caseId}/admissibility`, {
			headers: headers(token),
			data: { verdict: 'inadmissible', reason: `${RUN_PREFIX} onvolledig` },
		})
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json()
		expect(body.moment).toBe('case-inadmissible')
		expect(body.applicantMessage?.template).toBe('niet-ontvankelijk')
		expect(body.applicantTold, JSON.stringify(body.applicantMessage)).toBe(true)
	})

	/**
	 * A case whose type declares a remedy.
	 *
	 * @param request Authenticated request context.
	 * @param token   CSRF request-token.
	 * @param days    The declared term.
	 * @return The case id.
	 */
	async function caseWithRemedy(request: APIRequestContext, token: string, days: number) {
		const machine = await seedStateMachine(request, token)
		await updateObject(request, token, 'caseType', machine.caseTypeId, {
			remedy: { kind: 'bezwaar', termDays: days, body: `het college ${RUN_PREFIX}` },
		})
		const created = await seedCase(request, token, {
			title: `${RUN_PREFIX} besluit ${days}`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})

		return objectId(created)
	}

	/**
	 * Compose a besluit on a case.
	 *
	 * @param request Authenticated request context.
	 * @param token   CSRF request-token.
	 * @param caseId  The case.
	 * @return The composed beschikking.
	 */
	async function compose(request: APIRequestContext, token: string, caseId: string) {
		const res = await request.post(`${DOSSIQ_API}/beschikkingen`, {
			headers: headers(token),
			data: { caseId, templateId: 'tpl-default', rationale: `${RUN_PREFIX} motivering` },
		})
		expect(res.status(), await res.text()).toBe(201)
		const decision = await res.json()
		serverMade.push(['beschikking', objectId(decision)])
		return decision
	}

	// @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#scenario-a-besluit-carries-its-bezwaarclausule
	test('a besluit prints the bezwaarclausule its case type declares', async ({ request }) => {
		const token = await getRequestToken(request)
		const decision = await compose(request, token, await caseWithRemedy(request, token, 42))

		const clause = String(decision.legalRemediesClause ?? '')
		expect(clause).toContain('bezwaar')
		expect(clause).toContain('42')
		expect(clause).toContain(`het college ${RUN_PREFIX}`)
	})

	// @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#scenario-a-change-in-the-law-is-one-configuration-change
	test('two case types on one template print their own terms', async ({ request }) => {
		const token = await getRequestToken(request)
		const six = await compose(request, token, await caseWithRemedy(request, token, 42))
		const four = await compose(request, token, await caseWithRemedy(request, token, 28))

		expect(six.templateId).toBe(four.templateId)
		expect(String(six.legalRemediesClause)).toContain('42')
		expect(String(four.legalRemediesClause)).toContain('28')
		expect(String(four.legalRemediesClause)).not.toContain('42')
	})

	// @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#scenario-the-bezwaartermijn-starts-when-the-besluit-goes-out
	test('sending the besluit binds a remedy term on the declared days', async ({ request }) => {
		const token = await getRequestToken(request)
		const caseId = await caseWithRemedy(request, token, 42)
		const id = objectId(await compose(request, token, caseId))

		// Straight to sent through the stored state: the mandate and signing
		// steps are the beschikking lane's and have their own spec.
		await updateObject(request, token, 'beschikking', id, { currentStatus: 'signed' })
		const sent = await request.patch(`${DOSSIQ_API}/beschikkingen/${id}/verzend`, {
			headers: headers(token),
		})
		expect(sent.status(), await sent.text()).toBe(200)

		const terms = await (await request.get(`${DOSSIQ_API}/cases/${caseId}/terms`)).json()
		const remedy = (terms.terms ?? terms ?? []).find((t: any) => t.kind === 'remedy')
		expect(remedy, JSON.stringify(terms)).toBeTruthy()
		serverMade.push(['deadlineInstance', String(remedy.id)])
		const span =
			(new Date(remedy.endDate).getTime() - new Date(remedy.startDate.slice(0, 10)).getTime()) /
			86_400_000
		// 42 days, then rolled onto the administered working calendar, which
		// can only move the end LATER.
		expect(span).toBeGreaterThanOrEqual(42)
		expect(span).toBeLessThan(42 + 7)
	})

	// @spec openspec/changes/decision-outcomes-on-the-case/specs/beschikking-generatie/spec.md#scenario-is-this-still-open-to-bezwaar
	test('a remedy term sent fifty days ago on 42 days reads as expired', async ({ request }) => {
		const token = await getRequestToken(request)
		const caseId = await caseWithRemedy(request, token, 42)
		const sent = new Date(Date.now() - 50 * 86_400_000)
		const end = new Date(sent.getTime() + 42 * 86_400_000)

		const term = await createObject(request, token, 'deadlineInstance', {
			case: caseId,
			kind: 'remedy',
			startDate: sent.toISOString(),
			endDateCalculated: end.toISOString().slice(0, 10),
			endDateCurrent: end.toISOString().slice(0, 10),
			status: 'lopend',
			countExtensions: 0,
			notificatiesVerstuurd: [],
		})
		serverMade.push(['deadlineInstance', objectId(term)])

		const terms = await (await request.get(`${DOSSIQ_API}/cases/${caseId}/terms`)).json()
		const remedy = (terms.terms ?? terms ?? []).find((t: any) => t.kind === 'remedy')
		expect(remedy, JSON.stringify(terms)).toBeTruthy()
		expect(remedy.overdue).toBe(true)
		expect(remedy.daysLeft).toBeLessThan(0)
	})
})
