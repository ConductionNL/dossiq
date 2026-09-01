/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case detail — the KPI row, the tabbed panels and the right column.
 *
 * Every assertion here is one that a unit test could not make, because each
 * covers a seam between the manifest, the widget catalog and a live register:
 *
 *  - the countdown reads a DATE off the loaded record and turns it into words;
 *  - the case-type tile resolves a uuid to the referenced object's title;
 *  - the tabs widget renders one panel per configured tab and mounts only the
 *    open one;
 *  - the hoisted Actions menu sits beside the strip rather than inside it.
 *
 * The empty-state trap is worth stating, because this page has now hit it
 * twice: a widget whose query 404s renders "No X yet", which is exactly what
 * an empty result looks like. So the console/5xx tracker is asserted too — a
 * green-looking page is not evidence on this surface.
 */

import { expect, test } from '@playwright/test'
import {
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	seedCase,
	tryDeleteObject,
} from './helpers/fixtures'
import { trackDossiqErrors } from './helpers/nav'

test.describe('Case detail — KPI row, tabbed panels, right column', () => {
	test.setTimeout(180_000)

	let caseId = ''
	let caseTypeId = ''
	const created: Array<[string, string]> = []

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// A case type with a title we can assert the KPI resolved TO, rather
		// than asserting "not a uuid" and calling that a pass.
		// `processingDeadline` matters: `case.deadline` is COMPUTED by
		// OpenRegister from the case type's duration, so a case whose type
		// declares none has no deadline and the countdown correctly shows a
		// dash. Seeding the case's `deadline` directly does not substitute.
		const caseType = await createObject(api, token, 'caseType', {
			title: 'E2E Omgevingsvergunning',
			identifier: `E2E-CT-${Date.now().toString(36)}`,
			processingDeadline: 'P30D',
		})
		caseTypeId = objectId(caseType)
		created.push(['caseType', caseTypeId])

		// A deadline far enough out that the wording is stable: any same-day
		// boundary would make "Due today" vs "1 day left" a clock race.
		const deadline = new Date()
		deadline.setDate(deadline.getDate() + 30)

		const seeded = await seedCase(api, token, {
			title: 'E2E Dakkapel',
			caseType: caseTypeId,
			startDate: new Date().toISOString().slice(0, 10),
			deadline: deadline.toISOString().slice(0, 10),
		})
		caseId = objectId(seeded)
		created.push(['case', caseId])

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		for (const [schema, id] of created.reverse()) {
			await tryDeleteObject(api, token, schema, id)
		}
		await api.dispose()
	})

	test('the KPI row headlines time left, case type and completion', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)

		const kpis = page.locator('.cn-kpi-card')
		await expect(kpis.first()).toBeVisible({ timeout: 30_000 })

		// Time left is COMPUTED from the deadline, not printed from it.
		await expect(page.locator('.cn-countdown-widget')).toContainText(/day(s)? left/, {
			timeout: 15_000,
		})

		// The case type field holds a uuid. Showing the uuid would be a pass for
		// "renders something" and a failure for the feature.
		const caseTypeCard = kpis.filter({ hasText: 'Case type' })
		await expect(caseTypeCard).toContainText('E2E Omgevingsvergunning', { timeout: 20_000 })
		await expect(caseTypeCard).not.toContainText(caseTypeId)

		// Completion comes from the milestone endpoint. 0% is the honest answer
		// for a case type with no milestones; the assertion is that it RESOLVED,
		// not that it is non-zero.
		await expect(kpis.filter({ hasText: 'Completed' })).toContainText(/%/, {
			timeout: 20_000,
		})

		// The CMMN panel probes whether this case is CMMN-managed and gets a 409
		// for a BPMN-managed one, which is the app answering "no" rather than
		// failing. Pre-existing and unrelated to this page's widgets; everything
		// else must be silent.
		const unexpected = errors.filter((e) => !/\b409\b|cmmn-plan/.test(e))
		expect(unexpected, `console/5xx errors: ${unexpected.join(' | ')}`).toEqual([])
	})

	test('the tabs widget renders one tab per configured panel', async ({ page }) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })

		for (const label of ['Notes', 'Files', 'Related cases', 'Sub-cases', 'Mail', 'Appointments']) {
			await expect(strip.getByRole('tab', { name: label })).toBeVisible({ timeout: 15_000 })
		}
	})

	test('the Actions menu sits beside the strip, not inside the tablist', async ({ page }) => {
		// A control nested in role="tablist" is announced as one of the tabs, so
		// a reader counting six tabs would hear seven.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await expect(strip.locator('.cn-tabs__nav-end')).toBeVisible()
		await expect(strip.locator('[role="tablist"] .cn-tabs__nav-end')).toHaveCount(0)
	})

	test('only the open tab mounts, and a switched-to tab stays mounted', async ({ page }) => {
		// Six eager panels would fire six requests on load to answer five
		// questions nobody asked. This is the assertion that keeps them lazy.
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })

		const mounted = () =>
			strip.locator('[role="tabpanel"]').evaluateAll(
				(panels) => panels.filter((p) => p.children.length > 0).length,
			)

		await expect.poll(mounted, { timeout: 15_000 }).toBe(1)

		await strip.getByRole('tab', { name: 'Sub-cases' }).click()
		await expect.poll(mounted, { timeout: 15_000 }).toBe(2)

		// Switching back must not tear the first panel down, or every switch
		// refetches.
		await strip.getByRole('tab', { name: 'Notes' }).click()
		await expect.poll(mounted, { timeout: 15_000 }).toBe(2)
	})

	test('the right column carries the case collections with their own chrome', async ({ page }) => {
		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

		for (const title of ['Hours booked', 'Runs', 'Tasks', 'Decisions', 'Locations']) {
			await expect(page.getByText(title, { exact: true }).first()).toBeVisible({
				timeout: 20_000,
			})
		}
	})

	test('the Locations widget can actually query its schema', async ({ page }) => {
		// This widget rendered "No locations linked to this case yet" for weeks
		// because its schema slug 404'd — the empty state and the broken state
		// look identical, so assert the REQUEST, not the text.
		const responses: number[] = []
		page.on('response', (r) => {
			if (r.url().includes('/objects/dossiq/case-location')) responses.push(r.status())
		})

		await page.goto(`/apps/${REGISTER}/cases/${caseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

		await expect
			.poll(() => responses.length, { timeout: 20_000 })
			.toBeGreaterThan(0)
		expect(responses.every((s) => s < 400), `statuses: ${responses.join(',')}`).toBe(true)
	})
})
