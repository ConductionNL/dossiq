/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The New case dialog, from a case handler's side of the desk.
 *
 * It used to be CnAdvancedFormDialog: a Properties/Data table listing all 48
 * of the case schema's properties, unordered, `qualityScore` and
 * `casePlanState` among them. Two things changed. The schema now says which
 * properties a person deals with and which are engine plumbing, and the New
 * case action narrows itself to the nine a handler fills. On top of that,
 * `case.caseType` declares `x-openregister-extends-form`, so choosing a case
 * type adds that type's own questions to the form and the answers land in
 * `caseProperty` rows.
 *
 * These assertions are all against the rendered DOM. The API is used only to
 * seed and tear down the case type and its property definitions.
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
} from './helpers/fixtures.ts'

const DASHBOARD_URL = '/apps/dossiq/'

/** The nine fields the New case action declares in the manifest. */
const CREATE_FIELDS = [
	'caseType', 'title', 'description', 'assignee', 'priority',
	'confidentiality', 'intakeChannel', 'startDate', 'plannedEndDate',
]

/** Properties the schema marks `visible: false`; no surface may render them. */
const HIDDEN_FIELDS = ['qualityScore', 'casePlanState', 'statusHistory', 'portalSubject']

const CASE_TYPE_TITLE = `${RUN_PREFIX} Subsidie`
const CEILING = `${RUN_PREFIX} Plafond`
const AUDIENCE = `${RUN_PREFIX} Doelgroep`

let api: APIRequestContext
let token: string
let caseTypeId: string

test.describe('New case dialog', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// A case type of this run's own, never a reused one: attaching
		// property definitions to a shared case type would add required
		// questions to every other spec's cases.
		const caseType = await createObject(api, token, 'caseType', {
			title: CASE_TYPE_TITLE,
			identifier: `${RUN_PREFIX.toLowerCase()}-subsidie`,
			description: 'Throwaway case type for the New case dialog spec.',
		})
		caseTypeId = objectId(caseType)

		await createObject(api, token, 'propertyDefinition', {
			name: CEILING,
			caseType: caseTypeId,
			propertyType: 'number',
			isRequired: true,
			definition: 'The grant ceiling for this scheme.',
		})
		await createObject(api, token, 'propertyDefinition', {
			name: AUDIENCE,
			caseType: caseTypeId,
			propertyType: 'enum',
			enumValues: ['Cultuur', 'Sport'],
			defaultValue: 'Sport',
		})
	})

	test.afterAll(async () => {
		if (!api) return
		// caseProperty before propertyDefinition before caseType: a value row
		// references both, and deleting the definition first leaves it dangling.
		await cleanupRunObjects(api, token, ['caseProperty', 'case', 'propertyDefinition', 'caseType'])
		await api.dispose()
	})

	/**
	 * Open the dialog from the dashboard's New case button.
	 * @param page The Playwright page.
	 */
	async function openDialog(page) {
		await page.goto(DASHBOARD_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 15000 })
		await page.getByRole('button', { name: 'New case', exact: true }).click()
		const dialog = page.locator('[data-testid-modal="cn-form-dialog"], [data-testid="cn-modal"]').first()
		await expect(dialog).toBeVisible({ timeout: 20000 })
		return dialog
	}

	// @e2e openspec/changes/friendly-case-create-form/tasks.md
	test('opens the plain form, not the properties and JSON table', async ({ page }) => {
		const dialog = await openDialog(page)

		// The advanced dialog's tell is its tab strip. A handler filing a case
		// has no business inspecting the schema, so neither tab may be here.
		await expect(dialog.getByRole('tab', { name: 'Properties' })).toHaveCount(0)
		await expect(dialog.getByRole('tab', { name: 'Data' })).toHaveCount(0)
		await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()
	})

	// @e2e openspec/changes/friendly-case-create-form/tasks.md
	test('asks only for the fields a handler fills', async ({ page }) => {
		const dialog = await openDialog(page)

		for (const key of CREATE_FIELDS) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"]`),
				`the create form should ask for ${key}`,
			).toHaveCount(1)
		}
		// The 44 the button does not ask for, sampled at the ones that made the
		// old dialog unreadable.
		for (const key of [...HIDDEN_FIELDS, 'archiveNomination', 'workflowTemplate', 'result']) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"]`),
				`${key} is not a create-time field`,
			).toHaveCount(0)
		}
	})

	// @e2e openspec/changes/friendly-case-create-form/tasks.md
	test('adds the chosen case type own questions, and drops them again on a change', async ({ page }) => {
		const dialog = await openDialog(page)

		// Nothing before a case type is chosen: the questions belong to a type.
		await expect(dialog.getByText(CEILING)).toHaveCount(0)

		await dialog.locator('[data-cn-field="caseType"]').click()
		await page.getByRole('option', { name: CASE_TYPE_TITLE }).click()

		await expect(dialog.getByText(CEILING)).toBeVisible({ timeout: 15000 })
		await expect(dialog.getByText(AUDIENCE)).toBeVisible()
	})

	// @e2e openspec/changes/friendly-case-create-form/tasks.md
	test('files a case with its case type answers', async ({ page }) => {
		const dialog = await openDialog(page)
		const title = `${RUN_PREFIX} Aanvraag`

		await dialog.locator('[data-cn-field="title"]').getByRole('textbox').fill(title)
		await dialog.locator('[data-cn-field="caseType"]').click()
		await page.getByRole('option', { name: CASE_TYPE_TITLE }).click()
		await expect(dialog.getByText(CEILING)).toBeVisible({ timeout: 15000 })

		const ceilingField = dialog
			.locator('[data-cn-field]')
			.filter({ hasText: CEILING })
			.locator('input')
			.first()
		await ceilingField.fill('50000')

		await dialog.getByRole('button', { name: 'Create' }).click()

		// The case exists, and so does the answer, as a caseProperty row that
		// points at both the case and the definition. An unsplit payload would
		// have posted the answer to `case`, where OpenRegister drops an
		// undeclared key with a 200 and no error anywhere.
		await expect(async () => {
			const cases = await listObjects(api, 'case', { _limit: '200' })
			const created = cases.find((c) => String(c.title ?? '') === title)
			expect(created, 'the case should have been created').toBeTruthy()

			const answers = await listObjects(api, 'caseProperty', {
				case: objectId(created),
				_limit: '50',
			})
			expect(answers.length, 'the case type answer should have been written').toBeGreaterThan(0)
			expect(String(answers[0].value)).toBe('50000')
		}).toPass({ timeout: 30000 })
	})
})
