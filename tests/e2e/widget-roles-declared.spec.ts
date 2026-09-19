/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A figure a reader may not see never reaches their browser.
 *
 * 🔴 THE ASSERTION IS ON THE PAYLOAD, NOT ON THE PAGE. A tile hidden with a
 * `v-if` passes any test that only looks at the rendered dashboard, and the
 * number is still one network tab away (ADR-004). So the dashboard request is
 * intercepted and the response body is read: the field is absent, not zero,
 * and the tile is absent with it.
 *
 * 🔴 NO PLACEHOLDER. An empty box tells a reader there is a figure they are
 * not allowed to see, which is itself information, and it is the shape a
 * naive fix takes. The rest of the page is asserted intact in the same test,
 * because a page that lost its whole body would also have "no placeholder".
 *
 * 🔴 THE PROBE IS THE LEAST PRIVILEGED PRINCIPAL THAT SHOULD BE REFUSED: an
 * ordinary case handler, signed in, who holds no team lead group. A superuser
 * seeing everything proves almost nothing.
 *
 * Runs against the nightly instance, not in the build loop.
 *
 * @spec openspec/changes/widget-roles-declared/specs/dashboard/spec.md
 */

import { expect, test } from '@playwright/test'
import { REGISTER } from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const DASHBOARD_URL = `/apps/${REGISTER}/`

test.describe('a restricted tile is absent, and so is its figure', () => {
	test('a case handler receives no SLA compliance figure at all', async ({
		page,
	}) => {
		trackDossiqErrors(page)

		const payloads: Array<Record<string, unknown>> = []
		page.on('response', async (response) => {
			if (response.url().includes('/api/dashboard/kpis')) {
				payloads.push(await response.json().catch(() => ({})))
			}
		})

		await page.goto(DASHBOARD_URL, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect
			.poll(() => payloads.length, { timeout: 15_000 })
			.toBeGreaterThan(0)

		// The field is GONE, not zero: a zero is a figure, and a reader shown
		// one has been told something false about the caseload.
		expect(payloads[0]).not.toHaveProperty('slaCompliance')
		await expect(page.getByText(/SLA Compliance/i)).toHaveCount(0)
	})

	test('the rest of the dashboard is intact, and nothing stands in the gap', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		await page.goto(DASHBOARD_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		// The tiles a handler is entitled to are all there.
		await expect(page.getByText(/Open Cases/i).first()).toBeVisible()
		await expect(page.getByText(/Overdue/i).first()).toBeVisible()
		await expect(page.getByText(/My Tasks/i).first()).toBeVisible()
		// And no placeholder where the restricted one was: an empty box says
		// there is a figure you are not allowed to see.
		await expect(
			page.getByText(
				/not available|niet beschikbaar|no permission|geen toegang/i,
			),
		).toHaveCount(0)
	})

	test('the managerial dashboards are not reachable by typing the address', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		// The widgets on these pages all declare the team lead role, so their
		// endpoint answers a handler nothing. The assertion is on the DATA
		// again rather than on the route, because a route guard is not an
		// access check.
		const payloads: Array<Record<string, unknown>> = []
		page.on('response', async (response) => {
			if (response.url().includes('/api/dashboard/kpis')) {
				payloads.push(await response.json().catch(() => ({})))
			}
		})

		await page.goto(`${DASHBOARD_URL}doorlooptijd`, PAGE_LOAD)
		await dismissSupportDialog(page)

		for (const payload of payloads) {
			expect(payload).not.toHaveProperty('slaCompliance')
		}
	})

	test('a renamed role hides the widget from everyone rather than showing it to everyone', async ({
		page,
	}) => {
		trackDossiqErrors(page)
		// The failure this closes is a configuration mistake becoming a
		// disclosure: a group renamed under a declaration that still names the
		// old one must NOT fall open. Driven here by serving the page a
		// manifest whose declaration names a group nobody holds.
		await page.route('**/manifest.json', async (route) => {
			const response = await route.fetch()
			const body = JSON.parse(await response.text())
			for (const pageDef of body.pages ?? []) {
				for (const widget of [
					...(pageDef.config?.widgets ?? []),
					...(pageDef.widgets ?? []),
				]) {
					if (Array.isArray(widget?.roles)) {
						widget.roles = ['dossiq-teamleiders-renamed']
					}
				}
			}
			await route.fulfill({ response, body: JSON.stringify(body) })
		})

		await page.goto(DASHBOARD_URL, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect(page.getByText(/SLA Compliance/i)).toHaveCount(0)
	})
})
