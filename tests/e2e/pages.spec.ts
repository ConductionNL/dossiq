import { test, expect } from '@playwright/test'

test.describe('Dashboard', () => {

	test('shows heading and action buttons', async ({ page }) => {
		await page.goto('/index.php/apps/procest')
		await expect(page.getByRole('heading', { name: 'Dashboard', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'New Case' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'New Task' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Refresh dashboard' })).toBeVisible()
	})
})

test.describe('Cases page', () => {

	test('renders list view with correct controls', async ({ page }) => {
		await page.goto('/index.php/apps/procest/cases')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked()
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
	})

	test('new case modal has correct fields', async ({ page }) => {
		await page.goto('/index.php/apps/procest/cases')
		await page.getByRole('button', { name: 'Add Item' }).click()
		await expect(page.getByRole('heading', { name: 'New Case' })).toBeVisible({ timeout: 5000 })
		await expect(page.getByRole('combobox', { name: /case type/i })).toBeVisible()
		await expect(page.getByPlaceholder('Enter case title')).toBeVisible()
		await expect(page.getByPlaceholder('Optional description')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Set location' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Create case' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Cancel' })).toBeVisible()
	})

	test('sidebar has search and filter controls', async ({ page }) => {
		await page.goto('/index.php/apps/procest/cases')
		await page.getByRole('button', { name: 'Add Item' }).click()
		await page.getByRole('button', { name: 'Cancel' }).click()
		// Sidebar should have filter comboboxes
		const sidebar = page.locator('[role="complementary"], .app-sidebar')
		if (await sidebar.isVisible()) {
			await expect(page.getByPlaceholder('Type to search')).toBeVisible()
		}
	})
})

test.describe('Tasks page', () => {

	test('renders list view with search and filters', async ({ page }) => {
		await page.goto('/index.php/apps/procest/tasks')
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
		// CnIndexSidebar's search field — placeholder is "Type to search..." (lib default).
		await expect(page.getByPlaceholder('Type to search')).toBeVisible()
	})
})

test.describe('My Work page', () => {

	test('renders with correct filter controls', async ({ page }) => {
		await page.goto('/index.php/apps/procest/my-work')
		await expect(page.getByRole('heading', { name: 'My Work', level: 2 })).toBeVisible({ timeout: 10000 })
		// Filter tabs are <button role="tab">All (n)</button> — not plain buttons.
		await expect(page.getByRole('tab', { name: /All/ })).toBeVisible()
		await expect(page.getByRole('tab', { name: /Cases/ })).toBeVisible()
		await expect(page.getByRole('tab', { name: /Tasks/ })).toBeVisible()
		await expect(page.getByRole('checkbox', { name: 'Show completed' })).toBeVisible()
	})
})

test.describe('Work Queue page', () => {

	test('renders with heading and stat cards', async ({ page }) => {
		await page.goto('/index.php/apps/procest/werkvoorraad')
		await expect(page.getByRole('heading', { name: 'Work Queue', level: 2 })).toBeVisible({ timeout: 10000 })
		// Scope to the KPI strip — bare getByText('Open Cases') also matches the
		// "No open cases match the current filters" empty-state copy.
		const kpis = page.locator('.werkvoorraad__kpis')
		await expect(kpis.getByText('Open Cases', { exact: true })).toBeVisible()
		await expect(kpis.getByText('Overdue', { exact: true })).toBeVisible()
		await expect(kpis.getByText('Completed This Week', { exact: true })).toBeVisible()
		await expect(kpis.getByText('Unassigned', { exact: true })).toBeVisible()
	})

	test('has filter buttons', async ({ page }) => {
		await page.goto('/index.php/apps/procest/werkvoorraad')
		await expect(page.getByRole('button', { name: /All/ })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: /Unassigned/ })).toBeVisible()
		await expect(page.getByRole('button', { name: /Overdue/ })).toBeVisible()
	})
})

test.describe('B&W Voorstellen page', () => {

	test('renders with heading and create button', async ({ page }) => {
		await page.goto('/index.php/apps/procest/voorstellen')
		await expect(page.getByRole('heading', { name: 'B&W Voorstellen', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Nieuw voorstel' })).toBeVisible()
	})

	test('has filter tabs', async ({ page }) => {
		await page.goto('/index.php/apps/procest/voorstellen')
		await expect(page.getByRole('button', { name: /Actief/ })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: /Afgerond/ })).toBeVisible()
		await expect(page.getByRole('button', { name: /Alle/ })).toBeVisible()
	})

	test('shows Dutch empty state', async ({ page }) => {
		await page.goto('/index.php/apps/procest/voorstellen')
		await expect(page.getByText('Geen actieve voorstellen')).toBeVisible({ timeout: 10000 })
	})
})

test.describe('Doorlooptijd page', () => {

	test('renders processing time analytics', async ({ page }) => {
		await page.goto('/index.php/apps/procest/doorlooptijd')
		await expect(page.getByRole('heading', { name: 'Processing Time Analytics', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByText('SLA adherence')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Dashboard' })).toBeVisible()
	})
})

test.describe('Settings page', () => {

	test('renders version and configuration sections', async ({ page }) => {
		await page.goto('/index.php/apps/procest/settings')
		await expect(page.getByRole('heading', { name: 'Version Information' })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('heading', { name: 'Configuration' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Re-import configuration' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Save' })).toBeVisible()
	})

	test('has schema configuration fields', async ({ page }) => {
		await page.goto('/index.php/apps/procest/settings')
		// Scope to the configuration form — "Register" otherwise also matches
		// section descriptions ("Register and schema settings", etc.). Each
		// field renders its own <label> plus the NcTextField's label, so take
		// the first exact match per name.
		const form = page.locator('.settings-form')
		await expect(form.getByText('Register', { exact: true }).first()).toBeVisible({ timeout: 10000 })
		await expect(form.getByText('Case schema', { exact: true }).first()).toBeVisible()
		await expect(form.getByText('Task schema', { exact: true }).first()).toBeVisible()
		await expect(form.getByText('Status schema', { exact: true }).first()).toBeVisible()
	})

	test('has case type management section', async ({ page }) => {
		await page.goto('/index.php/apps/procest/settings')
		await expect(page.getByRole('heading', { name: 'Case Type Management' })).toBeVisible({ timeout: 10000 })
	})
})
