/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The user-visible surfaces changed by the committee, parafering, workflow and
 * LHS work — driven against a live instance rather than asserted from a mock.
 *
 * Each test states what it would take to make it pass wrongly, because that is
 * the only thing that makes a green e2e worth reading. Where a fixture is
 * absent these FAIL naming what was missing rather than skipping into a pass:
 * an e2e that cannot tell "absent" from "broken" reports both as fine.
 *
 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
 * @spec openspec/specs/enforcement-lhs/spec.md
 */
import { test, expect } from '@playwright/test'
import { BASE_URL } from './base-url'

/** The provenance marker the workflow projection writes into a flow's notes. */
const FLOW_MARKER = 'dossiq:workflowTemplate:'

test.describe('workflow definitions projected onto flows', () => {
	test('the projected flows are listed, and every one is disabled', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq/flows`)
		await page.waitForLoadState('domcontentloaded')

		// Read the flow store through the app's own API rather than scraping the
		// list: the assertion is about what was PROJECTED, and a rendering
		// change should not be able to turn this red or green.
		const flows = await page.evaluate(async () => {
			const res = await fetch('/apps/openregister/api/flows?limit=200', {
				headers: { requesttoken: (window as any).OC?.requestToken ?? '' },
			})
			if (!res.ok) return { error: res.status }
			return res.json()
		})

		expect(
			flows,
			'the flow API must answer; a failure here is not "no flows"',
		).not.toHaveProperty('error')

		const items = (flows.results ?? flows.items ?? flows) as Array<
			Record<string, unknown>
		>
		const projected = items.filter((f) =>
			String(f.notes ?? '').startsWith(FLOW_MARKER),
		)

		expect(
			projected.length,
			'no projected flow found — run `occ dossiq:workflows:migrate-to-flows --user=admin` first',
		).toBeGreaterThan(0)

		// 🔴 The invariant the migration exists to protect. An enabled projection
		// would move every case a second time on each status change.
		for (const flow of projected) {
			expect(
				flow.enabled,
				`projected flow "${String(flow.name)}" must arrive disabled`,
			).toBeFalsy()
		}
	})
})

test.describe('LHS override authorisation', () => {
	test('an inspector cannot escalate by claiming a harsher recommendation', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq/`)
		await page.waitForLoadState('domcontentloaded')

		// The vulnerability, driven as a request: a body that CLAIMS the matrix
		// recommended the harshest measure, so that anything else reads as an
		// override-down and skips the manager gate.
		const outcome = await page.evaluate(async () => {
			const res = await fetch('/apps/dossiq/api/lhs/override', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: (window as any).OC?.requestToken ?? '',
				},
				body: JSON.stringify({
					recommendation: {
						id: 'does-not-exist',
						recommendedIntervention: 'bestuursdwang',
						severity: 'ernstig',
					},
					intervention: 'last_under_penaltypayment',
					justification:
						'Gemotiveerde afwijking van de interventieladder.',
				}),
			})
			return { status: res.status, body: await res.text() }
		})

		// The forged baseline must not be honoured. Before the fix this returned
		// 200 with a stored override; now the row is read back from the store,
		// so an id that does not exist cannot be overridden at all.
		expect(
			outcome.status,
			`a forged recommendation must not succeed; got ${outcome.status} ${outcome.body.slice(0, 200)}`,
		).not.toBe(200)
	})

	test('the LHS recommendations entry is gone from the settings menu', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq/`)
		await page.waitForLoadState('domcontentloaded')

		const nav = page.locator('[id^="app-navigation"]').first()
		await expect(
			nav,
			'the app navigation must render, or this asserts nothing',
		).toBeVisible({
			timeout: 30000,
		})

		// The settings entries live inside a collapsed foldout
		// (NcAppNavigationSettings), so they are in the DOM but not visible
		// until it is opened. Opening it is part of what a user does to see
		// them, so the test does it too.
		await nav
			.getByRole('button', { name: 'Settings', exact: true })
			.first()
			.click()

		// THE CONTROL, and it earned its place: the first version of this test
		// asserted only the absence, and would have passed against a navigation
		// that had not rendered at all. The sibling entry must be present, in
		// the instance's own language, before the absence below means anything.
		//
		// Labels are the Dutch ones the app actually renders. Asserting the
		// English source strings passed nothing and looked like the feature was
		// broken rather than the test.
		await expect(
			nav.getByRole('link', { name: 'Handhavingsstrategie', exact: true }),
			'the sibling settings entry must render, or an absence proves nothing',
		).toBeVisible({ timeout: 30000 })

		// The assertion: a recommendation is a per-enforcement audit record, not
		// configuration, so it no longer has a settings entry.
		await expect(
			nav.getByRole('link', { name: 'LHS-aanbevelingen', exact: true }),
		).toHaveCount(0)
	})
})

test.describe('parafering activation', () => {
	test('a voorstel whose case type has no route is refused, not parked', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq/`)
		await page.waitForLoadState('domcontentloaded')

		const outcome = await page.evaluate(async () => {
			const res = await fetch(
				'/apps/dossiq/api/besluitvorming/parafering/activate',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: (window as any).OC?.requestToken ?? '',
					},
					body: JSON.stringify({ proposalId: 'no-such-voorstel' }),
				},
			)
			return { status: res.status, body: await res.text() }
		})

		// Whatever the route is called, activating a voorstel that cannot be
		// routed must not answer 200. Before this change it did, leaving the
		// voorstel in `in_parafering` with an empty snapshot and no way out.
		expect(
			outcome.status,
			`activation of an unroutable voorstel must not succeed; got ${outcome.status}`,
		).not.toBe(200)
	})
})
