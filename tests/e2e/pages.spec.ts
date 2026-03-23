import { test, expect } from '@playwright/test'

test.describe('Dashboard', () => {

	test('shows heading and action buttons', async ({ page }) => {
		await page.goto('/apps/procest')
		await expect(page.getByRole('heading', { name: 'Dashboard', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'New Case' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'New Task' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Refresh dashboard' })).toBeVisible()
	})
})

test.describe('Cases page', () => {

	test('renders list view with correct controls', async ({ page }) => {
		await page.goto('/apps/procest/cases')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked()
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' })).toBeVisible()
	})
})

test.describe('Tasks page', () => {

	test('renders list view with correct controls', async ({ page }) => {
		await page.goto('/apps/procest/tasks')
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Add Item' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' })).toBeVisible()
	})
})

test.describe('My Work page', () => {

	test('renders with correct filter controls', async ({ page }) => {
		await page.goto('/apps/procest/my-work')
		await expect(page.getByRole('heading', { name: 'My Work', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: /All/ })).toBeVisible()
		await expect(page.getByRole('button', { name: /Cases/ })).toBeVisible()
		await expect(page.getByRole('button', { name: /Tasks/ })).toBeVisible()
		await expect(page.getByRole('checkbox', { name: 'Show completed' })).toBeVisible()
	})
})
