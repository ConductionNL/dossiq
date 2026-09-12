/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case-types admin smoke — covers the 7-tab integration spec'd by
 * case-types-03-result-role-tabs and case-types-04-property-doc-decision-tabs.
 *
 * Asserts the case-types management surface renders + the type-list +
 * the underlying admin chrome, AND the tab strip REQ-CT-15 describes.
 *
 * THE SEVEN-TAB CYCLE IS NO LONGER DEFERRED, and the note that said it was
 * is why nobody looked for eight months. CT-15a to CT-15g had no test in the
 * suite at all; six citations in `case-type-edit-and-setup.spec.ts` named
 * `TASK-CT-13` in an archived `tasks.md`, a task marked `[x] DEFERRED` whose
 * subject is exactly this, and not one of those six tests opens a tab. So the
 * scenarios read as covered while row-menu behaviour was what got proved.
 *
 * Three of the seven are asserted below. The other four are recorded on their
 * scenarios with reason-bearing excludes, because the spec names fields that
 * do not exist rather than because the surface is missing: the tabs ship.
 */

import { expect, test } from '@playwright/test'

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
	test('admin settings surface has an add-control for case types', async ({
		page,
	}) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(
			page.getByRole('heading', { name: 'Case Type Management' }),
		).toBeVisible({ timeout: 15000 })
		// The CnIndexPage management surface always renders an add control;
		// the exact label depends on whether the schema is seeded ("Add Case
		// Type") or generic ("Add Item"). Either is acceptable.
		const addBtn = page.getByRole('button', { name: /^Add (Item|Case Type)$/ })
		await expect(addBtn).toBeVisible({ timeout: 15000 })
	})

	/**
	 * Open the first case type on the admin surface and wait for its detail
	 * view.
	 *
	 * A ROW CLICK, not a deep link: `CaseTypeAdmin` swaps `CaseTypeList` for
	 * `CaseTypeDetail` in place, so the detail view has no route of its own and
	 * cannot be reached by URL. `rowClickToView` is what makes the body click
	 * open the row rather than tick its checkbox.
	 *
	 * @param page The Playwright page.
	 * @return The detail root, once its tab strip has rendered.
	 */
	async function openFirstCaseType(page) {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(
			page.getByRole('heading', { name: 'Case Type Management' }),
		).toBeVisible({ timeout: 60_000 })

		const admin = page.locator('.case-type-admin')
		const rows = admin.locator('[data-testid="cn-object-row"]')
		await expect(
			rows.first(),
			'the admin surface must list at least one case type, or there is '
				+ 'nothing to open and the assertions below address nothing',
		).toBeVisible({ timeout: 60_000 })
		await rows.first().click()

		const detail = page.locator('.case-type-detail')
		await expect(detail).toBeVisible({ timeout: 30_000 })
		return detail
	}

	/**
	 * CT-15a, clause by clause.
	 *
	 * THE TAB LIST IS ASSERTED AS A SUBSET, deliberately, and the reason is a
	 * finding rather than a convenience. The scenario names six tabs; the app
	 * ships ten. Decisions, Sub-cases, Workflow and Email were added after the
	 * scenario was written and no scenario mentions any of them. Asserting
	 * `toHaveText([...six])` would redden on shipped, wanted behaviour, so what
	 * is pinned is that each of the six the requirement names is present, which
	 * still reddens when one is removed or renamed.
	 *
	 * ✅ MUTATION CHECK RUN 2026-09-12, on a private disposable instance, with
	 * `tests/e2e/helpers/mutate-bundle.ts` against `dossiq-settings.js`:
	 *
	 *   find    /activeTab:"general"/   replace 'activeTab:"statuses"'
	 *   red on  "General is the default tab"
	 *           Expected: "General", Received: "Statuses"
	 *
	 * The other two tests below stayed GREEN under that same break, which is
	 * the half worth recording: each of the three reddens on ITS own clause and
	 * on nobody else's, so none of them is passing by looking at the wrong
	 * panel.
	 */
	// @e2e openspec/specs/case-types/spec.md#ct-15a-tab-layout
	test('the case type detail shows the six named tabs, General active, Save on top', async ({
		page,
	}) => {
		const detail = await openFirstCaseType(page)

		// THEN the page MUST display tabs: General, Statuses, Results, Roles,
		// Properties, Docs.
		for (const label of [
			'General',
			'Statuses',
			'Results',
			'Roles',
			'Properties',
			'Docs',
		]) {
			await expect(
				detail.locator('.case-type-detail__tab', { hasText: new RegExp(`^${label}$`) }),
				`the tab strip must offer ${label}`,
			).toHaveCount(1, { timeout: 20_000 })
		}

		// AND the General tab MUST be active by default. The active tab is a
		// class the component sets, so this reads the page's own verdict rather
		// than inferring it from which panel happens to be on screen.
		const active = detail.locator('.case-type-detail__tab--active')
		await expect(active, 'exactly one tab is active').toHaveCount(1)
		await expect(active, 'General is the default tab').toHaveText('General')

		// AND a Save button MUST be visible at the top, which is to say in the
		// header ABOVE the strip and not inside whichever panel is open.
		const header = detail.locator('.case-type-detail__header')
		await expect(
			header.getByRole('button', { name: /^Save$/ }),
			'Save belongs to the page, not to the open tab',
		).toBeVisible({ timeout: 20_000 })
	})

	/**
	 * CT-15d. The scenario names three columns and an Add control, and all
	 * three ship. `ResultsTab` renders them as its own labels, so they are read
	 * off the open panel rather than off the tab button.
	 *
	 * ✅ MUTATION CHECK: /Archive action/ -> 'ZZ-archive' in the settings
	 * bundle. Red on "the Results tab must show Archive action", element(s)
	 * not found, with the locator in the failure naming the scoping that makes
	 * it honest:
	 * `.case-type-detail .case-type-detail__tab-content getByText(/Archive action/i)`.
	 * CT-15a and CT-15e stayed green.
	 */
	// @e2e openspec/specs/case-types/spec.md#ct-15d-results-tab-content-v1
	test('the Results tab lists result types with archive action and retention', async ({
		page,
	}) => {
		const detail = await openFirstCaseType(page)
		await detail
			.locator('.case-type-detail__tab', { hasText: /^Results$/ })
			.click()

		const panel = detail.locator('.case-type-detail__tab-content')
		await expect(
			detail.locator('.case-type-detail__tab--active'),
			'the click must actually switch the tab',
		).toHaveText('Results')

		for (const label of ['Name', 'Archive action', 'Retention period']) {
			await expect(
				panel.getByText(new RegExp(label, 'i')).first(),
				`the Results tab must show ${label}`,
			).toBeVisible({ timeout: 20_000 })
		}
		await expect(
			panel.getByRole('button', { name: /Add/ }).first(),
			'the Results tab must offer an Add control',
		).toBeVisible({ timeout: 20_000 })
	})

	/**
	 * CT-15e. Two columns and an Add control, all shipped.
	 *
	 * ✅ MUTATION CHECK: /Generic role/ -> 'ZZ-generic' in the settings bundle.
	 * Red on "the Roles tab must show Generic role", element(s) not found,
	 * scoped to the open panel. CT-15a and CT-15d stayed green.
	 */
	// @e2e openspec/specs/case-types/spec.md#ct-15e-roles-tab-content-v1
	test('the Roles tab lists role types with name and generic role', async ({
		page,
	}) => {
		const detail = await openFirstCaseType(page)
		await detail.locator('.case-type-detail__tab', { hasText: /^Roles$/ }).click()

		const panel = detail.locator('.case-type-detail__tab-content')
		await expect(
			detail.locator('.case-type-detail__tab--active'),
			'the click must actually switch the tab',
		).toHaveText('Roles')

		for (const label of ['Name', 'Generic role']) {
			await expect(
				panel.getByText(new RegExp(label, 'i')).first(),
				`the Roles tab must show ${label}`,
			).toBeVisible({ timeout: 20_000 })
		}
		await expect(
			panel.getByRole('button', { name: /Add/ }).first(),
			'the Roles tab must offer an Add control',
		).toBeVisible({ timeout: 20_000 })
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
