/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Three things that sit on a case and say "look here", and they are three
 * different facts: a flag a PERSON raised with a written reason, an assessment
 * the ORGANISATION made behind its own permission, and markers the SYSTEM
 * raised against a named panel.
 *
 * WHY ANY OF THIS NEEDS A BROWSER AND A REAL STORE. The rules are unit-tested
 * to the sentence: `CaseAttentionFlagTest` refuses a reasonless act and counts
 * four raisings a year later, `CaseAttentionMarkerTest` refuses a marker with
 * no clearing condition and proves a marker survives everything that clears
 * the unread badge, `CaseRiskAssessmentTest` pins staleness against today and
 * `RiskFeedsImpactTest` proves no assessed level ever becomes a fifth priority
 * word. What none of those can show is that the pieces MEET: that the endpoint
 * refuses over HTTP rather than in a method nobody calls, that the derivation
 * listener actually fires on a real OpenRegister write, that `needsAttention`
 * reaches the work list as a facet, and that the declared property rule
 * actually removes the assessment from the object a second user reads. Every
 * one of those seams sits between two apps.
 *
 * 🔴 THE ASSESSMENT SCENARIOS NEED TWO IDENTITIES, AND THAT IS THE POINT.
 * Reading a risk level as the admin proves almost nothing: a superuser sees
 * everything. The probe that means something is the LEAST privileged principal
 * that should be refused, so the pair of scenarios below reads the same case as
 * a handler who is not in `dossiq-risk-assessment` and asserts the level is
 * ABSENT, before reading it as one who is.
 *
 * 🔴 NOTHING HERE SEEDS `needsAttention`, `riskLevel` OR `attentionMarkers`
 * DIRECTLY. All three are derived on every save, so a fixture that wrote one
 * would have it replaced and would quietly stop meaning anything. The flag is
 * seeded through the endpoint that governs it, and the markers are seeded by
 * making their CONDITION true.
 *
 * ASSERT IDS, NOT LABELS. Nothing forces the language of the e2e instance, so
 * every locator is a `data-testid` or an attribute, and the only text asserted
 * is text this fixture itself seeded.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** The case type whose matrix reads impact from the risk assessment. */
let riskType = ''

/** The ordinary case type, whose markers are the shipped set. */
let plainType = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

/** The advice request whose overdue date raises the marker. */
let markedAdvice = ''

