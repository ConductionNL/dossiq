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
		// Labels and visibility measured on a CI runner (2026-08-04). The
		// manifest ships "My work" (lower-case w), NOT "My Work", and a flat
		// "Cases" leaf — the "All cases" label this spec used to assert was
		// removed when the "Cases" GROUP IA was reverted, so it matched zero
		// links and the assertion could only ever fail.
		//
		// Only "Dashboard" and "Cases" are top-level VISIBLE leaves. The rest
		// render inside collapsed groups ("Work queue", "Reports", "Personal
		// settings"), so they are present in the DOM but `display:none` until
		// their group is expanded — asserted separately below.
		for (const label of ['Dashboard', 'Cases']) {
			await expect(nav.getByRole('link', { name: label, exact: true })).toBeVisible()
		}

		// Collapsed-group leaves: present in the navigation, hidden until the
		// group is expanded. `toHaveCount(1)` asserts presence without
		// requiring visibility.
		for (const label of ['My work', 'Workflow board', 'Processing time']) {
			await expect(nav.getByRole('link', { name: label, exact: true })).toHaveCount(1)
		}

		// And the group headers themselves are visible toggles.
		for (const group of ['Work queue', 'Reports']) {
			await expect(nav.getByRole('link', { name: group, exact: true })).toBeVisible()
		}

		// The Documentation footer link is present in the navigation DOM.
		await expect(nav.getByText('Documentation', { exact: true })).toHaveCount(1)
	})

	test('sidebar links point to correct URLs', async ({ page }) => {
		await page.goto('/index.php/apps/procest')
		const nav = sidebarNav(page)

		// Hrefs measured on a CI runner (2026-08-04). Note the rendered hrefs
		// carry the `/index.php` prefix — the bare `/apps/procest/...` values
		// this spec used to assert never matched what the nav actually emits.
		// "Tasks" is not a top-level nav entry; its /tasks route stays
		// deep-linkable and is covered by pages.spec.ts.
		const expected: Record<string, string> = {
			'My work': '/index.php/apps/procest/my-work',
			Cases: '/index.php/apps/procest/cases',
			'Workflow board': '/index.php/apps/procest/workflow-board',
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
		// "Cases" is a flat, visible, directly-navigating leaf — the "All
		// cases" label this used to click does not exist in the rendered nav.
		await nav.getByRole('link', { name: 'Cases', exact: true }).click()
		await expect(page).toHaveURL(/.*cases/)
	})
})
