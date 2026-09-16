/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Filing a case that already exists, from the intake desk.
 *
 * The scoring is OpenRegister's and the policy is dossiq's, so this file
 * exercises the seam between them: a seeded case, a second one typed into the
 * New case dialog with the same subject and case type, and the panel that has
 * to name the first before the second exists.
 *
 * 🔴 THE THREE TESTS RUN IN ORDER AND THE ORDER IS PART OF THE FIXTURE.
 * Whether an account may file over a warning is a GROUP membership, which is
 * global to the instance and cannot be scoped per test. So the handler is
 * tested while `dossiq-coordinators` does not exist, the group is then created
 * and the session's own account added to it, and the coordinator is tested
 * after. Playwright runs a file serially, and `test.describe.configure` pins it
 * rather than relying on that.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	trackCreatedObject,
	updateObject,
} from './helpers/fixtures.ts'

const DASHBOARD_URL = '/apps/dossiq/'

/** The group the `case` schema names in its `overrideGroups`. */
const COORDINATORS = 'dossiq-coordinators'

const CASE_TYPE_TITLE = `${RUN_PREFIX} Kapvergunning`
const START_STATUS = `${RUN_PREFIX} Ontvangen`

/**
 * The subject both cases carry.
 *
 * Identical on purpose. The `case` dedup rules weight `title` at 0.2
 * `normalized` plus 0.1 `levenshtein` against a 0.3 cut-off, so an identical
 * subject inside one case type is exactly one signal's worth and reaches the
 * threshold on its own. A merely similar subject does not, which is the line
 * the declaration draws and the reason this fixture does not vary the wording.
 */
const SUBJECT = `${RUN_PREFIX} Kap eik Eikenlaan 14`

let api: APIRequestContext
let token: string
let caseTypeId: string
let existingCaseId: string
let currentUser: string

/**
 * Call the provisioning API, which is the only way to make an account a
 * coordinator from a test.
 *
 * @param method The HTTP verb.
 * @param path The OCS path.
 * @param data The form body, when there is one.
 */
async function ocs(
	method: 'post' | 'delete',
	path: string,
	data?: Record<string, string>,
): Promise<number> {
	const response = await api[method](path, {
		headers: {
			requesttoken: token,
			'OCS-APIRequest': 'true',
		},
		form: data ?? {},
	})

	return response.status()
}

test.describe.configure({ mode: 'serial' })

