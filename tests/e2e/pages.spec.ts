import { test, expect } from '@playwright/test'
import { navTo, navToRoute, dismissSupportDialog } from './helpers/nav'

test.describe('Dashboard', () => {

	// FIXME(#427): under the CI env (bare `php -S`, no mod_rewrite) CnDashboardPage
	// renders its widget grid but not its header — no <h2>Dashboard</h2>, no action
	// buttons — and every widget shows "Widget not available". Renders fine in a
	// normal dev container. Re-enable once the dashboard header wires up under that env.
	test.fixme('shows heading and action buttons', async ({ page }) => {
		// Land on a route that resolves, then navigate to the dashboard via the
		// sidebar (client-side). A direct GET of the bare app root leaves
		// vue-router's history-mode location empty so the '/' route never
		// resolves and the dashboard renders an empty router-view.
		await page.goto('/index.php/apps/procest/cases')
		await page.locator('[id^="app-navigation"]').first().getByRole('link', { name: 'Dashboard' }).click()
		await expect(page.getByRole('heading', { name: 'Dashboard', level: 2 })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('button', { name: 'New Case' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'New Task' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Refresh dashboard' })).toBeVisible()
	})
})

test.describe('Cases page', () => {

	// @e2e openspec/specs/case-management/spec.md#cases-index-page-renders-list-shell
	test('renders list view with correct controls', async ({ page }) => {
		await navTo(page, 'Cases')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked()
		await expect(page.getByRole('button', { name: /^Add (Item|Case|Task)$/ })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
	})

	// FIXME(#427): under the CI env the Cases create dialog opens the generic
	// CnFormDialog ("Create Item") with an empty form body instead of procest's
	// CaseCreateDialog — the `case` schema's fields never resolve there. Renders
	// fine in a normal dev container. Re-enable once the schema config wires up.
	test.fixme('new case modal has correct fields', async ({ page }) => {
		await page.goto('/index.php/apps/procest/cases')
		// CnIndexPage labels the create button "Add <SchemaTitle>" when the
		// schema title resolves, "Add Item" otherwise — match either.
		await page.getByRole('button', { name: /^Add (Item|Case|Task)$/ }).click()
		// procest's custom CaseCreateDialog (.case-create-dialog) — scope to it
		// so e.g. the case-type combobox doesn't collide with the sidebar filter.
		const modal = page.locator('.case-create-dialog')
		await expect(modal.getByRole('heading', { name: 'New Case' })).toBeVisible({ timeout: 15000 })
		await expect(modal.getByRole('combobox')).toBeVisible()
		await expect(modal.getByPlaceholder('Enter case title')).toBeVisible()
		await expect(modal.getByPlaceholder('Optional description')).toBeVisible()
		await expect(modal.getByRole('button', { name: 'Set location' })).toBeVisible()
		await expect(modal.getByRole('button', { name: 'Create case' })).toBeVisible()
		await expect(modal.getByRole('button', { name: 'Cancel' })).toBeVisible()
	})

	test('sidebar has search and filter controls', async ({ page }) => {
		await navTo(page, 'Cases')
		await page.getByRole('button', { name: /^Add (Item|Case|Task)$/ }).click()
		await page.getByRole('button', { name: 'Cancel' }).click()
		// Sidebar should have filter comboboxes
		const sidebar = page.locator('[role="complementary"], .app-sidebar')
		if (await sidebar.isVisible()) {
			await expect(page.getByPlaceholder('Type to search')).toBeVisible()
		}
	})
})

test.describe('Tasks page', () => {

	// @e2e openspec/specs/task-management/spec.md#view-the-global-task-list
	test('renders list view with search and filters', async ({ page }) => {
		// "Tasks" is no longer a top-level sidebar leaf (dropped by the
		// nav-dedup pass); the /tasks page route stays reachable, so navigate
		// to it client-side rather than via a (non-existent) nav link.
		await navToRoute(page, '/tasks')
		await expect(page.getByRole('radio', { name: 'Table' })).toBeChecked({ timeout: 15000 })
		await expect(page.getByRole('button', { name: /^Add (Item|Case|Task)$/ })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Actions' }).first()).toBeVisible()
		// CnIndexSidebar's search field — placeholder is "Type to search..." (lib default).
		await expect(page.getByPlaceholder('Type to search')).toBeVisible()
	})
})

test.describe('My Work page', () => {

	// @e2e openspec/specs/my-work/spec.md#filter-tab-layout
	test('renders with correct filter controls', async ({ page }) => {
		await navTo(page, 'My Work')
		// type:dashboard pages render the manifest title in CnDashboardPage's
		// header AND the MyWork view renders its own <h2> — two "My Work" h2s.
		// Assert the first; presence confirms the page mounted.
		await expect(page.getByRole('heading', { name: 'My Work', level: 2 }).first()).toBeVisible({ timeout: 15000 })
		// Filter tabs are <button role="tab">All (n)</button> — not plain buttons.
		await expect(page.getByRole('tab', { name: /All/ })).toBeVisible()
		await expect(page.getByRole('tab', { name: /Cases/ })).toBeVisible()
		await expect(page.getByRole('tab', { name: /Tasks/ })).toBeVisible()
		await expect(page.getByRole('checkbox', { name: 'Show completed' })).toBeVisible()
	})
})

test.describe('Work Queue page', () => {

	// @e2e openspec/specs/signalering-widgets/spec.md#work-queue-page-renders-kpi-strip-and-filters
	test('renders with heading and stat cards', async ({ page }) => {
		await navTo(page, 'Work Queue')
		// type:dashboard header + the Werkvoorraad view's own <h2> both render
		// "Work Queue" — assert the first.
		await expect(page.getByRole('heading', { name: 'Work Queue', level: 2 }).first()).toBeVisible({ timeout: 15000 })
		// Scope to the KPI strip — bare getByText('Open Cases') also matches the
		// "No open cases match the current filters" empty-state copy.
		const kpis = page.locator('.werkvoorraad__kpis')
		await expect(kpis.getByText('Open Cases', { exact: true })).toBeVisible()
		await expect(kpis.getByText('Overdue', { exact: true })).toBeVisible()
		await expect(kpis.getByText('Completed This Week', { exact: true })).toBeVisible()
		await expect(kpis.getByText('Unassigned', { exact: true })).toBeVisible()
	})

	test('has filter buttons', async ({ page }) => {
		await navTo(page, 'Work Queue')
		await expect(page.getByRole('button', { name: /All/ })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('button', { name: /Unassigned/ })).toBeVisible()
		await expect(page.getByRole('button', { name: /Overdue/ })).toBeVisible()
	})
})

test.describe('B&W Voorstellen page', () => {

	// DEPLOY-MISMATCH: the bespoke "B&W Voorstellen" view (heading "B&W
	// Voorstellen", "Nieuw voorstel" button, "Actief"/"Afgerond"/"Alle" filter
	// tabs, "Geen actieve voorstellen" Dutch empty state) is a v0.2.8 feature.
	// The build deployed to this environment is v0.2.0, whose /voorstellen route
	// renders the generic index shell instead — none of these strings exist in
	// that bundle. The non-strict shell assertion lives in
	// spec-coverage/ui-pages.spec.ts (accepts either the custom or generic
	// shell). Re-enable once a v0.2.8 build is deployed.

	// @e2e openspec/specs/case-management/spec.md#voorstellen-page-renders-heading-and-create-control
	test.fixme('renders with heading and create button', async ({ page }) => {
		await navTo(page, 'Voorstellen')
		await expect(page.getByRole('heading', { name: 'B&W Voorstellen', level: 2 })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('button', { name: 'Nieuw voorstel' })).toBeVisible()
	})

	test.fixme('has filter tabs', async ({ page }) => {
		await navTo(page, 'Voorstellen')
		await expect(page.getByRole('button', { name: /Actief/ })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('button', { name: /Afgerond/ })).toBeVisible()
		await expect(page.getByRole('button', { name: /Alle/ })).toBeVisible()
	})

	test.fixme('shows Dutch empty state', async ({ page }) => {
		await navTo(page, 'Voorstellen')
		await expect(page.getByText('Geen actieve voorstellen')).toBeVisible({ timeout: 15000 })
	})
})

test.describe('Doorlooptijd page', () => {

	// @e2e openspec/specs/doorlooptijd-dashboard/spec.md#doorlooptijd-page-renders-heading
	test('renders processing time analytics', async ({ page }) => {
		// Deep-link without the /index.php prefix — the deployed build's
		// history-mode router resets a /index.php deep-link to the Dashboard.
		await page.goto('/apps/procest/doorlooptijd')
		await dismissSupportDialog(page)
		await expect(page.getByRole('heading', { name: 'Processing Time Analytics', level: 2 })).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('SLA adherence')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Dashboard' })).toBeVisible()
	})
})

test.describe('Settings page', () => {

	// @e2e openspec/specs/admin-settings/spec.md#in-app-settings-page-renders-configuration-sections
	test('renders version and configuration sections', async ({ page }) => {
		await page.goto('/apps/procest/settings')
		await dismissSupportDialog(page)
		await expect(page.getByRole('heading', { name: 'Version Information' })).toBeVisible({ timeout: 15000 })
		await expect(page.getByRole('heading', { name: 'Configuration' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Re-import configuration' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Save' })).toBeVisible()
	})

	test('has schema configuration fields', async ({ page }) => {
		await page.goto('/apps/procest/settings')
		await dismissSupportDialog(page)
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
		await page.goto('/apps/procest/settings')
		await dismissSupportDialog(page)
		await expect(page.getByRole('heading', { name: 'Case Type Management' })).toBeVisible({ timeout: 15000 })
	})
})
