/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's field form, as the manifest declares it.
 *
 * The Fields tab of a case type is the `case-type-fields-editor` object-list
 * widget. Its Add button opens the library's CnFormDialog over the
 * `propertyDefinition` schema, with the fields and overrides the manifest
 * lists. Every field renders inside `[data-cn-field="<key>"]`, which is the
 * one handle that survives a label change or a translation.
 */

import type { Locator, Page } from '@playwright/test'

import { expect } from '@playwright/test'
import { openCaseTypeTab } from './nav.ts'

/**
 * Open the Fields tab of a case type and return the field list on it.
 *
 * @param page  The Playwright page.
 * @param title The case type's title.
 * @return The field list widget.
 */
export async function openFieldsTab(page: Page, title: string): Promise<Locator> {
	await openCaseTypeTab(page, title, /^(Fields|Velden)/)
	const panel = page.locator('.cn-object-list-widget').filter({ has: page.getByRole('button', { name: /^(Add field|Veld toevoegen)$/ }) })
	await expect(panel.first()).toBeVisible({ timeout: 30_000 })
	return panel.first()
}

/**
 * Open the Add field form from the Fields tab.
 *
 * @param page  The Playwright page.
 * @param panel The field list widget.
 * @return The open form dialog.
 */
export async function openAddField(page: Page, panel: Locator): Promise<Locator> {
	await panel.getByRole('button', { name: /^(Add field|Veld toevoegen)$/ }).first().click()
	const dialog = page.getByRole('dialog').filter({ has: page.locator('[data-cn-field="name"]') })
	await expect(dialog).toBeVisible({ timeout: 30_000 })
	return dialog
}

/**
 * One field of the form, by its schema key.
 *
 * @param dialog The form dialog.
 * @param key    The property key.
 * @return The field's container.
 */
export function field(dialog: Locator, key: string): Locator {
	return dialog.locator(`[data-cn-field="${key}"]`)
}

/**
 * Pick an option in a select field.
 *
 * By TEXT, not accessible name: NcSelect splits a long label into two spans
 * for the ellipsis and the accessible name joins them with a space.
 *
 * @param page   The Playwright page.
 * @param dialog The form dialog.
 * @param key    The property key.
 * @param option The option's text.
 */
export async function pick(page: Page, dialog: Locator, key: string, option: RegExp): Promise<void> {
	await field(dialog, key).getByRole('combobox').click()
	await page.getByRole('option').filter({ hasText: option }).first().click()
}

/**
 * Open a folded section of the form (for example "More options").
 *
 * @param dialog  The form dialog.
 * @param section The section heading.
 */
export async function unfold(dialog: Locator, section: RegExp): Promise<void> {
	const toggle = dialog.getByTestId('cn-form-dialog-section-toggle').filter({ hasText: section })
	if ((await toggle.getAttribute('aria-expanded')) === 'false') {
		await toggle.click()
	}
}

/**
 * Turn the Choice list switch on.
 *
 * @param dialog The form dialog.
 */
export async function choiceListOn(dialog: Locator): Promise<void> {
	await field(dialog, 'choiceList').getByText(/^(Choice list|Keuzelijst)/).click()
	await expect(field(dialog, 'enumValues')).toBeVisible()
}

/**
 * Fill the choices, one per line. The textarea commits on change, so the
 * field is left with Tab.
 *
 * @param dialog The form dialog.
 * @param lines  The choices.
 */
export async function fillChoices(dialog: Locator, lines: string[]): Promise<void> {
	const box = dialog.getByTestId('cn-form-lines-enumValues')
	await box.fill(lines.join('\n'))
	await box.press('Tab')
}

/**
 * Submit the create form and wait for the result phase to report success.
 *
 * @param dialog The form dialog.
 */
export async function create(dialog: Locator): Promise<void> {
	await dialog.getByRole('button', { name: /^(Create|Aanmaken)$/ }).click()
	const result = dialog.locator('[data-testid-phase="result"]')
	await expect(result).toBeVisible({ timeout: 30_000 })
	await expect(result.locator('.notecard--error'), 'the server refused the field').toHaveCount(0)
	await expect(result.locator('.notecard--success')).toBeVisible()
}
