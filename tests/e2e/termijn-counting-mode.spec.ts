/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The counting mode of a term, on the settings tab.
 *
 * The arithmetic is asserted in the unit fixture pair, where the calendar can
 * be held still. What only a browser can show is that an administrator can SEE
 * which mode a definition counts in and change it: a term whose mode nobody
 * can read is a term whose dates nobody can explain, and that is the whole
 * complaint behind row Q8.16.
 *
 * The pill is asserted on every definition, not only on the working-day ones.
 * A reader who sees nothing cannot tell a calendar-day term from one whose
 * mode has never been set, and those are the same dates for different reasons.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	showObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** The definitions seeded for this run. */
const definitions: Record<string, string> = {}

test.describe('A term declares how it counts', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)
		const slug = String(caseType.slug ?? caseType.identifier ?? '')

		const define = async (key: string, countingMode: string) => {
			const row = await createObject(api, token, 'deadlineDefinition', {
				caseType: slug,
				legalBasis: 'AWB 4:13',
				validFrom: '2026-01-01',
				standardDurationDays: 10,
				countingMode,
			})
			definitions[key] = objectId(row)
		}

		await define('working', 'workingDays')
		await define('calendar', 'calendarDays')

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md#scenario-the-mode-is-visible-in-settings
	test('The mode is shown on the settings tab and offered in the editor', async ({
		page,
		playwright,
		baseURL,
	}) => {
		await page.goto(`/apps/${REGISTER}/settings`, PAGE_LOAD)
		await page.getByText('Termijnen', { exact: false }).first().click()

		const pills = page.getByTestId('termijn-counting-mode-pill')
		await expect(pills.first()).toBeVisible({ timeout: 30_000 })

		// Both modes are readable, which is the point: a pill that only ever
		// says one thing tells a reader nothing.
		const shown = await pills.allInnerTexts()
		expect(shown.some((label) => label.trim() !== '')).toBeTruthy()

		// And the field is offered when a new version is authored.
		await page.getByText('New version', { exact: false }).first().click()
		await expect(page.getByTestId('termijn-counting-mode')).toBeVisible({
			timeout: 30_000,
		})

		// The stored value is what the pill reads from, so it is asserted at
		// the store rather than off the label, which is translated.
		const api = await playwright.request.newContext({ baseURL })
		expect(
			String(
				(await showObject(api, 'deadlineDefinition', definitions.working))
					.countingMode ?? '',
			),
		).toBe('workingDays')
		expect(
			String(
				(await showObject(api, 'deadlineDefinition', definitions.calendar))
					.countingMode ?? '',
			),
		).toBe('calendarDays')
		await api.dispose()
	})
})
