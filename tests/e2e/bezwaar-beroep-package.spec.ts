/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bezwaar case type comes from its package (REQ-DQPAC-001, REQ-DQPAC-002).
 *
 * WHAT THIS SPEC IS FOR. Hydra ADR-118's testing split: a procedure that moved
 * onto a case definition package is proven by a Newman collection that reads
 * the rows back (tests/integration/packages/bezwaar-beroep.postman_collection.json,
 * run by .github/workflows/packages.yml) and by a Playwright journey. This is
 * the journey: an administrator installs `bezwaar-beroep` through the package
 * routes, the catalogue records the installed version, and the case type,
 * its statuses and its roles are there for a handler to open on the Case types
 * page.
 *
 * 🔴 THE IDS COME FROM THE PACKAGE, NOT FROM THIS FILE. The spec reads
 * lib/Settings/packages/bezwaar-beroep/ from disk, so a change to the package
 * changes what is asserted instead of leaving a stale copy behind.
 *
 * 🔴 NOT ON A SHARED INSTANCE. Installing writes the package's rows into the
 * register everybody on that instance works in. `refuseOnSharedInstance`
 * reports that as a failure with the reason, not as a skip.
 *
 * @spec openspec/changes/procedures-are-configuration-dossiq/specs/procedures-are-configuration/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { refuseOnSharedInstance } from './base-url.ts'
import { getRequestToken, REGISTER } from './helpers/fixtures.ts'
import { navToRoute, PAGE_LOAD_MS } from './helpers/nav.ts'
import { unprivilegedContext } from './helpers/principals.ts'

/** The package under test. */
const PACKAGE_ID = 'bezwaar-beroep'

/** Where the package lives in this checkout. */
const PACKAGE_DIR = path.join(__dirname, '..', '..', 'lib', 'Settings', 'packages', PACKAGE_ID)

/**
 * One JSON file of the package.
 *
 * @param name The file name inside the package directory.
 * @return The parsed file.
 */
function packageFile(name: string): any {
	return JSON.parse(readFileSync(path.join(PACKAGE_DIR, name), 'utf8'))
}

const MANIFEST = packageFile('package.json')
const CASE_TYPES = packageFile('case-type.json')

/** The bezwaar case type as shipped. */
const BEZWAAR = (CASE_TYPES.caseType as any[]).find((row) => row.identifier === 'bezwaar')

/** Its statuses, in their shipped order. */
const BEZWAAR_STATUSES = (CASE_TYPES.statusTypes as any[])
	.filter((row) => row.caseType === BEZWAAR?.id)
	.sort((a, b) => Number(a.order) - Number(b.order))

/** Its roles. */
const BEZWAAR_ROLES = (CASE_TYPES.roleTypes as any[]).filter(
	(row) => row.caseType === BEZWAAR?.id,
)

/** dossiq's package routes. */
const PACKAGES = '/index.php/apps/dossiq/api/packages'

/** OpenRegister's object API for the dossiq register. */
const OBJECTS = `/index.php/apps/openregister/api/objects/${REGISTER}`

