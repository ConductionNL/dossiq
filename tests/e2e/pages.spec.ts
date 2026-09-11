import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupFlowTasks,
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
} from './helpers/fixtures.ts'
import {
	dismissSupportDialog,
	loadAllAdminSections,
	navTo,
	navToRoute,
} from './helpers/nav.ts'

test.describe('Dashboard', () => {
	// UNPARKED. The old FIXME(#427) said CnDashboardPage renders its widget grid
	// but not its header under the bare `php -S` CI environment. That is no
	// longer true and has not been for some time: `case-create-form.spec.ts`
	// opens the New case dialog by clicking the dashboard's own header action on
	// every CI run, and passes. So the header renders, and the only thing this
	// test was still right about was two of its three buttons.
	//
	// WHAT THIS ASSERTS AND WHAT IT DROPPED. `New case` is a declared header
	// action on the Dashboard page in src/manifest.json, so it is the button a
	// reader can hold the page to. `New Task` and `Refresh dashboard` were
	// never built — they appear nowhere in src/ and nowhere in the manifest —
	// so asserting them made the test unfixable by anything short of building
	// two features nobody asked for. They are gone rather than skipped: an
	// assertion on a control that was never specified is not pending work.
	test('shows heading and action buttons', async ({ page }) => {
		// Land on a route that resolves, then navigate to the dashboard via the
		// sidebar (client-side). A direct GET of the bare app root leaves
		// vue-router's history-mode location empty so the '/' route never
		// resolves and the dashboard renders an empty router-view.
		await page.goto('/index.php/apps/dossiq/cases', { timeout: 60_000 })
		await dismissSupportDialog(page)
		await page
			.locator('[id^="app-navigation"]')
			.first()
			.getByRole('link', { name: 'Dashboard' })
			.click()
		await expect(
			page.getByRole('heading', { name: 'Dashboard', level: 2 }),
		).toBeVisible({ timeout: 30_000 })
		// The page's own description, from the manifest. Asserted alongside the
		// heading so a bare `<h2>Dashboard</h2>` rendered by some other chrome
		// could not satisfy this on its own.
		await expect(
			page.getByText('Cases, deadlines and your workload at a glance'),
		).toBeVisible({ timeout: 30_000 })
		// The single declared header action. `exact` matters: the widget grid
		// below carries case rows whose text also contains "case".
		await expect(
			page.getByRole('button', { name: 'New case', exact: true }),
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/specs/dashboard/spec.md#kpi-tiles-render-on-a-fresh-load
	test('the KPI tiles render numbers on a fresh load, not the widget fallback', async ({
		page,
	}) => {
		// A HARD load, deliberately: the catalog that renders `stat` tiles
		// used to be registered only by the lazy detail-page chunk, so the
		// dashboard was fine after visiting a case and broken as the first
		// page of a session. Client-side navigation cannot tell the two apart.
		await page.goto('/index.php/apps/dossiq/')
		await dismissSupportDialog(page)
		const tiles = page.locator('.cn-stat-widget')
		await expect(tiles.first()).toBeVisible({ timeout: 30_000 })
		await expect(tiles).toHaveCount(5)
		await expect(page.getByText('Widget not available')).toHaveCount(0)
		// Each tile carries a resolved number, not a dash or an empty value.
		for (const tile of await tiles.all()) {
			await expect(tile.locator('.cn-kpi-card__value')).toHaveText(/\d/, {
				timeout: 15_000,
			})
		}
	})
})

test.describe('Cases page', () => {
	// @e2e openspec/specs/case-management/spec.md#cases-index-page-renders-list-shell
	test('renders list view with correct controls', async ({ page }) => {
		await navTo(page, /^(All cases|Alle zaken)$/)
		// The view switcher renders as BUTTONS, not a radio group — measured on
		// a CI runner (2026-08-04): the page exposes zero `radio` roles, so the
		// old `getByRole('radio', …)` assertions could never pass.
		await expect(page.getByRole('button', { name: 'Cards' })).toBeVisible({
			timeout: 15000,
		})
		await expect(page.getByRole('button', { name: 'Table' })).toBeVisible()
		await expect(
			page.getByRole('button', { name: /^Add (Item|Case|Task)$/ }),
		).toBeVisible()
		await expect(
			page.getByRole('button', { name: 'Actions' }).first(),
		).toBeVisible()
	})

	// UNPARKED, AND REPOINTED AT THE DIALOG THAT ACTUALLY SHIPS.
	//
	// The old body asserted `.case-create-dialog`, a heading "New Case", a
	// "Create case" button and a "Set location" button. `CaseCreateDialog`
	// does not exist: the string appears nowhere under src/, and neither does
	// "Set location". So the FIXME's diagnosis ("the generic CnFormDialog
	// opens instead of dossiq's own") described the *design*, not a defect —
	// the plain CnFormDialog IS what this app ships, and
	// `friendly-case-create-form` is the spec that says so.
	//
	// WHAT THIS ADDS OVER case-create-form.spec.ts. That spec opens the dialog
	// from the DASHBOARD's header action. This one opens it from the Cases
	// index's own Add control, which is the other entry point and the one this
	// test was always about. If the index's create path ever stops resolving
	// the `case` schema — the actual failure #427 described — this reddens and
	// the dashboard spec does not.
	test('new case modal has correct fields', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq/cases', { timeout: 60_000 })
		await dismissSupportDialog(page)
		// CnIndexPage labels the create button "Add <SchemaTitle>" when the
		// schema title resolves, "Add Item" otherwise — match either.
		await page
			.getByRole('button', { name: /^Add (Item|Case|Task)$/ })
			.click({ timeout: 30_000 })
		// The dialog ROOT, not the phase div carrying the testid: NcDialog puts
		// its buttons in a footer slot beside that div, so a locator scoped to
		// the testid finds the fields but never Create or Cancel.
		const modal = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(modal).toBeVisible({ timeout: 30_000 })
		// The `case` schema's fields resolved. `data-cn-field` is the form's
		// own per-field hook, so this fails on the "empty form body" #427
		// described rather than passing on an open-but-blank dialog.
		for (const key of ['title', 'caseType', 'description']) {
			await expect(
				modal.locator(`[data-cn-field="${key}"]`),
				`the case schema's ${key} field must resolve in the index create dialog`,
			).toBeVisible({ timeout: 30_000 })
		}
		await expect(modal.getByRole('button', { name: 'Create' })).toBeVisible()
		await expect(modal.getByRole('button', { name: 'Cancel' })).toBeVisible()
	})

	test('sidebar has search and filter controls', async ({ page }) => {
		await navTo(page, /^(All cases|Alle zaken)$/)
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
	/**
	 * The Case column test used to read whatever the instance happened to
	 * hold, and on this run it held nothing: the Tasks index answered "No
	 * items found" and the spec reported a missing table. Nothing seeds tasks
	 * for the whole instance — `ci-seed.sh` provisions the register and its
	 * schemas, not rows — so every task on the list belongs to some other
	 * spec, is torn down by that spec's `afterAll`, and may or may not exist
	 * by the time this file runs on its own worker. A test that needs a task
	 * linked to a case has to own one.
	 */
	let api: APIRequestContext
	let token = ''
	let taskCaseId = ''
	let taskCaseTitle = ''
	let taskTitle = ''

	/**
	 * A due date far enough out that the inbox's `-dueAt` sort keeps this
	 * file's own task on page one.
	 *
	 * ⚠️ IT IS TOLERANCE, NOT A GUARANTEE. The engine orders by `due_at`
	 * alone and the page is 25 rows, so where an UNDATED task sorts is the
	 * datastore's business: sqlite and MySQL put nulls last on a DESC,
	 * Postgres puts them first. An instance holding more than a page of
	 * undated tasks is the one case this does not survive, and it fails as
	 * "the seeded row is not in the table" rather than silently passing.
	 */
	const FAR_FUTURE_DUE = '2099-12-31T09:00:00+00:00'

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		// The signed-in storage state, explicitly. `playwright.request` is the
		// raw API and inherits nothing from `use`, so a context built without
		// it carries no session and every seed below answers 401.
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		const caseType = await ensureCaseType(api, token)
		taskCaseTitle = `${RUN_PREFIX} Tasks page case`
		taskCaseId = objectId(
			await seedCase(api, token, {
				title: taskCaseTitle,
				caseType: caseType.id,
			}),
		)
		// IN THE ENGINE. The Tasks index is `entitySource: "tasks"` since
		// dossiq#2408, so a `caseTask` object is a row this page cannot see.
		//
		// A far-future `dueAt` is not decoration: the inbox sorts `-dueAt`
		// and pages at 25, and the page offers no way to narrow to one case
		// (a named source takes its config from the manifest and the active
		// tab, never from the URL query — CnPageRenderer builds `sourceConfig`
		// from the manifest config before route params are merged). Dating
		// the fixture past everything else is what keeps it on page one
		// without asserting a position.
		taskTitle = `${RUN_PREFIX} Tasks page task`
		await seedFlowTask(api, token, {
			title: taskTitle,
			objectUuid: taskCaseId,
			state: 'available',
			dueAt: FAR_FUTURE_DUE,
		})
	})

	test.afterAll(async () => {
		if (api === undefined) return
		// A flow task is not an OpenRegister object, so it is cancelled
		// through the engine's own verb rather than swept by prefix.
		await cleanupFlowTasks(api, token)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/task-management/spec.md#view-the-global-task-list
	test('renders list view with search and filters, and offers no Add', async ({
		page,
	}) => {
		// "Tasks" is no longer a top-level sidebar leaf (dropped by the
		// nav-dedup pass); the /tasks page route stays reachable, so navigate
		// to it client-side rather than via a (non-existent) nav link.
		await navToRoute(page, '/tasks')
		// View switcher renders as buttons, not radios — see the Cases test.
		await expect(page.getByRole('button', { name: 'Table' })).toBeVisible({
			timeout: 15000,
		})
		await expect(page.getByRole('button', { name: 'Cards' })).toBeVisible()
		// 🔴 NO ADD BUTTON, AND THAT IS THE CLAIM. The tasks source sets
		// `showAdd: false` because a task is raised by a flow, never by a
		// person: an Add here would open the index's schema form and build an
		// object the engine's inbox does not read. `toHaveCount(0)` rather
		// than `not.toBeVisible()` on purpose — nc-vue renders plenty of
		// zero-size chrome that is still in the accessibility tree, so
		// "invisible" would pass against a button that is merely collapsed.
		await expect(
			page.getByRole('button', { name: /^Add (Item|Case|Task)$/ }),
		).toHaveCount(0)
		await expect(
			page.getByRole('button', { name: 'Actions' }).first(),
		).toBeVisible()
		// CnIndexSidebar's search field — placeholder is "Type to search..."
		// (lib default). The index sidebar starts COLLAPSED on this route, so
		// the field is in the DOM but hidden; assert it is wired up rather
		// than requiring the sidebar to be open.
		await expect(page.getByPlaceholder('Type to search')).toBeAttached()
	})

	// @e2e openspec/specs/task-management/spec.md#view-the-global-task-list
	test('the Subject column shows the case title, not its uuid', async ({
		page,
	}) => {
		// 🔴 THE HEADER IS `Subject`, NOT `Case`. The tasks source supplies
		// its own six columns (Task, Subject, State, Priority, Due,
		// Assignee) and the subject cell reads the engine's resolved
		// `subject.title` for the object the task hangs off — which for
		// dossiq is the case, because OpenRegister has no case entity.
		//
		// THE URL NO LONGER NARROWS THE LIST. `?case=<id>` was a field filter
		// on a self-fetching CnIndexPage; a named source takes its config
		// from the manifest and the active tab and nothing else, so the query
		// string reaches no filter at all. The fixture is dated far into the
		// future instead, which puts it at the top of the `-dueAt` sort — the
		// row is still found BY ITS TITLE, never by position.
		await page.goto(`/apps/${REGISTER}/tasks`)
		await dismissSupportDialog(page)

		// The list has to have ANSWERED before the view is switched. The view
		// switcher paints with the page shell, so the click lands whether or
		// not any rows exist, and CnDataTable renders its `<table>` only once
		// it has rows — so switching too early leaves the page in Table view
		// with nothing to show, and the assertion below reads as "this page
		// has no table" rather than "the list had not loaded yet".
		await expect(
			page.locator('[data-testid="cn-object-row"]').first(),
		).toBeVisible({ timeout: 30_000 })

		await page.getByRole('button', { name: 'Table' }).click()
		const table = page.locator('table').first()
		await expect(table).toBeVisible({ timeout: 30_000 })
		const header = table.getByRole('columnheader', {
			name: /^(Subject|Onderwerp)$/,
		})
		await expect(header).toBeVisible()
		const index = await header.evaluate((th) =>
			Array.from(th.parentElement!.children).indexOf(th),
		)
		const cells = table.locator(`tbody tr td:nth-child(${index + 1})`)
		await expect(cells.first()).toBeVisible({ timeout: 15000 })
		// A uuid, truncated or not, is what the column used to show. The
		// resolved subject carries a title; a task without one shows nothing.
		for (const text of await cells.allInnerTexts()) {
			expect(text.trim()).not.toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}/i)
		}

		// And the cell of THIS spec's own task carries its case's TITLE. The
		// row is found by the task title rather than taken as the first one,
		// so the claim is about the seeded row and no other.
		//
		// "Not empty" is not enough on its own: a column rendering any
		// placeholder satisfies the loop above, and what the requirement asks
		// is that a reader sees which case the task belongs to.
		const seededRow = table.locator('tbody tr').filter({ hasText: taskTitle })
		await expect(seededRow).toHaveCount(1, { timeout: 20_000 })
		await expect(seededRow.locator(`td:nth-child(${index + 1})`)).toHaveText(
			taskCaseTitle,
			{ timeout: 20_000 },
		)
	})
})

test.describe('My Work page', () => {
	// @e2e openspec/specs/my-work/spec.md#scenario-card-and-table-view
	test('renders as a card index scoped to the current user', async ({ page }) => {
		// The sidebar label is "My work" (lower-case w) — "My Work" matched no
		// nav link and used to burn the whole test budget inside navTo.
		await navTo(page, /^(Assigned to me|Aan mij toegewezen)$/)
		// My Work is a CnIndexPage card list (assignee = current uid). It
		// renders NO page heading — measured on a CI runner (2026-08-04) the
		// route exposes zero `heading` roles — so identify it by the sort
		// controls that are unique to this view plus its card/table toggle.
		await expect(page.getByRole('button', { name: 'Urgency' })).toBeVisible({
			timeout: 15000,
		})
		await expect(page.getByRole('button', { name: 'Newest' })).toBeVisible()
		await expect(
			page.getByRole('button', { name: /Cards/ }).first(),
		).toBeVisible()
	})
})

test.describe('Doorlooptijd page', () => {
	// @e2e openspec/specs/doorlooptijd-dashboard/spec.md#doorlooptijd-page-renders-heading
	test('renders processing time analytics', async ({ page }) => {
		// Use the /index.php-prefixed deep link (navToRoute). The comment this
		// replaces claimed a /index.php deep-link resets to the Dashboard;
		// measured on a CI runner (2026-08-04) it renders the view correctly.
		await navToRoute(page, '/doorlooptijd')
		await expect(
			page.getByRole('heading', {
				// page-topology-cleanup (A3): the heading is the dashboard
				// page's title now. The old wording lives on as the subtitle,
				// asserted separately below where this spec checks it.
				name: 'Processing time',
				level: 2,
			}),
		).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('SLA adherence')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Dashboard' })).toBeVisible()
	})
})

test.describe('Settings page', () => {
	// @e2e openspec/specs/admin-settings/spec.md#in-app-settings-page-renders-configuration-sections
	// NOTE ON THE URL: these used the un-prefixed `/apps/dossiq/settings`.
	// Measured on a CI runner (2026-08-04), a deep link WITHOUT the
	// `/index.php` prefix does not render the target view — the same URL with
	// the prefix does. (Several comments in this suite asserted the opposite.)
	// NOTE ON THE SECTIONS: `AdminRoot.vue` mounts its sections lazily as the
	// viewport approaches them, so scroll them all in before asserting.
	test('renders the configuration section and its save control', async ({
		page,
	}) => {
		// page-topology-cleanup (B1) retired the IN-APP /settings page: it
		// mounted the same AdminRoot.vue as /settings/admin/dossiq, and
		// reaching an administration component through the in-app router
		// bypasses the settings framework's server-side checks (ADR-004).
		// Retargeted at the surface that owns administration, which is also
		// where the FIXME below said these components actually render.
		await page.goto('/index.php/settings/admin/dossiq')
		await dismissSupportDialog(page)
		await loadAllAdminSections(page)
		// "Version Information" and a "Re-import configuration" button were
		// asserted here but exist nowhere in src/ — that surface was removed.
		// `Settings.vue` renders a CnSettingsSection named "Configuration"
		// with a primary "Save" action, which is the current contract.
		// `exact` matters here. The retired in-app page rendered only section
		// chrome, so a loose "Save" matched exactly one button. The real admin
		// surface renders every section, and four of them have their own
		// labelled save ("Save mandate matrix settings", "Save consultation
		// settings", …). The Configuration section's control is the bare one.
		await expect(
			page.getByRole('button', { name: 'Save', exact: true }),
		).toBeVisible({ timeout: 15000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	// FIXME(#719) RESOLVED BY RETIREMENT. The in-app settings page rendered
	// only its section chrome — `.settings-form` count 0, no scrollable
	// container — because the type:"settings" page's `section-admin` slot never
	// rendered its body. That page is gone (page-topology-cleanup B1) and the
	// scenario is spec'd against administration, not against that route, so it
	// is retargeted at /settings/admin/dossiq where the components do render.
	test('has schema configuration fields', async ({ page }) => {
		await page.goto('/index.php/settings/admin/dossiq')
		await dismissSupportDialog(page)
		await loadAllAdminSections(page)
		// Scope to the configuration form — "Register" otherwise also matches
		// section descriptions ("Register and schema settings", etc.). Each
		// field renders its own <label> plus the NcTextField's label, so take
		// the first exact match per name.
		const form = page.locator('.settings-form')
		await expect(
			form.getByText('Register', { exact: true }).first(),
		).toBeVisible({ timeout: 15000 })
		await expect(
			form.getByText('Case schema', { exact: true }).first(),
		).toBeVisible()
		await expect(
			form.getByText('Status schema', { exact: true }).first(),
		).toBeVisible()
		// 🔴 NO TASK SCHEMA FIELD, AND ITS ABSENCE IS THE ASSERTION.
		// remove-casetask deleted the schema, so a field here would offer a
		// picker for something the register no longer declares, and an admin
		// who filled it in would configure nothing. Tasks live in OpenRegister's
		// task engine, which needs no schema id. Asserted AFTER the fields that
		// must render, so a form that failed to load cannot pass this by
		// showing nothing at all.
		await expect(form.getByText('Task schema', { exact: true })).toHaveCount(0)
	})

	// UNPARKED, WITH THE DIAGNOSIS CORRECTED.
	//
	// The old reason said "Case Type Management IS present in AdminRoot.vue …
	// most likely a navigation or selector problem". The component half was
	// right and the navigation half was the whole story: `AdminRoot.vue` is
	// mounted by Nextcloud's settings framework at `/settings/admin/dossiq`,
	// and `/apps/dossiq/settings` is not a route this app declares at all —
	// src/manifest.json has `/settings/case-types`, `/settings/integrations`
	// and friends, but no bare `/settings`. So the old body navigated to a
	// fall-through and then blamed the heading for not being there.
	//
	// The in-app surface for administering case types is the `CaseTypes` index
	// at `/settings/case-types`, reached from the app's own Settings group.
	// That is what this test now drives — the NC admin page's copy of the
	// section is already covered by spec-coverage/admin-settings.spec.ts, and
	// re-asserting it here would only duplicate it.
	test('has case type management section', async ({ page }) => {
		await navToRoute(page, '/settings/case-types')
		await dismissSupportDialog(page)
		await expect(
			page.getByRole('heading', { name: /^Case types$/i }),
		).toBeVisible({ timeout: 30_000 })
		// The management affordance, not just the title: a page that lists case
		// types but offers no way to add one is not a management section.
		await expect(
			page.getByRole('button', { name: /^Add (Item|Case ?type)$/i }).first(),
		).toBeVisible({ timeout: 30_000 })
	})
})
