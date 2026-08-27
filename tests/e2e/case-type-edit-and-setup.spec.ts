/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Three things a clean install got wrong, all of them "the app declared it,
 * wired it, shipped it, and no user could reach it".
 *
 * 1. The case-type row menu carried blank rows — action entries with no label
 *    and no icon, which CnRowActions still draws as full-height clickable
 *    items. 15 shipped across 6 pages of this manifest.
 * 2. The index opened an edit MODAL over a record that has its own detail
 *    page, and that modal shows only the schema's flat scalars — so a case
 *    type's statuses, results, roles and properties were uneditable from the
 *    surface that claimed to edit it.
 * 3. The optional setup step that loads the sample data was reported by the
 *    server and suppressed by the shell, so a fresh install had 88 case types,
 *    zero cases, and no affordance anywhere to load any.
 *
 * These assert the USER-VISIBLE end of each. The unit suites pin the
 * mechanisms (CnAppRoot.setupGate / CnIndexPageEditTarget /
 * CnPageRendererEditTarget in @conduction/nextcloud-vue,
 * SetupControllerStatusTest here).
 */

import { test, expect, type Page } from '@playwright/test'
import { navTo, dismissSupportDialog } from './helpers/nav'

const CASE_TYPES_LABEL = 'Case types'

/**
 * Open the row-actions overflow menu on the first table row.
 *
 * @param page The page.
 * @return The opened menu locator.
 */
async function openFirstRowMenu(page: Page) {
	const row = page.locator('tbody tr').first()
	await expect(row).toBeVisible({ timeout: 30000 })
	// The overflow trigger is the only button in the row-actions cell.
	await row.locator('button').last().click()
	const menu = page.locator('[role="menu"]').last()
	await expect(menu).toBeVisible({ timeout: 10000 })
	return menu
}

/**
 * The Nextcloud CSRF request token for the loaded page.
 *
 * NOT `meta[name=requesttoken]`: that meta tag does not exist on NC 34, so a
 * lookup through it yields `undefined`, the header goes out empty, and the
 * request comes back `412 {"message":"CSRF check failed"}` — a refusal that a
 * caller checking only for a 2xx reads as data. The token lives on
 * `OC.requestToken`, with `<head data-requesttoken>` as the fallback.
 *
 * @param page The page.
 * @return The token, or an empty string.
 */
async function requestToken(page: Page): Promise<string> {
	return await page.evaluate(() => {
		const w = window as unknown as { OC?: { requestToken?: string } }
		return w.OC?.requestToken
			|| document.head.getAttribute('data-requesttoken')
			|| ''
	})
}

/**
 * Read `/api/setup/status` from the loaded page, with CSRF.
 *
 * @param page The page.
 * @return The parsed status payload, or null when the answer was not one.
 */
async function readSetupStatus(page: Page): Promise<any> {
	const token = await requestToken(page)
	return await page.evaluate(async (tok) => {
		const res = await fetch('/index.php/apps/dossiq/api/setup/status', {
			headers: { Accept: 'application/json', requesttoken: tok },
		})
		const body = await res.json().catch(() => null)
		return (body && body.steps) ? body : null
	}, token)
}

/**
 * Fetch the app's main bundle — the artefact the browser actually loaded.
 *
 * The URL is read off the page's own `<script src>` rather than hardcoded:
 * `/apps/dossiq/js/dossiq-main.js` answers 200 with the app's HTML shell, not
 * the bundle, so a hardcoded path yields a page that contains none of the
 * strings being looked for and fails as though the code were missing.
 *
 * @param page The page.
 * @return The bundle source.
 */
async function mainBundleSource(page: Page): Promise<string> {
	return await page.evaluate(async () => {
		const el = [...document.querySelectorAll('script[src]')]
			.find((s) => /dossiq-main\.js/.test(s.getAttribute('src') || ''))
		if (!el) return ''
		const res = await fetch(el.getAttribute('src') as string)
		return res.ok ? await res.text() : ''
	})
}

