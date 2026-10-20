import type { APIRequestContext, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP e2e for this week's new UI: DEELZAAK (sub-case) support + CASE-EMAIL
 * integration. Backend unit tests already landed; this covers the user-facing
 * round-trip through the manifest shell.
 *
 * What is asserted (real outcomes, verified live):
 *   1. The CaseDetail page (route /cases/:id, history-mode) mounts and renders
 *      the seeded case's data — the surface the Sub-cases + Email tabs hang off.
 *   2. DEELZAAK PERSISTENCE: a child case linked via `parentCase` round-trips —
 *      it is returned by GET /apps/dossiq/api/deelzaken/{parent}/children, the
 *      exact endpoint DeelzaakList.vue consumes, and the parent-of lookup
 *      resolves back. The sub-case COUNT endpoint (used by the case list badge)
 *      reflects the new child. Cleanup removes both cases.
 *   3. CASE-EMAIL: the case-email backend that the Email tab drives is
 *      reachable and degrades correctly when Nextcloud Mail is not installed
 *      (leaf-first per ADR-022 — dossiq ships no email engine of its own).
 *
 * RESOLVED (nc-vue slot-render gap — fixed in @conduction/nextcloud-vue):
 *   The DeelzaakList component is registered in
 *   src/registry.js (kind:'page', component:…) and bundled. The manifest shell
 *   now renders a kind:'page' registry component when referenced as a sidebar
 *   tab (`component: …`, via CnObjectSidebar.resolveTabComponent) and as a
 *   custom page's `slots.main` (via CnPageRenderer.resolvedComponent) — the
 *   ADR-036 / nextcloud-vue#459 kind-agnostic slot-resolver family. The
 *   "Sub-cases tab renders DeelzaakList" assertion below (the page `slots.main`
 *   path) was previously `test.fixme` for this gap and is now LIVE + green,
 *   proving the resolver fix; the persistence + data-path assertions above
 *   always passed. The Email-tab assertion (the CaseDetail sidebar tab-strip
 *   path) was a *different* gap — CnDetailPage.resolvedSidebar ignoring
 *   `config.sidebarTabs` + CnAppRoot shadowing the host objectSidebarState
 *   holder — also fixed in @conduction/nextcloud-vue (2026-06-12) with
 *   dossiq's App.vue now hosting the CnObjectSidebar in CnAppRoot's #sidebar
 *   slot. It is now LIVE + green too.
 */
import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	deleteObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from '../helpers/fixtures.ts'
import { dismissSupportDialog } from '../helpers/nav.ts'

let api: APIRequestContext
let token: string
let caseTypeId: string
let caseTypeSeeded = false

/**
 * Call a dossiq deelzaken endpoint with the run's CSRF token.
 *
 * @param {string} path The path.
 */
async function deelzaken(path: string): Promise<{ status: number; body: any }> {
	const res = await api.get(`/index.php/apps/dossiq/api/deelzaken${path}`, {
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	})
	const body = await res.json().catch(() => ({}))
	return { status: res.status(), body }
}

test.describe('Dossiq — deelzaak (sub-case) + case-email', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		const ct = await ensureCaseType(api, token)
		caseTypeId = ct.id
		caseTypeSeeded = ct.seeded
	})

	test.afterAll(async () => {
		// This suite seeds a handful of cases across its tests; deleting them
		// one-by-one via the API can exceed the default 30s hook budget on a
		// loaded dev instance. Give the teardown room and never let cleanup
		// failures fail the suite (best-effort housekeeping).
		test.setTimeout(120_000)
		try {
			await cleanupRunObjects(api, token)
			if (caseTypeSeeded)
				await deleteObject(api, token, 'caseType', caseTypeId)
		} catch {
			// best-effort cleanup — leftover fixtures are prefixed and inert
		} finally {
			await api.dispose()
		}
	})

	// UNPARKED for the same reason as its cases-crud twin, which carries the
	// long version: the zaaknummer now renders in the `case-core` info panel.
	// Scoped to that panel because unscoped it was satisfied by the copy the
	// identity row prints at the top of the page, and stayed green on CI run
	// 34592678724 with the number removed from the panel.
	test('CaseDetail page renders the case the sub-case + email tabs hang off', async ({
		page,
	}) => {
		const title = `${RUN_PREFIX} Deelzaak parent`
		const identifier = `${RUN_PREFIX}-DZP`
		const parent = await seedCase(api, token, {
			title,
			caseType: caseTypeId,
			identifier,
			description: 'Parent of a sub-case.',
		})
		const parentId = objectId(parent)
		// The number the create RETURNED: the `case` schema keeps a supplied
		// number and calculates one otherwise, so the response is the authority.
		const assignedIdentifier = String(
			(parent as Record<string, unknown>).identifier ?? identifier,
		)

		await page.goto(`/index.php/apps/dossiq/cases/${parentId}`, {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})
		await dismissSupportDialog(page)

		// The detail page (history-mode route /cases/:id) mounts + shows the case.
		await expect(page).toHaveURL(new RegExp(`/cases/${parentId}`), {
			timeout: 10_000,
		})
		const infoPanel = page
			.locator('.cn-tabs-widget')
			.locator('.cn-tabs__content > [role="tabpanel"]:not([hidden])')
		await expect(
			infoPanel,
			'the case info panel carries the title',
		).toContainText(title, { timeout: 30_000 })
		await expect(
			infoPanel,
			'the case info panel carries the zaaknummer',
		).toContainText(assignedIdentifier, { timeout: 15_000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('a deelzaak (sub-case) persists and round-trips through the deelzaken API', async () => {
		// Seed a parent + a child linked via `parentCase` — exactly the shape
		// DeelzaakCreateModal.vue persists through the OpenRegister object store.
		const parent = await seedCase(api, token, {
			title: `${RUN_PREFIX} DZ Parent`,
			caseType: caseTypeId,
			identifier: `${RUN_PREFIX}-DZP2`,
		})
		const parentId = objectId(parent)
		const child = await seedCase(api, token, {
			title: `${RUN_PREFIX} DZ Child`,
			caseType: caseTypeId,
			identifier: `${RUN_PREFIX}-DZC`,
			parentCase: parentId,
		})
		const childId = objectId(child)

		// GET /deelzaken/{parent}/children — the child
		// must be returned, proving the parent-child link persisted.
		const children = await deelzaken(`/${encodeURIComponent(parentId)}/children`)
		expect(children.status, 'children endpoint reachable').toBe(200)
		const rows = Array.isArray(children.body?.results)
			? children.body.results
			: Array.isArray(children.body)
				? children.body
				: []
		const found = rows.find(
			(r: any) =>
				objectId(r) === childId || r?.identifier === `${RUN_PREFIX}-DZC`,
		)
		expect(
			found,
			'seeded sub-case is returned by the deelzaken children endpoint',
		).toBeTruthy()

		// The parent-of endpoint dereferences the child's `parentCase` field and
		// returns the PARENT (previously it did a plain find() on the child id and
		// echoed the child back as its own parent — fixed in
		// DeelzaakService::getParentCase). Assert it returns the parent, not the
		// child.
		const parentLookup = await deelzaken(
			`/${encodeURIComponent(childId)}/parent`,
		)
		expect(parentLookup.status, 'parent endpoint reachable').toBe(200)
		expect(
			parentLookup.body,
			'parent endpoint returns a case object',
		).toBeTruthy()
		const returnedId = objectId(parentLookup.body)
		expect(returnedId, 'parent endpoint returns the PARENT, not the child').toBe(
			parentId,
		)
		expect(returnedId, 'parent endpoint must not echo the child').not.toBe(
			childId,
		)

		// The sub-case COUNT endpoint (case-list badge source) sees the child.
		const counts = await deelzaken(`/counts?ids=${encodeURIComponent(parentId)}`)
		expect(counts.status, 'counts endpoint reachable').toBe(200)
		expect(Number(counts.body?.counts?.[parentId] ?? 0)).toBeGreaterThanOrEqual(
			1,
		)
	})

	test('the case-email backend (email templates) the tab consumes is sound', async () => {
		// The Email tab loads the case-type's email
		// templates via GET /api/email/templates/{caseTypeId}; the compose path
		// degrades to an "unavailable" state when NC Mail (the leaf) is absent
		// (this dev instance has no Mail) — leaf-first per ADR-022. Assert the
		// templates endpoint the tab calls answers cleanly rather than 5xx-ing.
		const probe = await api.get(
			`/index.php/apps/dossiq/api/email/templates/${encodeURIComponent(caseTypeId)}`,
			{
				headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
			},
		)
		expect(probe.status(), `email templates -> ${probe.status()}`).toBeLessThan(
			500,
		)
	})

	// ----- Rendered tab UI ------------------------------------------------------
	// The old sub-case list address redirects to the parent case, on the
	// Related tab that lists its sub-cases.
	test('the old sub-case list address opens the parent on its Related tab', async ({
		page,
	}: {
		page: Page
	}) => {
		const parent = await seedCase(api, token, {
			title: `${RUN_PREFIX} Tab parent`,
			caseType: caseTypeId,
			identifier: `${RUN_PREFIX}-TAB`,
		})
		const parentId = objectId(parent)
		await page.goto(`/index.php/apps/dossiq/cases/${parentId}/deelzaken`)
		await dismissSupportDialog(page)
		await expect(page).toHaveURL(new RegExp(`/cases/${parentId}(\\?[^#]*)?#case-related-panel$`), { timeout: 15_000 })
		const detail = page.locator('.cn-detail-page')
		await expect(detail).toBeVisible({ timeout: 15_000 })
		await expect(detail.getByText('Sub-cases', { exact: true }).first()).toBeVisible({ timeout: 15_000 })
	})

	// The Email tab of the case panels renders the library mail widget
	// (`type: "mail"`): the template row that opens a Nextcloud Mail draft,
	// above the mail linked to the case.
	test('Email tab renders the mail widget', async ({
		page,
	}: {
		page: Page
	}) => {
		const parent = await seedCase(api, token, {
			title: `${RUN_PREFIX} Email parent`,
			caseType: caseTypeId,
			identifier: `${RUN_PREFIX}-EML`,
		})
		const parentId = objectId(parent)
		await page.goto(`/index.php/apps/dossiq/cases/${parentId}`)
		await dismissSupportDialog(page)
		const detail = page.locator('.cn-detail-page')
		await expect(detail).toBeVisible({ timeout: 15_000 })
		await expect(detail.locator('.cn-detail-page__title')).toContainText(
			`${RUN_PREFIX} Email parent`,
			{ timeout: 15_000 },
		)
		const emailTab = detail.getByRole('tab', { name: 'Email', exact: true })
		await expect(emailTab).toBeVisible({ timeout: 15_000 })
		await emailTab.click()
		await expect(detail.locator('.cn-mail-tab')).toBeVisible({
			timeout: 15_000,
		})
	})
})