test.describe('The duplicate warning at intake', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		const whoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
		currentUser = String((await whoami.json())?.ocs?.data?.id ?? '')
		expect(currentUser, 'the session must resolve to a user id').not.toBe('')

		// The group must NOT exist yet: the first test asserts what a handler
		// sees, and a leftover group from an earlier run would make the session
		// a coordinator and turn that assertion green for the wrong reason.
		await ocs('delete', `/ocs/v2.php/cloud/groups/${COORDINATORS}`)

		const caseType = await createObject(api, token, 'caseType', {
			title: CASE_TYPE_TITLE,
			identifier: `${RUN_PREFIX.toLowerCase()}-kapvergunning`,
			description: 'Throwaway case type for the duplicate warning spec.',
			// PUBLISHED, NOT DRAFT. `case.caseType` carries
			// `x-relation-filter: {isDraft: false}`, so a draft type never
			// appears in the New case picker and the failure reads as a missing
			// option rather than as a draft.
			isDraft: false,
		})
		caseTypeId = objectId(caseType)

		const status = await createObject(api, token, 'statusType', {
			name: START_STATUS,
			caseType: caseTypeId,
			order: 1,
			isFinal: false,
			description: 'Throwaway starting status for the duplicate warning spec.',
		})
		await updateObject(api, token, 'caseType', caseTypeId, {
			initialStatus: objectId(status),
		})

		const existing = await seedCase(api, token, {
			title: SUBJECT,
			caseType: caseTypeId,
			description: 'The case the second filing is supposed to find.',
		})
		existingCaseId = objectId(existing)
	})

	test.afterAll(async () => {
		if (!api) return
		await ocs('delete', `/ocs/v2.php/cloud/groups/${COORDINATORS}`)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open the New case dialog and type the subject of the case that exists.
	 *
	 * @param page The Playwright page.
	 */
	async function fileTheSameCaseAgain(page) {
		await page.goto(DASHBOARD_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 15000 })
		await page.getByRole('button', { name: 'New case', exact: true }).click()

		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20000 })

		await dialog
			.locator('[data-cn-field="title"]')
			.getByRole('textbox')
			.fill(SUBJECT)

		const combo = dialog.getByRole('combobox', { name: /Case type/ })
		await combo.click()
		// Matched by TEXT: vue-select splits an option label into adjacent
		// spans and the accessible name joins them with a space, so an exact
		// name match never hits on a label carrying the run prefix.
		const option = page.getByRole('option').filter({ hasText: CASE_TYPE_TITLE })
		if (!(await option.isVisible().catch(() => false))) {
			await combo.pressSequentially(RUN_PREFIX, { delay: 30 })
		}
		await expect(option).toBeVisible({ timeout: 20000 })
		await option.click()

		await dialog.getByRole('button', { name: 'Create' }).click()

		return dialog
	}

	// @e2e openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md#a-likely-duplicate-is-shown
	test('names the open case this one looks like, with a link to it', async ({
		page,
	}) => {
		await fileTheSameCaseAgain(page)

		const panel = page.locator('[data-testid="duplicate-warning-note"]')
		await expect(panel).toBeVisible({ timeout: 30000 })

		// The match is named AND reachable. A panel that reported a count
		// without a link would leave a handler with a warning and nowhere to
		// check it, which is the whole gesture this exists for.
		const link = page.locator('[data-testid="duplicate-warning-link"]').first()
		await expect(link).toBeVisible()
		await expect(link).toHaveAttribute('href', new RegExp(existingCaseId))

		// The case type says `warn`, so the way through is offered.
		const carryOn = page.locator('[data-testid="duplicate-warning-continue"]')
		await expect(carryOn).toBeVisible()
		await expect(carryOn).toBeEnabled()

		await carryOn.click()

		await expect(async () => {
			const cases = await listObjects(api, 'case', { _limit: '200' })
			const filed = cases.filter((row) => String(row.title ?? '') === SUBJECT)
			expect(filed.length, 'the second case should have been filed').toBe(2)
			for (const row of filed) {
				trackCreatedObject('case', objectId(row))
			}
		}).toPass({ timeout: 30000 })
	})

	// @e2e openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md#block-stops-a-handler-not-a-coordinator
	test('offers a handler no way past a case type that blocks', async ({
		page,
	}) => {
		await updateObject(api, token, 'caseType', caseTypeId, {
			duplicatePolicy: 'block',
		})

		await fileTheSameCaseAgain(page)

		await expect(
			page.locator('[data-testid="duplicate-warning-blocked"]'),
		).toBeVisible({ timeout: 30000 })
		await expect(
			page.locator('[data-testid="duplicate-warning-continue"]'),
		).toHaveCount(0)
	})

	// @e2e openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md#block-stops-a-handler-not-a-coordinator
	test('offers a coordinator a way past, once they say why', async ({ page }) => {
		expect(
			await ocs('post', '/ocs/v2.php/cloud/groups', {
				groupid: COORDINATORS,
			}),
			'the coordinators group should have been created',
		).toBeLessThan(300)
		expect(
			await ocs('post', `/ocs/v2.php/cloud/users/${currentUser}/groups`, {
				groupid: COORDINATORS,
			}),
			'the session should have been made a coordinator',
		).toBeLessThan(300)

		await fileTheSameCaseAgain(page)

		const carryOn = page.locator('[data-testid="duplicate-warning-continue"]')
		await expect(carryOn).toBeVisible({ timeout: 30000 })

		// Offered, but not yet allowed. The reason is the point of the
		// override: without it the next handler reading two near-identical
		// cases cannot tell which of them was meant.
		await expect(carryOn).toBeDisabled()

		await page
			.locator('[data-testid="duplicate-warning-reason"]')
			.getByRole('textbox')
			.fill('A second tree on the same address, a separate permit.')

		await expect(carryOn).toBeEnabled()
		await carryOn.click()

		await expect(async () => {
			const cases = await listObjects(api, 'case', { _limit: '200' })
			const filed = cases.filter((row) => String(row.title ?? '') === SUBJECT)
			for (const row of filed) {
				trackCreatedObject('case', objectId(row))
			}
			const overridden = filed.filter(
				(row) => String(row.duplicateOverrideReason ?? '') !== '',
			)
			expect(
				overridden.length,
				'the reason should have been recorded on the case that was filed anyway',
			).toBe(1)
			expect(
				(overridden[0].duplicateOverrideOf ?? []).length,
				'the case should record what it was filed over',
			).toBeGreaterThan(0)
		}).toPass({ timeout: 30000 })
	})
})