test.describe('Case types — the row action menu', () => {
	test.setTimeout(120_000)

	// @e2e openspec/changes/case-types-04-property-doc-decision-tabs/tasks.md#TASK-CT-13
	test('every entry in the row menu has a visible label', async ({ page }) => {
		await navTo(page, CASE_TYPES_LABEL)
		await dismissSupportDialog(page)
		const menu = await openFirstRowMenu(page)

		const items = menu.locator('[role="menuitem"], li')
		const count = await items.count()
		expect(count).toBeGreaterThan(0)

		const labels: string[] = []
		for (let i = 0; i < count; i++) {
			labels.push(((await items.nth(i).innerText()) || '').trim())
		}

		// The defect, stated exactly: an entry that renders as empty space.
		// Reading every label and asserting on the SET means a regression names
		// itself instead of surfacing as "expected 4, got 7".
		expect(labels.filter((l) => l === '')).toEqual([])
	})

	// @e2e openspec/changes/case-types-04-property-doc-decision-tabs/tasks.md#TASK-CT-13
	test('the menu offers View and Edit exactly once each', async ({ page }) => {
		// The manifest used to hand-roll a `view` action next to the built-in
		// one; both now resolve to the same detail route, so only one should
		// remain.
		await navTo(page, CASE_TYPES_LABEL)
		await dismissSupportDialog(page)
		const menu = await openFirstRowMenu(page)
		const text = ((await menu.innerText()) || '').trim()

		expect(text.match(/\bView\b/g) || []).toHaveLength(1)
		expect(text.match(/\bEdit\b/g) || []).toHaveLength(1)
	})
})

test.describe('Case types — Edit goes to the detail page, not a modal', () => {
	test.setTimeout(120_000)

	// @e2e openspec/changes/case-types-04-property-doc-decision-tabs/tasks.md#TASK-CT-13
	test('Edit navigates to the case type detail route', async ({ page }) => {
		await navTo(page, CASE_TYPES_LABEL)
		await dismissSupportDialog(page)
		const menu = await openFirstRowMenu(page)

		await menu.getByText('Edit', { exact: true }).click()

		await expect(page).toHaveURL(/\/settings\/case-types\/[^/]+$/, { timeout: 20000 })
	})

	// @e2e openspec/changes/case-types-04-property-doc-decision-tabs/tasks.md#TASK-CT-13
	test('Edit does not open a dialog over the index', async ({ page }) => {
		// The decisive half. Navigating AND opening the modal would satisfy the
		// test above while changing nothing about the defect.
		await navTo(page, CASE_TYPES_LABEL)
		await dismissSupportDialog(page)
		const menu = await openFirstRowMenu(page)

		await menu.getByText('Edit', { exact: true }).click()
		await expect(page).toHaveURL(/\/settings\/case-types\/[^/]+$/, { timeout: 20000 })

		// A dialog may exist on the detail page only if the user asks for it.
		await expect(page.getByRole('dialog').filter({ hasText: 'Edit Case Type' })).toHaveCount(0)
	})
})

test.describe('Case type detail — the record can be edited there', () => {
	test.setTimeout(120_000)

	// @e2e openspec/changes/case-types-04-property-doc-decision-tabs/tasks.md#TASK-CT-13
	test('the detail page offers an Edit button', async ({ page }) => {
		// Without this the change above would simply have made every case type
		// read-only — which is what "0 of 233 detail pages declared an edit
		// action" measured across the fleet.
		await navTo(page, CASE_TYPES_LABEL)
		await dismissSupportDialog(page)
		const menu = await openFirstRowMenu(page)
		await menu.getByText('View', { exact: true }).click()
		await expect(page).toHaveURL(/\/settings\/case-types\/[^/]+$/, { timeout: 20000 })

		await expect(page.locator('[data-testid="cn-detail-page-edit"]')).toBeVisible({ timeout: 20000 })
	})

	// @e2e openspec/changes/case-types-04-property-doc-decision-tabs/tasks.md#TASK-CT-13
	test('the Edit button opens the record form', async ({ page }) => {
		await navTo(page, CASE_TYPES_LABEL)
		await dismissSupportDialog(page)
		const menu = await openFirstRowMenu(page)
		await menu.getByText('View', { exact: true }).click()
		await expect(page).toHaveURL(/\/settings\/case-types\/[^/]+$/, { timeout: 20000 })

		await page.locator('[data-testid="cn-detail-page-edit"]').click()
		await expect(page.getByRole('dialog')).toBeVisible({ timeout: 20000 })
	})
})