test.describe('The bezwaar case type comes from its package', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	let api: APIRequestContext
	let token = ''

	test.beforeAll(async ({ playwright, baseURL }) => {
		refuseOnSharedInstance(
			'bezwaar-beroep-package.spec.ts',
			'It installs the bezwaar-beroep package, which writes case types, statuses, roles, flows and an example case into the shared register.',
		)
		expect(BEZWAAR, 'the package ships the bezwaar case type').toBeTruthy()
		expect(BEZWAAR_STATUSES.length, 'and its statuses').toBeGreaterThan(0)
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)
	})

	/**
	 * @spec openspec/changes/procedures-are-configuration-dossiq/specs/procedures-are-configuration/spec.md#scenario-the-bezwaar-case-type-comes-from-its-package
	 */
	test('an ordinary account cannot install a package', async ({ playwright, baseURL }) => {
		const colleague = await unprivilegedContext(playwright, String(baseURL))
		const res = await colleague.post(`${PACKAGES}/${PACKAGE_ID}/install`)
		expect(
			[401, 403],
			`a non-admin install answered ${res.status()} ${await res.text()}`,
		).toContain(res.status())
	})

	/**
	 * @spec openspec/changes/procedures-are-configuration-dossiq/specs/procedures-are-configuration/spec.md#scenario-the-bezwaar-case-type-comes-from-its-package
	 */
	test('an administrator installs it, and the catalogue records the version', async () => {
		const res = await api.post(`${PACKAGES}/${PACKAGE_ID}/install`, {
			headers: { requesttoken: token },
		})
		const body = await res.text()
		expect(res.status(), body).toBe(200)
		expect(['installed', 'current']).toContain(JSON.parse(body).status)

		const catalogue = await api.get(PACKAGES, { headers: { requesttoken: token } })
		expect(catalogue.ok(), `catalogue -> ${catalogue.status()}`).toBeTruthy()
		const entry = ((await catalogue.json()).results as any[]).find(
			(row) => row.id === PACKAGE_ID,
		)
		expect(entry, `the catalogue lists ${PACKAGE_ID}`).toBeTruthy()
		expect(entry.valid, 'the package validates').toBe(true)
		expect(entry.installed, 'the installed version is the shipped one').toBe(MANIFEST.version)
	})

	/**
	 * @spec openspec/changes/procedures-are-configuration-dossiq/specs/procedures-are-configuration/spec.md#scenario-the-bezwaar-case-type-comes-from-its-package
	 */
	test('the case type, its statuses and its roles are in the register', async () => {
		const caseType = await api.get(`${OBJECTS}/caseType/${BEZWAAR.id}`)
		expect(caseType.status(), await caseType.text()).toBe(200)
		const row = await caseType.json()
		expect(row.identifier).toBe('bezwaar')
		expect(row.title).toBe(BEZWAAR.title)

		for (const status of BEZWAAR_STATUSES) {
			const res = await api.get(`${OBJECTS}/statusType/${status.id}`)
			expect(res.status(), `status ${status.name}: ${await res.text()}`).toBe(200)
			expect((await res.json()).name).toBe(status.name)
		}

		for (const role of BEZWAAR_ROLES) {
			const res = await api.get(`${OBJECTS}/roleType/${role.id}`)
			expect(res.status(), `role ${role.name}: ${await res.text()}`).toBe(200)
		}
	})

	/**
	 * @spec openspec/changes/procedures-are-configuration-dossiq/specs/procedures-are-configuration/spec.md#scenario-the-bezwaar-case-type-comes-from-its-package
	 */
	test('installing again changes nothing', async () => {
		const res = await api.post(`${PACKAGES}/${PACKAGE_ID}/install`, {
			headers: { requesttoken: token },
		})
		const body = await res.text()
		expect(res.status(), body).toBe(200)
		expect(JSON.parse(body).status, 'a second install finds it current').toBe('current')
	})

	/**
	 * @spec openspec/changes/procedures-are-configuration-dossiq/specs/procedures-are-configuration/spec.md#scenario-the-bezwaar-case-type-comes-from-its-package
	 */
	test('a handler finds Bezwaar on the Case types page and opens it', async ({ page }) => {
		await navToRoute(page, '/settings/case-types')
		const row = page.getByRole('row').filter({ hasText: BEZWAAR.title })
		await expect(row.first(), 'the case type list offers Bezwaar').toBeVisible({
			timeout: PAGE_LOAD_MS,
		})
		await row.first().getByText(BEZWAAR.title, { exact: true }).first().click()
		await expect(page).toHaveURL(/\/settings\/case-types\/[^/]+$/, {
			timeout: PAGE_LOAD_MS,
		})
		await expect(page.getByText(BEZWAAR.title).first()).toBeVisible({ timeout: PAGE_LOAD_MS })
	})
})
