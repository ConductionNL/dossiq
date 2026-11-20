/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * vth-cases-run-end-to-end task 4.1: both scenarios of REQ-VAS-PKG-01 (a
 * package shows its configuration on its own admin tab).
 *
 * A package declares `adminTab` in its package.json (title and lists).
 * `AdminSettings` provides the installed packages that declare one as the
 * initial state `packageAdminTabs` (PackageAdminTabs::installed(), read from
 * the shipped-set ledger), `AdminRoot.vue` adds one tab per entry, and
 * `PackageAdminTab.vue` draws one section per declared list
 * (`section-package-<id>-<list>`) with one row per installed item
 * (`package-item-<list>`).
 *
 * THE EXPECTATIONS COME FROM THE PACKAGE, NOT FROM THIS FILE: the six case
 * types, the ten flows with their draft or published state and the three
 * inspection checklists are read from lib/Settings/packages/vth/.
 *
 * The VTH package ships no fee rules, templates or connections of its own,
 * so those three sections are asserted to be present, not to hold rows.
 *
 * The second scenario's control is the VTH tab: without it an absent
 * subsidie tab would prove only that no package tab rendered. Note that the
 * subsidie package declares no `adminTab` at all, so its absence is also what
 * an installed subsidie would show; vth is the only package that declares one.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { getRequestToken } from './helpers/fixtures.ts'
import { PAGE_LOAD, PAGE_LOAD_MS } from './helpers/nav.ts'

/** dossiq's Nextcloud admin settings page. */
const SETTINGS = '/index.php/settings/admin/dossiq'

/** dossiq's package catalogue. */
const PACKAGES = '/index.php/apps/dossiq/api/packages'

/** The vth package in this checkout. */
const VTH_DIR = path.join(__dirname, '..', '..', 'lib', 'Settings', 'packages', 'vth')

/**
 * One JSON file of the vth package.
 *
 * @param parts Path segments under the package directory.
 * @return The parsed file.
 */
function vthFile(...parts: string[]): any {
	return JSON.parse(readFileSync(path.join(VTH_DIR, ...parts), 'utf8'))
}

const MANIFEST = vthFile('package.json')
const CASE_TYPE_TITLES: string[] = (vthFile('case-type.json').caseType as any[]).map((row) => String(row.title))
const FLOWS: Array<{ title: string; status: string }> = readdirSync(path.join(VTH_DIR, 'flows'))
	.filter((name) => name.endsWith('.json'))
	.map((name) => vthFile('flows', name))
	.map((flow) => ({ title: String(flow.title), status: String(flow.lifecycleStatus) }))
const CHECKLIST_TITLES: string[] = (vthFile('seed', 'inspection-checklists.json').rows as any[]).map((row) =>
	String(row.title ?? row.name),
)

/** How the tab words a flow's state (packageItemStatus). */
const STATUS_WORDS: Record<string, string> = { draft: 'Draft', published: 'Published' }

let api: APIRequestContext
let token = ''

/**
 * The catalogue entry of one package.
 *
 * @param id The package id.
 * @return The entry, or undefined when the catalogue does not list it.
 */
async function catalogueEntry(id: string): Promise<any> {
	const res = await api.get(PACKAGES, { headers: { requesttoken: token } })
	expect(res.ok(), `package catalogue -> ${res.status()}`).toBeTruthy()
	return ((await res.json()).results as any[]).find((row) => row.id === id)
}

/**
 * Open the admin settings on one tab.
 *
 * @param page The page.
 * @param tab  The tab id.
 */
async function openSettings(page: Page, tab: string): Promise<void> {
	await page.goto(`${SETTINGS}?tab=${encodeURIComponent(tab)}`, PAGE_LOAD)
	await expect(page.getByTestId('cn-admin-settings-tabs')).toBeVisible({ timeout: PAGE_LOAD_MS })
}

/**
 * The package tabs the server provided to the page, decoded from its initial state.
 *
 * @param page The page, on the admin settings.
 * @return The installed packages with a tab.
 */
async function providedPackageTabs(page: Page): Promise<any[]> {
	const encoded = await page
		.locator('#initial-state-dossiq-packageAdminTabs')
		.getAttribute('value', { timeout: 10_000 })
	expect(encoded, 'the server provides packageAdminTabs to the settings page').toBeTruthy()
	return JSON.parse(Buffer.from(String(encoded), 'base64').toString('utf8'))
}

/**
 * The rows of one list on a package tab: each label and the state it shows.
 *
 * @param tab  The package tab.
 * @param list The list name.
 * @return The rows.
 */
