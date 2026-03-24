import { test, expect, Page } from '@playwright/test'

const sidebarNav = (page: Page) => page.locator('[id^="app-navigation"]').first()

test.describe('Sidebar Navigation', () => {

	test('shows all navigation items', async ({ page }) => {
		await page.goto('/apps/procest')
		const nav = sidebarNav(page)

		for (const label of ['Dashboard', 'My Work', 'Cases', 'Tasks', 'Documentation']) {
			await expect(nav.getByText(label)).toBeVisible()
		}
	})

	test('sidebar links point to correct URLs', async ({ page }) => {
		await page.goto('/apps/procest')
		const nav = sidebarNav(page)

		const expected: Record<string, string> = {
			'My Work': '/apps/procest/my-work',
			Cases: '/apps/procest/cases',
			Tasks: '/apps/procest/tasks',
		}

		for (const [name, href] of Object.entries(expected)) {
			await expect(nav.getByRole('link', { name })).toHaveAttribute('href', href)
		}
	})

	test('settings button is visible', async ({ page }) => {
		await page.goto('/apps/procest')
		// Settings button at bottom of sidebar
		await expect(page.getByText('Settings')).toBeVisible()
	})

	test('clicking nav item navigates', async ({ page }) => {
		await page.goto('/apps/procest')
		const nav = sidebarNav(page)
		await nav.getByRole('link', { name: 'Cases' }).click()
		await expect(page).toHaveURL(/.*cases/)
	})
})
