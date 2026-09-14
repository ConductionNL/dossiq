/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A planned follow-up that repeats: an annual permit check, a quarterly report.
 *
 * Everything here is a seam no unit test reaches. The document class knows what
 * cron fields a recurrence becomes and the sweep knows when a series is spent,
 * and both are asserted in PHPUnit. What only a browser can show is that the
 * form offers the repeat at all, that posting it writes ONE scheduled flow with
 * those cron fields, and that the Related tab reads the series back with its
 * next occurrence and its cases underneath.
 *
 * ASSERT IDS, NOT LABELS. Nothing forces the language of the E2E instance, so
 * every locator is a `data-testid` or a role, and the only text asserted is
 * text this fixture itself seeded (which carries RUN_PREFIX and is therefore
 * the same in either locale) or a date, which is not translated. The tab name
 * is matched against both locales for the same reason.
 *
 * THE CLOCK IS NOT DRIVEN HERE. A series whose third occurrence is its last
 * takes three firings to prove, and a browser test cannot move the instance's
 * cron forward. That scenario is excluded in the spec and carries its proof in
 * `tests/Unit/Service/Flow/PlannedFollowUpSweepTest.php`, which steps a stubbed
 * flow through three firings, and in
 * `tests/Unit/Service/Flow/PlannedFollowUpSeriesTest.php`, which is where the
 * month-end and year-boundary arithmetic lives. Named here so the claim can be
 * checked rather than believed.
 *
 * THE TAB PANELS ARE LAZY and the sidebar has tabpanels of its own, so the
 * Related cases assertions address the OPEN PANEL inside the strip, and never
 * a page-wide locator, and they take `.cn-tabs__content`'s DIRECT children
 * rather than descending: the related-objects widget renders a
 * `<section role="tabpanel">` of its own inside the panel, so a descendant
 * query matches two elements and every assertion on it fails as a strict mode
 * violation rather than as anything about the tab.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	updateObject,
} from './helpers/fixtures.ts'
import {
	clickHeaderAction,
	PAGE_LOAD,
	trackDossiqErrors,
} from './helpers/nav.ts'

/** The case type a follow-up of this suite is planned as. */
let followUpType = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

/** The date the first occurrence of every series in this suite falls on. */
const firstDue = (() => {
	const next = new Date()
	next.setMonth(next.getMonth() + 1)
	return next.toISOString().slice(0, 10)
})()

