/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The New case type dialog, from the side of the person authoring a blueprint.
 *
 * It used to ask all 41 of the caseType schema's visible properties, in one
 * column, at default dialog width. Nothing was wrong with the dialog:
 * CnFormDialog has taken `size` and `columns` since two-column forms shipped.
 * CnIndexPage passed neither, so its built-in Add form had no way to ask, while
 * the New case button on the Dashboard got two columns because it is an
 * `open-form` HEADER ACTION and CnActionButtons did forward them. Same app,
 * same kind of record, two different forms.
 *
 * The layout half of this file therefore needs nextcloud-vue 2.46.0. The field
 * SET and the field ORDER do not: `includeFields` and `fieldOverrides` have
 * always been honoured on an index page, so those assertions hold either way.
 *
 * Every assertion here is against the rendered DOM. The API is used only to
 * clean up anything a run leaves behind.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { cleanupRunObjects, getRequestToken } from './helpers/fixtures.ts'

const CASE_TYPES_URL = '/apps/dossiq/#/settings/case-types'

/**
 * The twelve fields the manifest's `includeFields` declares, in the order its
 * `fieldOverrides` put them in. The order is the assertion, not a convenience:
 * no caseType property carries an `order`, so without the overrides the sort
 * falls through to alphabetical and the form opens on Category.
 */
const AUTHORING_FIELDS = [
	'title',
	'identifier',
	'category',
	'handlingModel',
	'confidentiality',
	'processingDeadline',
	'validFrom',
	'isDraft',
	'parentCaseType',
	'description',
	'purpose',
	'trigger',
]

/** The three prose fields, which must be multi-line and span both columns. */
const PROSE_FIELDS = ['description', 'purpose', 'trigger']

/**
 * Properties the create form must NOT ask for, one sampled from each group the
 * manifest note gives a reason for leaving off.
 */
const NOT_ASKED = [
	// Written by the publish action, never typed.
	'version',
	'previousVersion',
	'supersededBy',
	// Built up on the detail page, once the things they point at exist.
	'subCaseTypes',
	'decisionTypes',
	'relatedCaseTypes',
	'startableFlows',
	// A data protection officer's pass over an existing type.
	'processesPersonalData',
	'legalBasis',
	// Specialist codings nobody has to hand while naming a type.
	'iv3TaskField',
	'selectionListProcessType',
	'layerIds',
	// The one excluded for a harder reason: a $ref filtered by a caseType that
	// does not exist yet, so the picker would offer every OTHER type's statuses.
	'initialStatus',
]

let api: APIRequestContext
let token: string

test.describe('New case type dialog', () => {
	test.setTimeout(120_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)
	})

	test.afterAll(async () => {
		// In afterAll rather than in a test body: cleanup that only runs on the
		// happy path leaves rows behind exactly when a run failed.
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open the create dialog from the Case types index Add button.
	 *
	 * @param page The Playwright page.
	 * @returns The dialog ROOT, not the phase div that carries the testid:
	 *   NcDialog renders its buttons in a footer slot beside that div, so a
	 *   locator scoped to the testid finds the fields but never Create.
	 */
	async function openDialog(page) {
		await page.goto(CASE_TYPES_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 15000 })
		await page.getByTestId('cn-cta-primary').click()
		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20000 })
		return dialog
	}

	// @e2e case-types::the-create-form-asks-the-twelve-authoring-fields-and-not-the-rest
	// @e2e case-types::the-starting-status-is-not-asked-for-at-create-time
	test('asks the twelve authoring fields and not the other twenty-nine', async ({
		page,
	}) => {
		const dialog = await openDialog(page)

		for (const key of AUTHORING_FIELDS) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"]`),
				`the create form should ask for ${key}`,
			).toHaveCount(1)
		}

		for (const key of NOT_ASKED) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"]`),
				`${key} is not a create-time field`,
			).toHaveCount(0)
		}
	})

	// @e2e case-types::the-form-opens-on-the-title-rather-than-alphabetically
	test('opens on the title rather than alphabetically', async ({ page }) => {
		const dialog = await openDialog(page)

		const keys = await dialog
			.locator('[data-cn-field]')
			.evaluateAll((nodes) =>
				nodes.map((n) => n.getAttribute('data-cn-field')),
			)

		expect(keys).toEqual(AUTHORING_FIELDS)
		// Spelled out because it is the whole point of the override map: sorted
		// alphabetically this form opens on Category and buries Title in tenth.
		expect(keys[0]).toBe('title')
		expect(keys.indexOf('category')).toBeGreaterThan(0)
	})

	// @e2e case-types::the-fields-are-laid-out-in-two-columns
	test('lays the fields out in two columns', async ({ page }) => {
		const dialog = await openDialog(page)

		const form = dialog.locator('[data-testid-modal="cn-form-dialog"]')
		await expect(form).toHaveClass(/cn-form-dialog__form--two-column/)

		// Two columns means two distinct left edges among the single-line
		// fields. Asserting on the class alone would pass even if the CSS never
		// applied, which is exactly the failure worth catching.
		const lefts = await dialog
			.locator('[data-cn-field]')
			.evaluateAll((nodes) =>
				nodes
					.filter((n) => !n.className.includes('--wide'))
					.map((n) => Math.round(n.getBoundingClientRect().left)),
			)
		expect(new Set(lefts).size).toBe(2)
	})

	// @e2e case-types::the-fields-are-laid-out-in-two-columns
	test('gives the three prose fields the full width', async ({ page }) => {
		const dialog = await openDialog(page)

		const widths = await dialog.evaluate((root, prose) => {
			const box = (sel: string) => {
				const el = root.querySelector(sel)
				return el ? el.getBoundingClientRect().width : 0
			}
			return {
				form: box('[data-testid-modal="cn-form-dialog"]'),
				title: box('[data-cn-field="title"]'),
				prose: prose.map((k: string) => ({
					key: k,
					width: box(`[data-cn-field="${k}"]`),
				})),
			}
		}, PROSE_FIELDS)

		// Measured, not inferred from the class: the class is what ASKS for the
		// span, the width is whether it happened.
		for (const { key, width } of widths.prose) {
			expect(width, `${key} should span both columns`).toBeGreaterThan(
				widths.title * 1.5,
			)
		}
	})

	// @e2e case-types::the-fields-are-laid-out-in-two-columns
	test('renders the prose fields as multi-line inputs', async ({ page }) => {
		const dialog = await openDialog(page)

		for (const key of PROSE_FIELDS) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"] textarea`),
				`${key} is a paragraph, not a single line`,
			).toHaveCount(1)
		}
		// The counterpart: a short field is still a one-line input, so the
		// override map has not simply turned everything into a textarea.
		await expect(dialog.locator('[data-cn-field="title"] textarea')).toHaveCount(
			0,
		)
	})
})