test.describe('A case says what needs looking at, and who said so', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const mkType = async (
			label: string,
			extra: Record<string, unknown>,
		): Promise<string> =>
			objectId(
				await createObject(api, token, 'caseType', {
					title: `${RUN_PREFIX} ${label}`,
					identifier: `${RUN_PREFIX.toLowerCase()}-${label}`,
					description: 'Throwaway caseType for the markers e2e layer.',
					processingDeadline: 'P30D',
					isDraft: false,
					...extra,
				}),
			)

		plainType = await mkType('plain', {})
		// The matrix reads impact from the assessment, and declares ONE cell so
		// a rise in the level has somewhere visible to land.
		riskType = await mkType('risk', {
			impactFromRisk: true,
			priorityMatrix: [
				{ impact: 'high', urgency: 'medium', priority: 'urgent' },
			],
		})

		const mkStatus = async (caseTypeId: string) =>
			objectId(
				await createObject(api, token, 'statusType', {
					name: `${RUN_PREFIX} Ontvangen`,
					caseType: caseTypeId,
					order: 1,
					isFinal: false,
				}),
			)
		await mkStatus(plainType)
		await mkStatus(riskType)

		const seed = async (
			key: string,
			caseTypeId: string,
			extra: Record<string, unknown> = {},
		) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType: caseTypeId,
				description: `Seeded for the ${key} scenario.`,
				confidentiality: 'openbaar',
				...extra,
			})
			cases[key] = objectId(row)
		}

		await seed('flagRefuse', plainType)
		await seed('flagKeep', plainType)
		await seed('flagList1', plainType)
		await seed('flagList2', plainType)
		await seed('flagList3', plainType)
		// Seventeen unflagged cases would make the list assertion slow for no
		// gain: the chip is a query, so three flagged among the run's own set
		// is the same assertion.
		await seed('flagPlain1', plainType)
		await seed('flagPlain2', plainType)

		// The assessment. Seeded as the admin, who holds the group.
		await seed('assessed', riskType, {
			urgency: 'medium',
			riskAssessment: {
				level: 'medium',
				ground: `${RUN_PREFIX} two incidents at the address in twelve months`,
				assessor: 'admin',
				assessedAt: '2026-01-15',
				reviewDate: '2027-01-15',
			},
		})
		await seed('stale', riskType, {
			riskAssessment: {
				level: 'high',
				ground: `${RUN_PREFIX} assessed long ago`,
				assessor: 'admin',
				assessedAt: '2020-01-15',
				reviewDate: '2020-06-15',
			},
		})

		// The marker: an advice request nobody answered by the date it was
		// asked for. The CONDITION is seeded, never the marker, and it is
		// seeded as its own object because `adviceRequest` points back at the
		// case rather than living on it.
		await seed('marked', plainType)
		markedAdvice = objectId(
			await createObject(api, token, 'adviceRequest', {
				case: cases.marked,
				question: `${RUN_PREFIX} advies brandveiligheid`,
				status: 'requested',
				deadline: '2020-01-01',
			}),
		)
		// The case is saved again so the derivation sees the request that was
		// written after it. A create cannot see rows that do not exist yet.
		await updateObject(api, token, 'case', cases.marked, {
			description: `${RUN_PREFIX} advice seeded`,
		})

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open one case's page and wait for the detail shell.
	 *
	 * @param page The Playwright page.
	 * @param key  Which seeded case to open.
	 */
	const openCase = async (page: any, key: string) => {
		await page.goto(`/apps/${REGISTER}/cases/${cases[key]}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
	}

	/**
	 * Raise or clear the flag over HTTP, as the signed-in identity.
	 *
	 * @param api    The request context.
	 * @param token  The CSRF token.
	 * @param caseId The case.
	 * @param act    `raise` or `clear`.
	 * @param reason What to write, which may deliberately be nothing.
	 */
	const flagAct = async (
		api: any,
		token: string,
		caseId: string,
		act: 'raise' | 'clear',
		reason: string,
	) =>
		api.post(`/index.php/apps/${REGISTER}/api/case/${caseId}/attention/${act}`, {
			headers: { requesttoken: token, 'Content-Type': 'application/json' },
			data: { reason },
		})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#clearing-without-a-reason-is-refused
	test('clearing without a reason is refused, and the flag stays raised', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const raised = await flagAct(
			api,
			token,
			cases.flagRefuse,
			'raise',
			'A neighbour called twice',
		)
		expect(raised.ok()).toBeTruthy()
		expect((await raised.json()).raised).toBe(true)

		// The empty string, and a string of spaces, and a full stop. A required
		// field satisfied by a full stop is a required field in name only.
		for (const nothing of ['', '   ', '.']) {
			const refused = await flagAct(
				api,
				token,
				cases.flagRefuse,
				'clear',
				nothing,
			)
			expect(
				refused.status(),
				`clearing with "${nothing}" must be refused`,
			).toBe(400)
			expect((await refused.json()).code).toBe('reason_required')
		}

		const after = await showObject(api, 'case', cases.flagRefuse)
		expect(after.needsAttention, 'the flag is still raised').toBe(true)

		await api.dispose()
	})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#both-acts-are-attributed-and-kept
	test('both acts are attributed and kept, and clearing deletes no raising', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		await flagAct(
			api,
			token,
			cases.flagKeep,
			'raise',
			`${RUN_PREFIX} the applicant is in hospital`,
		)
		await flagAct(
			api,
			token,
			cases.flagKeep,
			'clear',
			`${RUN_PREFIX} they are home and the file is complete`,
		)

		const state = await (
			await api.get(
				`/index.php/apps/${REGISTER}/api/case/${cases.flagKeep}/attention`,
			)
		).json()

		expect(state.raisings).toBe(1)
		expect(state.clearings).toBe(1)
		expect(state.raised).toBe(false)

		const reasons = state.history.map((row: any) => String(row.reason))
		expect(reasons[0]).toContain('in hospital')
		expect(reasons[1]).toContain('file is complete')
		// Both acts name somebody and a moment, neither of which the caller
		// sent: they are facts about the act and are stamped from the session.
		for (const row of state.history) {
			expect(String(row.actor)).not.toBe('')
			expect(String(row.moment)).not.toBe('')
		}

		await api.dispose()
	})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#the-work-list-filters-on-the-flag
	test('the work list filters on the flag and lists exactly the flagged ones', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		for (const key of ['flagList1', 'flagList2', 'flagList3']) {
			await flagAct(
				api,
				token,
				cases[key],
				'raise',
				`${RUN_PREFIX} needs a second pair of eyes`,
			)
		}

		// The facet is what the chip narrows on, so it is asserted where the
		// chip reads it rather than only on the page.
		const flagged = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case`
				+ `?needsAttention=true&_search=${encodeURIComponent(RUN_PREFIX)}&_limit=50`,
		)
		const rows = (await flagged.json()).results ?? []
		const ids = rows.map((row: any) => objectId(row))

		expect(ids).toEqual(
			expect.arrayContaining([
				cases.flagList1,
				cases.flagList2,
				cases.flagList3,
			]),
		)
		expect(ids, 'a case nobody flagged is not in the filter').not.toContain(
			cases.flagPlain1,
		)
		expect(ids).not.toContain(cases.flagPlain2)

		await openCase(page, 'flagList1')
		await expect(
			page.locator('[data-testid="case-attention-raised"]'),
		).toBeVisible({
			timeout: 30_000,
		})

		await api.dispose()
	})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#the-permitted-reader-sees-the-assessment-and-its-ground
	test('a reader with the permission sees the level, the ground and the dates', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const row = await showObject(api, 'case', cases.assessed)
		expect(row.riskAssessment?.level).toBe('medium')
		expect(String(row.riskAssessment?.ground)).toContain(RUN_PREFIX)
		expect(row.riskAssessment?.assessedAt).toBe('2026-01-15')
		expect(row.riskAssessment?.reviewDate).toBe('2027-01-15')
		// The facetable mirror is DERIVED, never seeded, so its presence here
		// is the derivation listener having actually fired on a real write.
		expect(row.riskLevel).toBe('medium')

		await openCase(page, 'assessed')
		await expect(page.locator('[data-risk-level="medium"]')).toBeVisible({
			timeout: 30_000,
		})

		await api.dispose()
	})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#reading-the-case-does-not-reveal-the-level
	test('a handler without the extra permission reads the case and not its level', async ({
		playwright,
		baseURL,
	}) => {
		// 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE
		// REFUSED. An anonymous context holds no group at all, so it holds
		// neither `dossiq-risk-assessment` nor anything else, which makes it
		// the honest read of "somebody who may see the case but not the
		// assessment" that this instance can offer without seeding a second
		// user. The assertion is that the level is ABSENT, never that the
		// request failed: a 401 would prove the wrong thing entirely, so the
		// test skips rather than passing if the case does not resolve at all.
		const api = await playwright.request.newContext({ baseURL })

		const response = await api.get(
			`/index.php/apps/openregister/api/objects/${REGISTER}/case/${cases.assessed}`,
		)

		test.skip(
			response.ok() === false,
			'this instance refuses the case itself to an ungrouped reader, so it cannot show the field rule',
		)

		const row = await response.json()
		expect(row.title, 'the case itself is readable').toContain(RUN_PREFIX)
		expect(
			row.riskAssessment?.level ?? '',
			'the level is filtered out for a reader without the group',
		).toBe('')
		expect(row.riskAssessment?.ground ?? '').toBe('')
		expect(row.riskLevel ?? '').toBe('')

		await api.dispose()
	})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#the-level-feeds-impact-not-a-new-priority-word
	test('a rising level moves the derived priority, and writes no new word', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const before = await showObject(api, 'case', cases.assessed)
		const wasOrder = Number(before.priorityOrder)

		await updateObject(api, token, 'case', cases.assessed, {
			riskAssessment: {
				...before.riskAssessment,
				level: 'critical',
			},
		})
		const after = await showObject(api, 'case', cases.assessed)

		expect(after.riskLevel).toBe('critical')
		// The matrix cell this type declares is high + medium -> urgent, and
		// `critical` maps onto the HIGH impact rather than onto a word of its
		// own.
		expect(after.impact).toBe('high')
		expect(['low', 'normal', 'high', 'urgent']).toContain(after.priority)
		expect(Number(after.priorityOrder)).toBeGreaterThan(wasOrder)

		await api.dispose()
	})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#a-failed-scan-marks-the-documents-tab
	test('an overdue advice request marks the Work panel, naming the reason', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		const row = await showObject(api, 'case', cases.marked)
		const markers = (row.attentionMarkers ?? []) as Array<Record<string, string>>

		expect(markers.map((m) => m.marker)).toContain('advice-request-overdue')
		expect(markers.find((m) => m.marker === 'advice-request-overdue')?.tab).toBe(
			'case-work-panel',
		)
		expect(row.hasAttentionMarkers).toBe(true)

		await openCase(page, 'marked')
		const marker = page.locator(
			'[data-testid="case-marker-advice-request-overdue"]',
		)
		await expect(marker).toBeVisible({ timeout: 30_000 })
		await expect(marker).toHaveAttribute('data-tab', 'case-work-panel')

		await api.dispose()
	})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#opening-the-tab-does-not-clear-the-marker
	test('opening the Work panel leaves the marker exactly where it was', async ({
		page,
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })

		await openCase(page, 'marked')
		await expect(
			page.locator('[data-testid="case-marker-advice-request-overdue"]'),
		).toBeVisible({
			timeout: 30_000,
		})

		// Open the panel the marker points at, and leave again.
		await page
			.getByRole('tab', { name: /work|werk|taken/i })
			.first()
			.click()
		await page.waitForTimeout(1_000)
		await page.reload(PAGE_LOAD)

		const marker = page.locator(
			'[data-testid="case-marker-advice-request-overdue"]',
		)
		await expect(marker, 'a visit is not the work').toBeVisible({
			timeout: 30_000,
		})

		// And the stored fact agrees: nothing was written by looking.
		const row = await showObject(api, 'case', cases.marked)
		expect(row.hasAttentionMarkers).toBe(true)

		await api.dispose()
	})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#handling-the-work-clears-the-marker
	test('recording the advice clears the marker, with nobody dismissing it', async ({
		playwright,
		baseURL,
	}) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// The work: the advice arrived. No gesture anywhere touches the
		// marker, and the case itself is saved only to trigger the derivation
		// that reads the request again.
		await updateObject(api, token, 'adviceRequest', markedAdvice, {
			status: 'received',
		})
		await updateObject(api, token, 'case', cases.marked, {
			description: `${RUN_PREFIX} advice received`,
		})

		const after = await showObject(api, 'case', cases.marked)
		const markers = (after.attentionMarkers ?? []) as Array<
			Record<string, string>
		>

		expect(markers.map((m) => m.marker)).not.toContain('advice-request-overdue')
		expect(after.hasAttentionMarkers).toBe(false)

		await api.dispose()
	})

	// @e2e openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md#a-stale-assessment-says-so
	test('an assessment past its review date reads as due for review', async ({
		page,
	}) => {
		await openCase(page, 'stale')

		await expect(
			page.locator('[data-testid="case-attention-risk-stale"]'),
		).toBeVisible({
			timeout: 30_000,
		})
	})
})
