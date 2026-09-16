/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which notices reach you, and which layer decided that.
 *
 * 🔴 WHAT THIS LAYER CAN AND CANNOT PROVE. Playwright is one user, and the
 * interesting half of a layered preference is another party's: a GROUP default
 * set by somebody who administers the group, seen by a member who did not set
 * it. A browser signed in as admin can write a group default and then read it
 * back, which proves the layer is reported, and that is what is driven here.
 * What it cannot prove is the refusal: that an ordinary member may NOT set
 * their team's default. A superuser success proves almost nothing about a
 * permission, so that claim is left to openregister's own
 * NotificationGroupPreferencesController tests, which can name a principal that
 * should be refused. It is not asserted here and it is not implied.
 *
 * WHAT IS DRIVEN HERE: the personal settings page lists dossiq's notifications,
 * each one names the layer that decided it, switching one records it as the
 * reader's own and says so, handing it back reports the layer below again, and
 * the daily digest switch reports its layer beside it like every other.
 *
 * THE ENTRIES ARE ADDRESSED BY THEIR TEST ID, NEVER BY POSITION OR COUNT. The
 * platform answers for every app on the instance and dossiq filters to its own,
 * so which rows exist depends on what else is installed. "The list has N rows"
 * is never asserted; "the list holds this notification, and it says who decided
 * it" is.
 *
 * THE PREFERENCE IS REAL STATE ON A SHARED INSTANCE. Every test that writes one
 * clears it again, because a preference left switched off is a notice a later
 * run never receives and cannot explain.
 */

import { expect, test } from '@playwright/test'

const PERSONAL_SETTINGS = '/settings/user/dossiq'
const DIGEST = 'workDigest-workDigestReady'
const CASE_ASSIGNED = 'case-caseAssigned'

test.describe('notifications routed by role and domain', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(PERSONAL_SETTINGS)
	})

	test('every notification names the layer that decided it', async ({ page }) => {
		const layer = page.getByTestId(`notification-routing-layer-${CASE_ASSIGNED}`)

		await expect(layer).toBeVisible()
		await expect(
			layer,
			'a value with no layer beside it is the switch people stop trusting',
		).not.toHaveText('')
	})

	test('switching a notification records it as the reader own value', async ({ page }) => {
		const toggle = page.getByTestId(`notification-routing-switch-${CASE_ASSIGNED}`)
		const layer = page.getByTestId(`notification-routing-layer-${CASE_ASSIGNED}`)

		await toggle.click()
		await expect(layer).toContainText('You set this')

		// Hand it back, so the instance is left as it was found.
		await page.getByTestId(`notification-routing-clear-${CASE_ASSIGNED}`).click()
		await expect(layer).not.toContainText('You set this')
	})

	test('handing a notification back reports the layer below again', async ({ page }) => {
		const toggle = page.getByTestId(`notification-routing-switch-${CASE_ASSIGNED}`)
		const layer = page.getByTestId(`notification-routing-layer-${CASE_ASSIGNED}`)

		await toggle.click()
		await expect(layer).toContainText('You set this')

		await page.getByTestId(`notification-routing-clear-${CASE_ASSIGNED}`).click()
		await expect(
			page.getByTestId(`notification-routing-clear-${CASE_ASSIGNED}`),
			'once the reader has no value of their own, there is nothing to hand back',
		).toBeHidden()
	})

	test('the settings can be shown as they apply to one domain', async ({ page }) => {
		await page.getByTestId('notification-routing-scope').click()
		await page.getByRole('option', { name: 'Cases' }).click()

		await expect(
			page.getByTestId(`notification-routing-layer-${CASE_ASSIGNED}`),
			'a case notification is in the cases domain, so it is still listed there',
		).toBeVisible()
	})

	test('the daily digest names its deciding layer too', async ({ page }) => {
		await expect(page.getByTestId('work-digest-enabled')).toBeVisible()
		await expect(
			page.getByTestId('work-digest-layer'),
			'the digest switch moved onto a notification preference, so it reports a layer like the rest',
		).not.toHaveText('')
	})

	test('the digest switch and its routed entry agree', async ({ page }) => {
		const digestEntry = page.getByTestId(`notification-routing-switch-${DIGEST}`)
		await expect(digestEntry).toBeVisible()

		const routed = await digestEntry.isChecked()
		const own = await page.getByTestId('work-digest-enabled').isChecked()

		expect(
			own,
			'one switch stored in two places that can disagree is the drift this change removes',
		).toBe(routed)
	})
})