test.describe('A planned follow-up that repeats', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		followUpType = objectId(
			await createObject(api, token, 'caseType', {
				title: `${RUN_PREFIX} Controle`,
				identifier: `${RUN_PREFIX.toLowerCase()}-controle`,
				description:
					'Throwaway caseType for the planned-case-series e2e layer.',
				processingDeadline: 'P30D',
				startableFlows: [],
			}),
		)

		const status = objectId(
			await createObject(api, token, 'statusType', {
				name: `${RUN_PREFIX} Ontvangen`,
				caseType: followUpType,
				order: 1,
				isFinal: false,
			}),
		)
		await updateObject(api, token, 'caseType', followUpType, {
			initialStatus: status,
		})

		const seed = async (key: string) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType: followUpType,
				description: `Seeded for the ${key} scenario.`,
				confidentiality: 'openbaar',
				// high + medium derives `high`; a seeded priority is replaced.
				impact: 'high',
				urgency: 'medium',
				intakeChannel: 'website',
				startDate: new Date().toISOString().slice(0, 10),
			})
			cases[key] = objectId(row)
		}

		await seed('series')
		await seed('single')
		await seed('occurrences')

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open one case and wait for the detail page to have rendered.
	 *
	 * @param page The Playwright page.
	 * @param key Which seeded case to open.
	 */
	const openCase = async (page: any, key: string) => {
		await page.goto(`/apps/${REGISTER}/cases/${cases[key]}`, PAGE_LOAD)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
	}

	/**
	 * Post one plan, and fail with the body rather than with a bare status.
	 *
	 * @param request The Playwright request context.
	 * @param caseId The case to plan a follow-up for.
	 * @param body The plan.
	 */
	const postPlan = async (request: any, caseId: string, body: any) => {
		const token = await getRequestToken(request)
		const response = await request.post(
			`/index.php/apps/${REGISTER}/api/case/${caseId}/plan`,
			{
				headers: {
					'Content-Type': 'application/json',
					requesttoken: token,
					'OCS-APIRequest': 'true',
				},
				data: body,
			},
		)
		expect(
			response.status(),
			`plan -> ${response.status()} ${await response.text()}`,
		).toBe(200)
		return await response.json()
	}

	/**
	 * The Related cases section of the open Related tab.
	 *
	 * @param page The Playwright page.
	 */
	const relatedSection = async (page: any) => {
		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await strip
			.getByRole('tab', { name: /^(Related|Gerelateerd)/ })
			.first()
			.click()
		return strip.locator(
			'.cn-tabs__content > [role="tabpanel"]:not([hidden]) [data-testid="case-section-case-related"]',
		)
	}

	// @e2e openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md#a-yearly-inspection-is-planned-once
	test('a yearly inspection is planned once and comes back on schedule', async ({
		page,
		request,
	}) => {
		const errors = trackDossiqErrors(page)
		await openCase(page, 'series')

		// The form offers the repeat and its end. Driven through the dialog
		// rather than asserted off the manifest, because a field the manifest
		// declares and the registry does not render is exactly the failure the
		// vitest cannot see.
		await clickHeaderAction(page, 'cn-action-plan-follow-up')
		const dialog = page.getByTestId('case-plan-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		await expect(dialog.getByTestId('case-plan-recurrence')).toBeVisible()

		// Ends appears only once a repeat is chosen: "ends after 3" says
		// nothing about a case that happens once.
		await expect(dialog.getByTestId('case-plan-end')).toHaveCount(0)
		await page.getByTestId('case-plan-cancel').click()
		await expect(dialog).toHaveCount(0, { timeout: 20_000 })

		const plannedTitle = `${RUN_PREFIX} Jaarlijkse controle`
		const planned = await postPlan(request, cases.series, {
			caseType: followUpType,
			date: firstDue,
			title: plannedTitle,
			recurrence: 'yearly',
			count: 3,
		})
		expect(planned.recurrence).toBe('yearly')
		expect(planned.count).toBe(3)

		// ONE scheduled flow for the case, and its cron says yearly: a day, one
		// month, and the weekday field open. A series that wrote a flow per
		// occurrence would pass every assertion about the tab below and still be
		// the wrong thing, which is why the count is asserted first.
		const token = await getRequestToken(request)
		const flows = await request.get(
			'/index.php/apps/openregister/api/flows?_limit=200',
			{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
		)
		expect(flows.status()).toBe(200)
		const body = await flows.json()
		const rows = (body?.results ?? body?.flows ?? body ?? []) as any[]
		const mine = rows.filter(
			(row: any) => String(row?.name ?? '').includes(plannedTitle) === true,
		)
		expect(mine.length, 'a series is ONE scheduled flow').toBe(1)

		const month = Number(firstDue.slice(5, 7))
		const cron = String(mine[0]?.cron ?? '')
		const fields = cron.trim().split(/\s+/)
		expect(fields, `cron "${cron}" is not five fields`).toHaveLength(5)
		expect(Number(fields[3]), `cron "${cron}" is not yearly`).toBe(month)
		expect(fields[4], `cron "${cron}" pins a weekday too`).toBe('*')

		// The Related tab shows the series with its next occurrence.
		await page.reload()
		const panel = await relatedSection(page)
		await expect(panel).toContainText(plannedTitle, { timeout: 30_000 })
		await expect(panel).toContainText(firstDue, { timeout: 30_000 })

		expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([])
	})

	// @e2e openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md#a-single-follow-up-is-unchanged
	test('a follow-up with no repeat carries the planned date as before', async ({
		page,
		request,
	}) => {
		const errors = trackDossiqErrors(page)
		const plannedTitle = `${RUN_PREFIX} Eenmalige controle`

		const planned = await postPlan(request, cases.single, {
			caseType: followUpType,
			date: firstDue,
			title: plannedTitle,
			recurrence: 'none',
		})
		expect(planned.recurrence).toBe('none')
		expect(planned.date).toBe(firstDue)

		// The cron pins the DAY AND THE MONTH of the planned date, which is what
		// a single follow-up wrote before series existed. A recurrence leaking
		// into a plan that asked for none would open the day field, and this is
		// the assertion that sees it.
		const token = await getRequestToken(request)
		const flows = await request.get(
			'/index.php/apps/openregister/api/flows?_limit=200',
			{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
		)
		const body = await flows.json()
		const rows = (body?.results ?? body?.flows ?? body ?? []) as any[]
		const mine = rows.filter(
			(row: any) => String(row?.name ?? '').includes(plannedTitle) === true,
		)
		expect(mine.length).toBe(1)
		const fields = String(mine[0]?.cron ?? '').trim().split(/\s+/)
		expect(Number(fields[2])).toBe(Number(firstDue.slice(8, 10)))
		expect(Number(fields[3])).toBe(Number(firstDue.slice(5, 7)))

		// It shows on the Related tab, and no case of that title exists yet:
		// the follow-up is planned, not made.
		await openCase(page, 'single')
		const panel = await relatedSection(page)
		await expect(panel).toContainText(plannedTitle, { timeout: 30_000 })

		const existing = (
			await listObjects(request, 'case', { _limit: '200' })
		).filter((row: any) => String(row.title ?? '') === plannedTitle)
		expect(existing.length, 'the planned case does not exist yet').toBe(0)

		expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([])
	})

	// @e2e openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md#two-occurrences-show-under-one-series
	test('the cases a series opened show under it, and Stop series is offered', async ({
		page,
		request,
	}) => {
		const errors = trackDossiqErrors(page)
		const plannedTitle = `${RUN_PREFIX} Kwartaalrapportage`

		const planned = await postPlan(request, cases.occurrences, {
			caseType: followUpType,
			date: firstDue,
			title: plannedTitle,
			recurrence: 'quarterly',
		})
		const flowId = String(planned.id ?? '')
		expect(flowId, 'the plan answered no flow id').not.toBe('')

		// The two occurrences are seeded rather than waited for. A quarterly
		// series takes six months to open two cases of its own, and what this
		// scenario is about is the READ: cases carrying the series as their
		// `handoffSource` are listed under it. The token is written exactly as
		// PlannedFollowUpDocument writes it, so a change to that prefix reddens
		// here rather than silently emptying the list.
		const token = await getRequestToken(request)
		const occurrences: string[] = []
		for (const label of ['Q1', 'Q2']) {
			const row = await seedCase(request, token, {
				title: `${RUN_PREFIX} ${plannedTitle} ${label}`,
				caseType: followUpType,
				description: 'An occurrence of the series.',
				confidentiality: 'openbaar',
				impact: 'medium',
				urgency: 'medium',
				intakeChannel: 'website',
				startDate: new Date().toISOString().slice(0, 10),
				relatedCases: [cases.occurrences],
				handoffSource: `planned-series:${flowId}`,
			})
			occurrences.push(objectId(row))
		}
		expect(occurrences).toHaveLength(2)

		// The endpoint lists them under the series, which is what the tab
		// renders. Asserted on the read as well as on the DOM: an empty list
		// and a list the tab failed to render look identical on screen.
		const read = await request.get(
			`/index.php/apps/${REGISTER}/api/case/${cases.occurrences}/planned`,
			{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
		)
		expect(read.status()).toBe(200)
		const rows = ((await read.json())?.results ?? []) as any[]
		const series = rows.find((row: any) => String(row.id ?? '') === flowId)
		expect(series, 'the series is not in the planned list').toBeTruthy()
		expect(series.recurrence).toBe('quarterly')
		expect(
			(series.occurrences ?? []).length,
			'the series does not list the cases it opened',
		).toBe(2)

		await openCase(page, 'occurrences')
		await relatedSection(page)

		// Stop series is offered on the series row, and only on a row that
		// repeats: a single follow-up has nothing to stop.
		const stop = page.getByTestId(`case-planned-stop-${flowId}`)
		await expect(stop).toBeVisible({ timeout: 30_000 })
		for (const id of occurrences) {
			await expect(
				page.getByTestId(`case-planned-occurrence-${id}`),
			).toBeVisible({ timeout: 30_000 })
		}

		// And stopping it takes the row away while leaving the cases alone.
		await stop.click()
		await expect(stop).toHaveCount(0, { timeout: 30_000 })
		const survivors = (
			await listObjects(request, 'case', { _limit: '200' })
		).filter((row: any) => occurrences.includes(objectId(row)))
		expect(
			survivors.length,
			'stopping a series deleted the cases it had opened',
		).toBe(2)

		expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([])
	})
})
