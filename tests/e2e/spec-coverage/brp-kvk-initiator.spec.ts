/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the brp-kvk-register-sets initiator UI
 * (initiator-selection + initiator-display specs). Register-side scenarios
 * (repair import, OR bsn validation, fixture parity against the live mock /
 * KvK test API) carry @e2e excludes in the specs — they are proven by
 * PHPUnit (BrpKvkRegisterSetsTest) and the external-integrations contract
 * lanes. These tests drive the procest-owned UI through real clicks: the
 * StartCaseWidget initiator step, cross-source search on the seeded
 * register sets, persistence of the projection fields, and the detail
 * display (including the no-initiator empty case).
 */

import { test, expect } from '@playwright/test'

const APP = '/index.php/apps/procest/index'

test.describe('Initiator selection (brp-kvk-register-sets)', () => {

	// @e2e openspec/specs/initiator-selection/spec.md#agent-picks-an-initiator-type
	test('start-case flow offers Person / Company / Contact and stays skippable', async ({ page }) => {
		await page.goto(`${APP}#/`)
		// The dashboard StartCaseWidget lists case types; picking one opens
		// the optional initiator step.
		await page.locator('.start-case-widget__card').first().click()
		await expect(page.getByRole('heading', { name: 'Who is the initiator?' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('Person', { exact: true })).toBeVisible()
		await expect(page.getByText('Company', { exact: true })).toBeVisible()
		await expect(page.getByText('Contact', { exact: true })).toBeVisible()
		// The case MUST remain creatable without selecting any initiator.
		await page.getByRole('button', { name: 'Skip' }).click()
		await expect(page).toHaveURL(/\/cases\//, { timeout: 20000 })
	})

	// @e2e openspec/specs/initiator-selection/spec.md#person-search-hits-the-brp-register-set
	test('person search lists a seeded personen-mock persona with BSN', async ({ page }) => {
		await page.goto(`${APP}#/`)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByLabel('Search initiator').fill('Janssen')
		const result = page.locator('.initiator-picker__result', { hasText: 'Stephan Janssen' })
		await expect(result).toBeVisible({ timeout: 15000 })
		await expect(result).toContainText('BSN 999990627')
		await expect(result).toContainText('1975-04-06')
	})

	// @e2e openspec/specs/initiator-selection/spec.md#company-search-hits-the-kvk-register-set
	test('company search by pinned KvK number lists the fixture company', async ({ page }) => {
		await page.goto(`${APP}#/`)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByText('Company', { exact: true }).click()
		await page.getByLabel('Search initiator').fill('69599084')
		const result = page.locator('.initiator-picker__result', { hasText: 'Test EMZ Dagobert' })
		await expect(result).toBeVisible({ timeout: 15000 })
		await expect(result).toContainText('KVK 69599084')
	})

	// @e2e openspec/specs/initiator-selection/spec.md#contacts-source-degrades-gracefully
	test('contact tab shows an explicit empty state, never an error toast', async ({ page }) => {
		await page.goto(`${APP}#/`)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByText('Contact', { exact: true }).click()
		await page.getByLabel('Search initiator').fill('zzz-no-such-contact-zzz')
		await expect(page.getByText('No contacts found')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('.toast-error, .toastify.toast-error')).toHaveCount(0)
	})

	// @e2e openspec/specs/initiator-selection/spec.md#selection-persists-on-the-case
	// @e2e openspec/specs/initiator-display/spec.md#initiator-visible-on-the-case
	test('picked persona persists as projection and shows on case detail with source link', async ({ page }) => {
		await page.goto(`${APP}#/`)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByLabel('Search initiator').fill('Janssen')
		await page.locator('.initiator-picker__result', { hasText: 'Stephan Janssen' }).click()
		await page.getByRole('button', { name: 'Use as initiator' }).click()
		await expect(page).toHaveURL(/\/cases\//, { timeout: 20000 })
		// Detail overview: initiator section with name, type, and source id
		// linking to the seeded brpPerson record.
		const section = page.getByTestId('initiator-section')
		await expect(section).toBeVisible({ timeout: 20000 })
		await expect(section).toContainText('Stephan Janssen')
		await expect(section).toContainText('Person')
		await expect(section.getByRole('link', { name: '999990627' })).toHaveAttribute('href', /openregister/)
	})

	// @e2e openspec/specs/initiator-display/spec.md#no-initiator-no-clutter
	test('a case created without initiator renders no initiator block', async ({ page }) => {
		await page.goto(`${APP}#/`)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByRole('button', { name: 'Skip' }).click()
		await expect(page).toHaveURL(/\/cases\//, { timeout: 20000 })
		await expect(page.getByTestId('initiator-section')).toHaveCount(0)
	})
})