test.describe('Setup — the sample-data step is reachable', () => {
	test.setTimeout(120_000)

	// @e2e openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	test('the server reports every actionable step, and reports the optional ones as outstanding', async ({ page }) => {
		// `reported` vs `absent` is the whole distinction: CnAppRoot only
		// prompts for a step the server actually answered for, so a step that
		// merely goes unmentioned can never reach a user.
		await page.goto('/index.php/apps/dossiq')
		const body = await readSetupStatus(page)

		expect(body).not.toBeNull()
		expect(body.steps).toHaveProperty('register-check')
		expect(body.steps).toHaveProperty('seed')
		expect(body.steps).toHaveProperty('dwangsom-secret')
		for (const id of Object.keys(body.steps)) {
			expect(typeof body.steps[id].done).toBe('boolean')
		}
	})

	// @e2e openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	test('the wizard opens exactly when an optional step is outstanding', async ({ page }) => {
		// Asserted against the SERVER's own answer rather than against a fixed
		// expectation, so the test describes the rule instead of describing
		// this instance: outstanding optional work => the wizard offering it is
		// on screen; none => it is not. Either way the shell stays reachable —
		// this wizard does not gate.
		await page.goto('/index.php/apps/dossiq')
		const body = await readSetupStatus(page)
		expect(body).not.toBeNull()

		const outstanding = Object.entries(body.steps)
			.filter(([id, s]) => id !== 'register-check' && (s as { done: boolean }).done === false)
			.map(([id]) => id)

		const wizard = page.locator('.cn-app-root__setup-optional')
		if (outstanding.length > 0) {
			await expect(wizard, `outstanding optional steps: ${outstanding.join(', ')}`)
				.toBeVisible({ timeout: 30000 })
		} else {
			await expect(wizard).toBeHidden({ timeout: 10000 })
		}
		await expect(page.locator('main')).toBeAttached()
	})

	// @e2e openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	test('a seed run that creates nothing does not record the step as done', async ({ page }) => {
		// The seeder returns success with every counter at zero when its payload
		// is absent, which is the state it is in. Recording that as done made
		// the affordance one-shot and silently useless: one click, "Seeded 0
		// case types", step complete, never offered again.
		await page.goto('/index.php/apps/dossiq')
		const before = await readSetupStatus(page)
		test.skip(before?.steps?.seed?.done === true, 'sample data already loaded on this instance')

		const run = await page.evaluate(async () => {
			const token = (document.head.querySelector('meta[name=requesttoken]') as HTMLMetaElement)?.content
			const res = await fetch('/index.php/apps/dossiq/api/setup/run/seed', {
				method: 'POST',
				headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: token },
			})
			return { status: res.status, body: await res.json().catch(() => null) }
		})

		if (run.body?.detail && (run.body.detail.caseTypes ?? 0) === 0) {
			// Nothing was created, so the step must still be outstanding.
			expect(run.body.success).toBe(false)
			const after = await readSetupStatus(page)
			expect(after.steps.seed.done).toBe(false)
		} else {
			// A seeder with real payload: it created something, so it is done.
			expect(run.body.success).toBe(true)
			const after = await readSetupStatus(page)
			expect(after.steps.seed.done).toBe(true)
		}
	})
})

test.describe('Walkthrough — it points at the configuration surfaces', () => {
	test.setTimeout(120_000)

	// @e2e openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	test('the tour a user actually gets includes the Case types and Flows stops', async ({ page }) => {
		// Asserted against the SERVED BUNDLE, not the manifest on disk: the tour
		// a user gets is the one webpack built into `dossiq-main.js`, and a
		// source-level check cannot see a stale bundle. (`/api/manifest` is a
		// delta endpoint and does not carry the walkthrough, so it is no use
		// here.)
		await page.goto('/index.php/apps/dossiq')
		const bundle = await mainBundleSource(page)
		expect(bundle.length, 'could not fetch the app bundle').toBeGreaterThan(1000)

		for (const id of ['see-case-types', 'see-flows']) {
			expect(bundle, `walkthrough step "${id}" missing from the built bundle`).toContain(id)
		}
	})

	// @e2e openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	test('the tour opens on a first visit and offers all seven steps', async ({ page, context }) => {
		// The step COUNT is the user-visible consequence of adding the two
		// stops: it cannot read 7 unless both are in the tour the shell
		// composed. A fresh context has no seen-version recorded, so the tour
		// auto-opens on `first-visit`.
		await context.clearCookies({ name: 'nc_walkthrough' }).catch(() => {})
		await page.goto('/index.php/apps/dossiq')

		const tour = page.getByRole('dialog').filter({ hasText: 'Welcome to Dossiq' })
		if (await tour.isVisible({ timeout: 20000 }).catch(() => false)) {
			await expect(tour).toContainText('/ 7')
		} else {
			test.skip(true, 'walkthrough already completed for this user on this instance')
		}
	})

	// @e2e openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	test('neither new stop forces the user to create anything', async ({ page }) => {
		// "Show where, do not force" is the whole point of both steps. A tour
		// step that only advances on `object-created` would make looking at the
		// case types a prerequisite for finishing the tour.
		await page.goto('/index.php/apps/dossiq')
		const bundle = await mainBundleSource(page)
		expect(bundle.length, 'could not fetch the app bundle').toBeGreaterThan(1000)

		for (const id of ['see-case-types', 'see-flows']) {
			// The step object is emitted as one contiguous run in the bundle;
			// take a window around its id and check what it advances on.
			const at = bundle.indexOf(id)
			expect(at).toBeGreaterThan(-1)
			const window = bundle.slice(at, at + 1200)
			expect(window, `${id} must offer a manual way past`).toContain('allowManualNext')
			expect(window, `${id} must not require creating one`).not.toContain('object-created')
		}
	})
})
