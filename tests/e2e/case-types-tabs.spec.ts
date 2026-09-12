/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case-types admin smoke — covers the 7-tab integration spec'd by
 * case-types-03-result-role-tabs and case-types-04-property-doc-decision-tabs.
 *
 * Asserts the case-types management surface renders + the type-list +
 * the underlying admin chrome. Real "create + 7-tab edit" cycle is
 * data-dependent and runs in opsx-verify against a seeded register;
 * this spec covers the data-independent shell.
 */

import { expect, test } from '@playwright/test'
// 🔬 PROBE IMPORT, REMOVED IN THE NEXT COMMIT.
import { mutateBundle } from './helpers/mutate-bundle.ts'

const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'

test.describe('Case-types admin — 7-tab integration shell', () => {
	// Same reason as spec-coverage/admin-settings.spec.ts: the Nextcloud admin
	// settings page mounts fourteen OpenRegister-backed sections and has been
	// measured between ~7s and 3.2m under the CI `php -S` server — variable
	// enough to overrun even test.slow()'s tripled budget.
	test.setTimeout(300_000)

	// @e2e openspec/specs/admin-settings/spec.md#admin-settings-page-is-accessible
	test('admin settings surface renders the Case Type Management heading', async ({
		page,
	}) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })
		await expect(
			page.getByRole('heading', { name: 'Case Type Management' }),
		).toBeVisible({ timeout: 15000 })
	})

	// @e2e openspec/specs/admin-settings/spec.md#empty-case-type-list
	//
	// 🔴 THE SCENARIO'S GIVEN IS "no case types have been created", AND THIS
	// TEST NEVER ESTABLISHED IT. It opened the admin page against whatever the
	// register held, which on any seeded instance is a populated list, and
	// asserted an add control that renders identically either way. Both of the
	// scenario's own sentences, the empty state message and the guidance,
	// went unasserted, so nothing here could tell an empty list from a full
	// one.
	//
	// The register is emptied at the network, not on the instance. Deleting
	// every case type would take the surface away from every other spec in
	// this run and cannot be undone; a 200 with no results is the same input
	// the component sees on a fresh install, and it lands on this page alone.
	// The interception is COUNTED, because a route that matched nothing leaves
	// the real list answering and turns this straight back into the test it
	// used to be.
	test('an empty case type register says so and points at the first one', async ({
		page,
	}) => {
		// 🔬 MUTATION PROBE, REMOVED IN THE NEXT COMMIT. The requirement is
		// that an empty register says it is empty and says what to do next, so
		// the break is the page going back to the generic line it inherited
		// from CnIndexPage. Both strings are rewritten on their way into the
		// browser, so nothing on disk moves and no other session's instance
		// does either. `assertApplied()` below is what keeps this honest: a
		// mutation that matched nothing leaves the real strings rendering and
		// the green that follows would mean nothing at all.
		const mutation = await mutateBundle(
			page,
			[
				{
					label: 'the empty state stops naming case types',
					find: /"dossiq","No case types configured yet"/,
					replace: '"dossiq","No items found"',
				},
				{
					label: 'the empty state stops offering guidance',
					find: /"dossiq","Create your first case type to start handling cases\."/,
					replace: '"dossiq"," "',
				},
			],
			/dossiq-settings\.js/,
		)

		let emptied = 0
		await page.route('**/apps/openregister/api/objects/*/caseType*', async (route) => {
			emptied++
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ results: [], total: 0, page: 1, pages: 1 }),
			})
		})

		await page.goto(ADMIN_SETTINGS_URL)
		// 🔬 PROBE: after the navigation that loads the bundle, never before.
		mutation.assertApplied()
		await expect(
			page.getByRole('heading', { name: 'Case Type Management' }),
		).toBeVisible({ timeout: 15000 })

		// THEN an empty state message, and guidance towards the first case
		// type. Both are dossiq's own strings: `CnIndexPage`'s default is
		// "No items found", which is what this section used to show and what
		// the scenario asks it not to.
		await expect(
			page.getByText('No case types configured yet'),
			'the empty register says it is empty',
		).toBeVisible({ timeout: 15000 })
		await expect(
			page.getByText('Create your first case type to start handling cases.'),
			'and says what to do about it',
		).toBeVisible({ timeout: 15000 })

		// AND the add control, which is the thing that guidance points at.
		// The label depends on whether the schema resolved ("Add Case Type")
		// or not ("Add Item"); either is the same control.
		await expect(
			page.getByRole('button', { name: /^Add (Item|Case Type)$/ }),
		).toBeVisible({ timeout: 15000 })

		expect(
			emptied,
			'the case type fetch was never intercepted, so the real register '
				+ 'answered and this run proves nothing about an empty one',
		).toBeGreaterThan(0)
	})

	// @e2e exclude no scenario says the publish route must merely be reachable.
	// case-type-publish-validation names outcomes instead — 422 with no status
	// types, 422 with no final status, 422 without validFrom, 200 when every
	// prerequisite is met — and this probe asserts none of them, only that a
	// PATCH on a non-existent uuid stays under 500. Citing one of those
	// scenarios here would read as verified coverage of a validator this test
	// never exercises. The scenarios themselves are covered by
	// ZgwZtcRulesServiceTest; what is missing is an e2e that drives a real
	// publish and reads the status code back.
	test('publish validation endpoint exists at the case-types route', async ({
		page,
		request,
	}) => {
		// PATCH a non-existent case type should NOT 404 the entire route —
		// the endpoint is reachable (404 or 422 from the validator is fine;
		// 500 indicates a routing/ZgwBusinessRulesService bootstrap defect).
		await page.goto(ADMIN_SETTINGS_URL)
		const res = await request.patch(
			'/index.php/apps/dossiq/api/case-types/non-existent-uuid',
			{
				data: { isDraft: false },
				failOnStatusCode: false,
			},
		)
		// 200 / 404 / 422 are all reachable-OK. 500 is the failure mode.
		expect(res.status()).toBeLessThan(500)
	})
})
