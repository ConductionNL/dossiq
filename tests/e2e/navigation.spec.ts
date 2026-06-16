import { test, expect, Page } from '@playwright/test'

const sidebarNav = (page: Page) => page.locator('[id^="app-navigation"]').first()

test.describe('Sidebar Navigation', () => {

	test('shows all navigation items', async ({ page }) => {
		await page.goto('/index.php/apps/procest')
		const nav = sidebarNav(page)

		// Main route entries that render as visible app-navigation links.
		// ("Documentation" is a `section: "footer"` external link in the
		// manifest — it lives in a collapsed footer area and is not a visible
		// top-level nav entry, so it is asserted separately below.)
		// Per procest-nav-dedup-and-grouping the operational core is grouped:
		// "My Work" now lives under the "Work" group and the "Cases" leaf was
		// relabelled to "All cases" (under the "Cases" group) to drop the
		// duplicate "Cases" label. The standalone "Tasks" top-level entry was
		// dropped by that same dedup pass — Tasks is now reached from a case's
		// Tasks sidebar tab (and the /tasks page route stays deep-linkable,
		// covered by pages.spec.ts), so it is no longer a top-level nav link.
		// All routes are unchanged.
		for (const label of ['Dashboard', 'My Work', 'All cases']) {
			await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible()
		}

		// The Documentation footer link is present in the navigation DOM.
		await expect(nav.getByText('Documentation', { exact: true })).toHaveCount(1)
	})

	test('sidebar links point to correct URLs', async ({ page }) => {
		await page.goto('/index.php/apps/procest')
		const nav = sidebarNav(page)

		const expected: Record<string, string> = {
			'My Work': '/apps/procest/my-work',
			// "Cases" leaf relabelled to "All cases" (route/href unchanged).
			'All cases': '/apps/procest/cases',
			// "Tasks" is no longer a top-level nav entry (dropped by the
			// nav-dedup pass); its /tasks page route stays deep-linkable.
		}

		for (const [name, href] of Object.entries(expected)) {
			await expect(nav.getByRole('link', { name, exact: true })).toHaveAttribute('href', href)
		}
	})

	test('settings button is visible', async ({ page }) => {
		await page.goto('/index.php/apps/procest')
		// Settings button at the bottom of the app sidebar. Target the
		// dedicated settings toggle by its testid so it doesn't collide with
		// the "SettingsMenu" nav entry (both render the text "Settings").
		await expect(page.getByTestId('cn-nav-settings').getByRole('button', { name: 'Settings' })).toBeVisible()
	})

	test('clicking nav item navigates', async ({ page }) => {
		await page.goto('/index.php/apps/procest')

		// A "Support Procest" dialog can auto-open over the app and intercept
		// pointer events on the navigation. Dismiss it if present.
		const supportDialog = page.locator('[data-testid-modal="cn-support-dialog"]')
		if (await supportDialog.isVisible().catch(() => false)) {
			await supportDialog.getByRole('button', { name: 'Close' }).click()
			await expect(supportDialog).toBeHidden()
		}

		const nav = sidebarNav(page)
		// "Cases" leaf relabelled to "All cases" (route unchanged).
		await nav.getByRole('link', { name: 'All cases', exact: true }).click()
		await expect(page).toHaveURL(/.*cases/)
	})
})
