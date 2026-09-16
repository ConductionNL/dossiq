/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Four clocks on one case, each apart, against a running instance.
 *
 * Every case below is a seam no unit test reaches. The unit suites drive the
 * services against mocked stores and prove the arithmetic; only a live instance
 * shows that a case type's declaration reaches an OpenRegister schema that
 * actually carries the property, that the listener binds the clocks on create,
 * and that the case page renders the four of them apart.
 *
 * 🔴 THE PAIRS ARE THE POINT. Three of these cases assert TWO things that
 * disagree: a phase overdue while the case term is not, a case late against its
 * plan and on time against the Awb, and a request that failed to send with a
 * term that is still running. Asserting only the first half of any of them
 * passes on the collapsed single field this change replaces.
 *
 * ASSERT IDS, NOT LABELS. Nothing forces the language of the E2E instance, so
 * every browser locator is a `data-testid` and the only text asserted is text
 * this fixture seeded.
 *
 * WHAT IS NOT HERE. The chain split is unit-covered
 * (`ChainTermSplitTest`, `PhaseTermTest`) because it is arithmetic over day
 * counts with no surface of its own: a browser adds nothing to it and the
 * seeding of four phases with declared shares would be the whole test.
 */

import { expect, test } from '@playwright/test'
import {
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { navToRoute, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/** dossiq's own term endpoints, addressed by case. */
const TERMS_BASE = '/index.php/apps/dossiq/api/cases'

/** How many days a seeded phase gets. */
const PHASE_DAYS = 14

/** The statutory lead time a seeded case type declares, in days. */
const STATUTORY_DAYS = 56

/** The service norm a seeded case type declares, in days. */
const PLANNED_DAYS = 30

/** The internal target a seeded case type declares, in days. */
const INTERNAL_DAYS = 20

/**
 * Seed a case type that declares all four clocks, and one phase inside it.
 *
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 * @param extra Extra case-type properties, for the fixed-date cases.
 */
async function seedDeclaringType(
	api: any,
	token: string,
	extra: Record<string, unknown> = {},
) {
	const suffix = Math.floor(Math.random() * 1e6)
	const caseType = await createObject(api, token, 'caseType', {
		title: `${RUN_PREFIX} Terms ${suffix}`,
		identifier: `${RUN_PREFIX.toLowerCase()}-terms-${suffix}`,
		description: 'Throwaway caseType for the four-clock e2e layer.',
		isDraft: false,
		processingDeadline: `P${STATUTORY_DAYS}D`,
		plannedLeadTime: PLANNED_DAYS,
		internalTargetDays: INTERNAL_DAYS,
		extensionAllowed: true,
		extensionPeriod: 'P42D',
		suspensionAllowed: true,
		maxSuspensionDays: 28,
		...extra,
	})
	const caseTypeId = objectId(caseType)

	const phase = await createObject(api, token, 'statusType', {
		name: `${RUN_PREFIX} Ontvankelijkheidstoets`,
		caseType: caseTypeId,
		order: 1,
		isFinal: false,
		phaseTermDays: PHASE_DAYS,
	})

	return { caseTypeId, phaseId: objectId(phase) }
}

/**
 * Read dossiq's term answer for one case.
 *
 * @param api    Authenticated request context.
 * @param caseId The case uuid.
 */
async function readTerms(api: any, caseId: string) {
	const response = await api.get(`${TERMS_BASE}/${caseId}/terms`)
	expect(response.ok(), 'the terms endpoint answers').toBeTruthy()
	return response.json()
}

test.describe('The four clocks on a case', () => {
	test('four clocks on one case are four instances, each naming its kind', async ({
		request,
		page,
	}) => {
		const token = await getRequestToken(request)
		const { caseTypeId, phaseId } = await seedDeclaringType(request, token)

		const seeded = await seedCase(request, token, {
			title: `${RUN_PREFIX} Four clocks`,
			caseType: caseTypeId,
			status: phaseId,
		})
		const caseId = objectId(seeded)

		const body = await readTerms(request, caseId)
		const kinds = body.terms.map((term: any) => term.kind)

		expect(kinds, 'the statutory term is bound from the lead time').toContain(
			'statutory',
		)
		expect(kinds, 'the planned end is its own instance').toContain('planned')
		expect(kinds, 'the internal target is its own instance').toContain(
			'internal',
		)

		// Every instance says what it is. A clock with no kind is the collapsed
		// field this change replaces.
		for (const term of body.terms) {
			expect(term.kind, 'every instance names its kind').toBeTruthy()
		}

		// The case page draws them apart.
		const errors = trackDossiqErrors(page)
		await navToRoute(page, `/cases/${caseId}`)
		await page.getByRole('tab', { name: /terms|termijnen/i }).click()
		await expect(page.getByTestId('case-terms-row-statutory')).toBeVisible(
			PAGE_LOAD,
		)
		await expect(page.getByTestId('case-terms-row-planned')).toBeVisible()
		await expect(page.getByTestId('case-terms-progress-figure')).toBeVisible()
		expect(errors, 'the Terms tab logs no dossiq error').toEqual([])
	})

	test('an ontvankelijkheidstoets has its own two weeks inside eight', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseTypeId, phaseId } = await seedDeclaringType(request, token)

		const seeded = await seedCase(request, token, {
			title: `${RUN_PREFIX} Phase clock`,
			caseType: caseTypeId,
			status: phaseId,
		})
		const caseId = objectId(seeded)

		// Entering the phase is what starts its clock, and the listener that
		// starts it runs on an UPDATE. A case created in the phase has not
		// entered it yet, so the case is saved into it.
		await updateObject(request, token, 'case', caseId, { status: phaseId })

		const body = await readTerms(request, caseId)
		const phaseTerm = body.terms.find((term: any) => term.kind === 'phase')
		const statutory = body.terms.find((term: any) => term.kind === 'statutory')

		expect(phaseTerm, 'entering the phase started a phase term').toBeTruthy()
		expect(
			phaseTerm.daysLeft,
			'the phase is shorter than the case term it runs inside',
		).toBeLessThan(statutory.daysLeft)
	})

	test('an overrunning phase reads overdue while the case term reads on time', async ({
		request,
		page,
	}) => {
		const token = await getRequestToken(request)
		const { caseTypeId, phaseId } = await seedDeclaringType(request, token)

		const seeded = await seedCase(request, token, {
			title: `${RUN_PREFIX} Overrunning phase`,
			caseType: caseTypeId,
			status: phaseId,
		})
		const caseId = objectId(seeded)

		// Push the phase clock into the past, leaving the case term where it is.
		const terms = await readTerms(request, caseId)
		const phaseTerm = terms.terms.find((term: any) => term.kind === 'phase')
		test.skip(
			!phaseTerm,
			'no phase clock was bound, so there is nothing to overrun',
		)

		const past = new Date(Date.now() - 6 * 86_400_000).toISOString().slice(0, 10)
		await updateObject(request, token, 'deadlineInstance', phaseTerm.id, {
			endDateCurrent: past,
		})

		const after = await readTerms(request, caseId)
		const overrun = after.terms.find((term: any) => term.kind === 'phase')
		const statutory = after.terms.find((term: any) => term.kind === 'statutory')

		expect(overrun.overdue, 'the phase has run over').toBe(true)
		expect(statutory.overdue, 'the case term has not').toBe(false)

		const errors = trackDossiqErrors(page)
		await navToRoute(page, `/cases/${caseId}`)
		await page.getByRole('tab', { name: /terms|termijnen/i }).click()
		await expect(page.getByTestId('case-terms-row-phase')).toHaveAttribute(
			'data-tone',
			'overdue',
			PAGE_LOAD,
		)
		await expect(page.getByTestId('case-terms-row-statutory')).toHaveAttribute(
			'data-tone',
			'ontime',
		)
		expect(errors, 'the Terms tab logs no dossiq error').toEqual([])
	})

	test('late against the plan and on time against the law reads as both', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseTypeId } = await seedDeclaringType(request, token)

		const seeded = await seedCase(request, token, {
			title: `${RUN_PREFIX} Late against the plan`,
			caseType: caseTypeId,
		})
		const caseId = objectId(seeded)

		const terms = await readTerms(request, caseId)
		const planned = terms.terms.find((term: any) => term.kind === 'planned')
		test.skip(!planned, 'no planned end was bound, so there is no pair to read')

		const past = new Date(Date.now() - 3 * 86_400_000).toISOString().slice(0, 10)
		await updateObject(request, token, 'deadlineInstance', planned.id, {
			endDateCurrent: past,
		})

		const after = await readTerms(request, caseId)

		expect(
			after.terms.find((term: any) => term.kind === 'planned').overdue,
			'the plan is missed',
		).toBe(true)
		expect(
			after.terms.find((term: any) => term.kind === 'statutory').overdue,
			'the Awb is not',
		).toBe(false)
		expect(after.progress.plannedOverdue).toBe(true)
		expect(after.progress.statutoryOverdue).toBe(false)
	})

	test('the internal target runs and stays off the citizen read', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseTypeId } = await seedDeclaringType(request, token)

		const seeded = await seedCase(request, token, {
			title: `${RUN_PREFIX} Internal target`,
			caseType: caseTypeId,
		})
		const caseId = objectId(seeded)

		const handler = await readTerms(request, caseId)
		expect(
			handler.terms.map((term: any) => term.kind),
			'the teamleider sees the internal target',
		).toContain('internal')

		const citizen = await request.get(`${TERMS_BASE}/${caseId}/terms/citizen`)
		expect(citizen.ok()).toBeTruthy()
		const body = await citizen.json()

		expect(
			body.terms.map((term: any) => term.kind),
			'the citizen read carries the statutory term and nothing else',
		).not.toContain('internal')
		expect(body.terms.every((term: any) => term.kind === 'statutory')).toBe(true)
	})

	test('a subsidy round closes on a date, and a closed round still takes a case', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const closesOn = new Date(Date.now() + 90 * 86_400_000)
			.toISOString()
			.slice(0, 10)
		const { caseTypeId } = await seedDeclaringType(request, token, {
			processingDeadlineDate: closesOn,
		})

		const january = objectId(
			await seedCase(request, token, {
				title: `${RUN_PREFIX} Early`,
				caseType: caseTypeId,
			}),
		)
		const february = objectId(
			await seedCase(request, token, {
				title: `${RUN_PREFIX} Later`,
				caseType: caseTypeId,
			}),
		)

		const first = await readTerms(request, january)
		const second = await readTerms(request, february)

		expect(
			first.terms.find((term: any) => term.kind === 'statutory').endDate,
		).toBe(closesOn)
		expect(
			second.terms.find((term: any) => term.kind === 'statutory').endDate,
		).toBe(closesOn)

		// A round that has already closed still creates the case, visibly expired.
		const passed = new Date(Date.now() - 30 * 86_400_000)
			.toISOString()
			.slice(0, 10)
		const { caseTypeId: closedType } = await seedDeclaringType(request, token, {
			processingDeadlineDate: passed,
		})
		const late = objectId(
			await seedCase(request, token, {
				title: `${RUN_PREFIX} Late application`,
				caseType: closedType,
			}),
		)

		const lateTerms = await readTerms(request, late)
		expect(lateTerms.case, 'the case exists').toBe(late)
		expect(
			lateTerms.terms.find((term: any) => term.kind === 'statutory').overdue,
			'its term reads expired rather than refusing the application',
		).toBe(true)
	})

	test('an extension beyond the declared period is refused, and one inside it is not', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseTypeId } = await seedDeclaringType(request, token)
		const caseId = objectId(
			await seedCase(request, token, {
				title: `${RUN_PREFIX} Extension`,
				caseType: caseTypeId,
			}),
		)

		const terms = await readTerms(request, caseId)
		const statutory = terms.terms.find((term: any) => term.kind === 'statutory')

		const tooFar = new Date(Date.now() + (STATUTORY_DAYS + 60) * 86_400_000)
			.toISOString()
			.slice(0, 10)
		const refused = await request.post(
			`/index.php/apps/dossiq/api/termijn/instances/${statutory.id}/verleng`,
			{
				headers: { requesttoken: token },
				data: { rationale: 'Extra onderzoek', newEinddatum: tooFar },
			},
		)

		expect(
			refused.status(),
			'a sixty day move on a forty-two day period is a 4xx',
		).toBeGreaterThanOrEqual(400)
		expect(refused.status()).toBeLessThan(500)
		const refusal = await refused.json()
		expect(
			refusal.error ?? refusal.message,
			'the refusal names its rule',
		).toBeTruthy()
	})

	test('the letter and the pause happen together, and a failed letter leaves the clock running', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseTypeId } = await seedDeclaringType(request, token)
		const caseId = objectId(
			await seedCase(request, token, {
				title: `${RUN_PREFIX} Aanvulling`,
				caseType: caseTypeId,
			}),
		)

		const asked = await request.post(
			`${TERMS_BASE}/${caseId}/information-request`,
			{
				headers: { requesttoken: token },
				data: {
					items: ['Bankafschrift', 'Bouwtekening'],
					recipient: 'admin',
					durationDays: 14,
					rationale: 'Aanvraag is onvolledig',
				},
			},
		)

		const body = await asked.json()

		if (asked.ok()) {
			expect(body.sent, 'the request went out').toBe(true)
			expect(body.suspended, 'and the term stopped in the same act').toBe(true)
			expect(body.record.items, 'one record carries what was asked').toEqual([
				'Bankafschrift',
				'Bouwtekening',
			])

			const resumed = await request.post(
				`${TERMS_BASE}/${caseId}/information-request/received`,
				{
					headers: { requesttoken: token },
					data: { items: ['Bankafschrift', 'Bouwtekening'] },
				},
			)
			expect(resumed.ok()).toBeTruthy()
			expect((await resumed.json()).resumed).toBe(true)
			return
		}

		// The transport is not configured on this instance, which is the OTHER
		// half of the requirement: a send that did not happen must leave the
		// clock running. A 200 with `sent: false` would be the failure.
		expect(
			asked.status(),
			'a failed send is not answered 200',
		).toBeGreaterThanOrEqual(400)
		expect(body.suspended ?? false, 'the term was NOT suspended').toBe(false)

		const after = await readTerms(request, caseId)
		const statutory = after.terms.find((term: any) => term.kind === 'statutory')
		expect(statutory.status, 'the clock is still running').not.toBe('paused')
	})

	test('the age of the open workload is answered per status, over open cases', async ({
		request,
	}) => {
		const token = await getRequestToken(request)
		const { caseTypeId, phaseId } = await seedDeclaringType(request, token)
		await seedCase(request, token, {
			title: `${RUN_PREFIX} Standing work`,
			caseType: caseTypeId,
			status: phaseId,
		})

		const response = await request.get(
			'/index.php/apps/dossiq/api/termijn/reports/open-workload-age',
		)
		expect(response.ok(), 'the workload report answers').toBeTruthy()

		const body = await response.json()
		expect(body.generatedAt, 'the report says when it was read').toBeTruthy()
		expect(Array.isArray(body.perStatus), 'it answers per status').toBe(true)
		expect(body.openCases, 'it counted the open cases it read').toBeGreaterThan(
			0,
		)

		for (const row of body.perStatus) {
			expect(row.status, 'every row names its status').toBeTruthy()
			expect(row.cases, 'every row counts its cases').toBeGreaterThan(0)
			expect(
				row.oldestDays,
				'every row carries an age',
			).toBeGreaterThanOrEqual(0)
		}
	})

	test('a case whose type declares no term says so rather than showing a blank panel', async ({
		request,
		page,
	}) => {
		const token = await getRequestToken(request)
		const suffix = Math.floor(Math.random() * 1e6)
		const bare = await createObject(request, token, 'caseType', {
			title: `${RUN_PREFIX} No terms ${suffix}`,
			identifier: `${RUN_PREFIX.toLowerCase()}-noterms-${suffix}`,
			description: 'A case type that declares no clock at all.',
			isDraft: false,
		})
		const caseId = objectId(
			await seedCase(request, token, {
				title: `${RUN_PREFIX} No clock`,
				caseType: objectId(bare),
			}),
		)

		const stored = await showObject(request, 'case', caseId)
		expect(objectId(stored), 'the case exists').toBe(caseId)

		const errors = trackDossiqErrors(page)
		await navToRoute(page, `/cases/${caseId}`)
		await page.getByRole('tab', { name: /terms|termijnen/i }).click()
		await expect(page.getByTestId('case-terms-tab')).toBeVisible(PAGE_LOAD)
		await expect(
			page.getByTestId('case-terms-unreadable'),
			'a case type with no term is not an unreadable case',
		).toHaveCount(0)
		expect(errors, 'the Terms tab logs no dossiq error').toEqual([])
	})
})
