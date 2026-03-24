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

	test('new case form has correct sections', async ({ page }) => {
		await page.goto('/apps/procest/cases/new')
		await expect(page.getByRole('heading', { name: 'Case', level: 2 })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('heading', { name: 'Status', level: 3 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Case Information', level: 3 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Participants', level: 3 })).toBeVisible()
		await expect(page.getByRole('heading', { name: /Tasks/, level: 3 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Activity', level: 3 }).first()).toBeVisible()
	})

	test('new case form has correct fields', async ({ page }) => {
		await page.goto('/apps/procest/cases/new')
		await expect(page.getByText('Title')).toBeVisible({ timeout: 10000 })
		await expect(page.getByText('Description')).toBeVisible()
		await expect(page.getByText('Case type')).toBeVisible()
		await expect(page.getByText('Priority')).toBeVisible()
		await expect(page.getByText('Handler')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Save' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Delete' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Add Participant' })).toBeVisible()
	})

	test('activity section has note input', async ({ page }) => {
		await page.goto('/apps/procest/cases/new')
		await expect(page.getByRole('textbox', { name: 'Add a note...' })).toBeVisible({ timeout: 10000 })
		await expect(page.getByRole('button', { name: 'Add note' })).toBeDisabled()
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