async function rowsOf(tab: Locator, list: string): Promise<Array<{ label: string; status: string; href: string }>> {
	return tab.locator(`[data-testid="package-item-${list}"]`).evaluateAll((items) =>
		items.map((item) => {
			const label = item.querySelector(':scope > a, :scope > span:not(.dq-package-tab__status)')
			return {
				label: (label?.textContent ?? '').trim(),
				status: (item.querySelector('.dq-package-tab__status')?.textContent ?? '').trim(),
				href: label?.getAttribute('href') ?? '',
			}
		}),
	)
}

test.describe('A package shows its configuration on its own admin tab', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
		expect(MANIFEST.adminTab?.title, 'the vth package declares its admin tab').toBe('VTH')
	})

	test.afterAll(async () => {
		await api?.dispose()
	})

	// @e2e openspec/changes/vth-cases-run-end-to-end/specs/vth-admin-settings/spec.md#scenario-the-vth-tab-lists-what-the-package-installed
	test('the VTH tab lists what the package installed', async ({ page }) => {
		const vth = await catalogueEntry('vth')
		test.skip(!vth?.installed, 'the vth package is not installed on this instance')

		await openSettings(page, 'vth')

		const provided = (await providedPackageTabs(page)).find((entry) => entry.id === 'vth')
		expect(provided, 'the server lists vth among the installed packages with a tab').toBeTruthy()
		expect(provided.title).toBe('VTH')
		expect(Object.keys(provided.lists)).toEqual(MANIFEST.adminTab.lists)

		await expect(
			page.getByTestId('cn-admin-settings-tabs').getByText('VTH', { exact: true }),
			'a tab titled as the package declares',
		).toBeVisible()

		const tab = page.getByTestId('package-tab-vth')
		await expect(tab).toBeVisible({ timeout: PAGE_LOAD_MS })
		for (const list of MANIFEST.adminTab.lists as string[]) {
			await expect(tab.locator(`[id="section-package-vth-${list}"]`), `the ${list} section`).toBeVisible()
		}

		const caseTypes = await rowsOf(tab, 'caseTypes')
		expect(caseTypes.map((row) => row.label).sort(), 'the six case types').toEqual([...CASE_TYPE_TITLES].sort())
		for (const row of caseTypes) {
			expect(row.href, `${row.label} opens the case type editor`).toContain('/apps/dossiq/settings/case-types/')
		}

		const flows = await rowsOf(tab, 'flows')
		for (const flow of FLOWS) {
			const row = flows.find((candidate) => candidate.label === flow.title)
			expect(row, `the flow ${flow.title} is listed`).toBeTruthy()
			expect(row?.href, `${flow.title} opens the flow editor`).toContain('/apps/dossiq/flows/')
			expect(row?.status, `${flow.title} shows its draft or published state`).toBe(STATUS_WORDS[flow.status])
		}

		const checklists = await rowsOf(tab, 'checklists')
		expect(checklists.map((row) => row.label).sort(), 'the three inspection checklists').toEqual(
			[...CHECKLIST_TITLES].sort(),
		)

		// The DSO connection: the activities that turn an Omgevingsloket
		// application into a case, drawn on the VTH tab.
		await expect(page.locator('[id="section-intake-activities"]')).toBeVisible()
	})

	// @e2e openspec/changes/vth-cases-run-end-to-end/specs/vth-admin-settings/spec.md#scenario-an-uninstalled-package-has-no-tab
	test('an uninstalled package has no tab', async ({ page }) => {
		const subsidie = await catalogueEntry('subsidie')
		expect(subsidie, 'the catalogue lists the subsidie package').toBeTruthy()
		test.skip(Boolean(subsidie.installed), 'the subsidie package is installed on this instance, so the scenario has no subject here')

		const vth = await catalogueEntry('vth')
		test.skip(!vth?.installed, 'the control needs the vth package, which is not installed on this instance')

		await openSettings(page, 'subsidie')

		const provided = await providedPackageTabs(page)
		expect(provided.map((entry) => entry.id), 'the control: package tabs did reach the page').toContain('vth')
		expect(provided.map((entry) => entry.id), 'no tab for a package that is not installed').not.toContain('subsidie')

		const tabs = page.getByTestId('cn-admin-settings-tabs')
		await expect(tabs.getByText('VTH', { exact: true })).toBeVisible()
		await expect(tabs.getByText(/^Subsidie$/), 'no subsidie tab is shown').toHaveCount(0)
		await expect(page.getByTestId('package-tab-subsidie')).toHaveCount(0)
	})
})
