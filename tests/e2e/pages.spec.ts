import type { APIRequestContext, Locator } from '@playwright/test'

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

	// @e2e openspec/specs/dashboard/spec.md#fresh-session-lands-on-the-dashboard
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

	/** The signed-in uid, which is what the Assignee column prints. */
	const ME = process.env.ADMIN_USER ?? 'admin'

	/**
	 * The zero-based position of one column, by its header.
	 *
	 * The six columns come from the tasks SOURCE rather than from this app's
	 * manifest, so their order is the library's to change; reading the index
	 * off the header keeps a cell assertion about the column it names.
	 *
	 * @param table The rendered table.
	 * @param name  A pattern matching the header in either language.
	 */
	async function columnIndex(table: Locator, name: RegExp): Promise<number> {
		const header = table.getByRole('columnheader', { name })
		await expect(header, `the list must carry the ${name} column`).toBeVisible({
			timeout: 20_000,
		})
		return await header.evaluate((th) =>
			Array.from(th.parentElement!.children).indexOf(th),
		)
	}

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
		//
		// THE ASSIGNEE AND THE PRIORITY ARE PART OF THE FIXTURE, NOT DECORATION.
		// REQ-TASK-004 asks each row to show six facts, and a task seeded
		// without an assignee or a priority leaves two of those cells empty
		// for a reason that is the fixture's rather than the list's.
		taskTitle = `${RUN_PREFIX} Tasks page task`
		await seedFlowTask(api, token, {
			title: taskTitle,
			objectUuid: taskCaseId,
			state: 'available',
			assignee: ME,
			priority: 'high',
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
	test('renders list view with search and filters, offers no Add, and shows the facts on a row', async ({
		page,
	}) => {
		// The file's 30s default is the budget for the chrome assertions this
		// test used to hold alone. Reading the row as well means a second
		// render of the inbox in table view, which the default cannot cover:
		// the first run of the repaired body timed out INSIDE
		// `toHaveCount(1)`, which reads as "the seeded row is missing" while
		// the row was simply still arriving.
		test.setTimeout(120_000)
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

		// 🔴 AND THE ROW ITSELF, WHICH IS THE HALF THIS TEST USED TO SKIP.
		// The controls above are the page's chrome: a list that answered
		// nothing at all satisfies every one of them, and the scenario's
		// second clause — "each task row MUST show: title, parent case
		// reference, status, assignee, due date, and priority" — survived
		// untouched. So the seeded task is read out of the table, cell by
		// cell, against the facts the requirement names.
		//
		// The list has to have ANSWERED before the view is switched: the
		// switcher paints with the page shell, so the click lands whether or
		// not rows exist, and CnDataTable renders its `<table>` only once it
		// has them.
		await expect(
			page.locator('[data-testid="cn-object-row"]').first(),
		).toBeVisible({ timeout: 30_000 })

		// 🔴 THROUGH THE Mine LENS, BECAUSE THE FAR-FUTURE DUE DATE IS NOT
		// ENOUGH. The `All` lens the page opens on is every task on the
		// instance — 69 of them here, paged at 25 — and the engine's order
		// puts the UNDATED ones first, so a fixture dated 2099 sits on page
		// three and the first run of this assertion read "the seeded row is
		// missing" while the row was three pages away. `Mine` is
		// `scope=assigned&isTerminal=false`, which is this user's open work
		// and fits on one page (19 rows when measured). The columns are the
		// source's and identical under every lens, so narrowing changes which
		// rows are read, never what a row is made of.
		await page.getByRole('tab', { name: /^(Mine|Van mij)$/ }).click()
		await page.getByRole('button', { name: 'Table' }).click()
		const table = page.locator('table').first()
		await expect(table).toBeVisible({ timeout: 30_000 })

		// Found BY ITS TITLE, never by position: the inbox is a shared list
		// on a shared instance and another session's tasks sit in it.
		const seededRow = table.locator('tbody tr').filter({ hasText: taskTitle })
		await expect(seededRow).toHaveCount(1, { timeout: 30_000 })

		/**
		 * One cell of the seeded row, by its column header.
		 *
		 * @param name A pattern matching the header in either language.
		 */
		const cell = async (name: RegExp): Promise<Locator> =>
			seededRow.locator(
				`td:nth-child(${(await columnIndex(table, name)) + 1})`,
			)

		await expect(
			await cell(/^(Task|Taak)$/),
			'the row names the task',
		).toContainText(taskTitle)
		// 🔴 THE SUBJECT CELL IS THE SIBLING TEST'S CLAIM, AND IT IS DARK
		// TODAY. REQ-TASK-004's sixth fact is the parent case reference, and
		// `the Subject column shows the case title, not its uuid` below is
		// the test that asserts it. Measured on this instance 2026-09-12: the
		// inbox endpoint answers `subject: null` for EVERY row, freshly
		// seeded ones included, so the column prints an em dash throughout
		// and that sibling is red for a reason that lives in OpenRegister's
		// `TaskInboxService::subjectContexts()`, not in this page. Asserting
		// it a second time here would duplicate a known-red claim rather than
		// add coverage, so this test reads the five facts that do resolve and
		// leaves the sixth where it already lives.
		await expect(
			await cell(/^(State|Status)$/),
			'the row shows the state the task was seeded in',
		).toHaveText(/^\s*(Available|Beschikbaar)\s*$/)
		await expect(
			await cell(/^(Priority|Prioriteit)$/),
			'the row shows the priority the task was seeded with',
		).toHaveText(/^\s*(High|Hoog)\s*$/)
		// `taskDueLabel` returns '' for a task with no `dueAt`, so a cell
		// carrying a number is a due date that resolved rather than chrome.
		await expect(
			await cell(/^(Due|Deadline|Vervaldatum)$/),
			'the row counts down to the due date it was seeded with',
		).toHaveText(/\d/)
		await expect(
			await cell(/^(Assignee|Toegewezen aan)$/),
			'the row names who holds the task',
		).toContainText(ME)
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
	/**
	 * ONE CASE ASSIGNED TO THE SIGNED-IN USER, SO THE TABLE CAN EXIST.
	 *
	 * `CnDataTable` renders its `<table>` only once it has rows, so on an
	 * instance where this user holds nothing the table view is a headline and
	 * no headers, and "the table view shows these five columns" would read as
	 * a missing column rather than as an empty list. The row is never located
	 * or counted: it is there so the list has something to draw.
	 */
	let api: APIRequestContext
	let token = ''

	/** The signed-in uid, which is what My Work filters on. */
	const ME = process.env.ADMIN_USER ?? 'admin'

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		const caseType = await ensureCaseType(api, token)
		await seedCase(api, token, {
			title: `${RUN_PREFIX} My Work case`,
			caseType: caseType.id,
			assignee: ME,
		})
	})

	test.afterAll(async () => {
		if (api === undefined) return
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/my-work/spec.md#scenario-card-and-table-view
	//
	// 🔴 WHAT THE SCENARIO ASKS, AND WHAT THIS USED TO ASSERT. "The list MUST
	// default to card view and offer a card/table toggle, AND the table view
	// MUST show the columns: identifier, title, case type, status, deadline."
	// The body asserted the two sort buttons and that a Cards button was
	// visible. It never switched to Table, never read a column and never said
	// which view the page opens in, so a My Work that opened as a table with
	// three columns passed every line of it.
	//
	// The requirement above the scenario carries an `@e2e exclude` for being
	// data dependent, and that reason is about the list's CONTENTS: which
	// cases a named user holds. This scenario is about the view shell and its
	// columns, which one seeded row makes assertable without asserting
	// anything about who holds what.
	test('renders as a card index scoped to the current user', async ({ page }) => {
		test.setTimeout(120_000)
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

		// THE DEFAULT IS CARDS, AND THE TOGGLE PROVES IT RATHER THAN THE
		// ABSENCE OF A TABLE. Both view buttons carry `aria-pressed`, so the
		// pressed one IS the current view; "no table on screen" would also be
		// satisfied by a list that had not answered yet.
		const cards = page.getByRole('button', { name: /Cards/ }).first()
		const tableToggle = page.getByRole('button', { name: /Table/ }).first()
		await expect(cards, 'the card/table toggle offers cards').toBeVisible()
		await expect(tableToggle, 'and a table').toBeVisible()
		await expect(
			cards,
			'My Work opens in card view, which is what the scenario calls the default',
		).toHaveAttribute('aria-pressed', 'true')
		await expect(tableToggle).toHaveAttribute('aria-pressed', 'false')

		// AND THE FIVE COLUMNS THE SCENARIO NAMES. Switching views is the
		// other half of the toggle claim, and the headers are the half that
		// says what a reader gets when they switch.
		// `.mywork-card` is this page's own card, not the library's generic
		// row: MyWorkCards fills CnIndexPage's `#card` slot with
		// MyWorkCaseCard, so `cn-object-row` never appears in card view here.
		await expect(
			page.locator('.mywork-card').first(),
			'the list has answered, so an absent column is a decision',
		).toBeVisible({ timeout: 30_000 })
		await tableToggle.click()
		const table = page.locator('table').first()
		await expect(table).toBeVisible({ timeout: 30_000 })
		// The SORTED column carries its direction glyph inside the header, so
		// its accessible name reads "Deadline\u25b2" and an end-anchored
		// pattern misses exactly the column the page is sorted on.
		for (const column of [
			/^(Identifier|Kenmerk|Identificatie)[\u25b2\u25bc]?$/,
			/^(Title|Titel)[\u25b2\u25bc]?$/,
			/^(Case type|Zaaktype)[\u25b2\u25bc]?$/,
			/^(Status)[\u25b2\u25bc]?$/,
			/^(Deadline|Uiterlijke datum)[\u25b2\u25bc]?$/,
		]) {
			await expect(
				table.getByRole('columnheader', { name: column }),
				`the table view must carry the ${column} column`,
			).toBeVisible({ timeout: 20_000 })
		}
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
	// @e2e openspec/specs/admin-settings/spec.md#admin-settings-page-is-accessible
	//
	// 🔴 REPOINTED, BECAUSE THE SCENARIO IT USED TO CITE DESCRIBES A PAGE
	// THAT NO LONGER EXISTS. The citation read
	// `admin-settings#in-app-settings-page-renders-configuration-sections`,
	// read as partial on 2026-09-11 and as smoke on 2026-09-12. Two of that
	// scenario's clauses cannot be made true against this product, and
	// neither is the test's fault:
	//
	//   the in-app Settings page      retired by page-topology-cleanup (B1),
	//                                 because reaching an administration
	//                                 component through the in-app router
	//                                 bypasses the settings framework's
	//                                 server-side checks (ADR-004)
	//   "Version Information" heading removed; the string exists nowhere in
	//                                 src/
	//
	// That scenario therefore carries a reason-bearing `@e2e exclude` naming
	// both, written where a spec reader meets it. What this test drives is
	// the administration surface, so it cites the scenario that describes the
	// administration surface rather than carrying no citation at all.
	//
	// WHAT IT PROVES THAT NOTHING ELSE DOES. REQ-ADMIN-001's accessible
	// scenario ends "AND the page MUST render the AdminRoot.vue component
	// with case type management and ZGW API mapping sections".
	// spec-coverage/admin-settings.spec.ts asserts the Case Type Management
	// half. The ZGW API mapping half was asserted nowhere, and is asserted
	// here beside the Configuration section and its Save control.
	//
	// NOTE ON THE URL: these used the un-prefixed `/apps/dossiq/settings`.
	// Measured on a CI runner (2026-08-04), a deep link WITHOUT the
	// `/index.php` prefix does not render the target view — the same URL with
	// the prefix does. (Several comments in this suite asserted the opposite.)
	// NOTE ON THE SECTIONS: `AdminRoot.vue` mounts its sections lazily as the
	// viewport approaches them, so scroll them all in before asserting.
	test('renders the configuration section and its save control', async ({
		page,
	}) => {
		// The administration page mounts every section this app declares, a
		// dozen of them, each fetching its own configuration. On a cold
		// instance the first load runs past the file's 30s default and the
		// failure reads as `page.goto: Test timeout`, which says nothing
		// about administration at all.
		test.setTimeout(120_000)
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

		// The section headings, named individually. A count would redden on
		// ADDING a section and pass on a swap, and would never say which one
		// went missing. `ZGW API Mapping` is the one the cited scenario names
		// beside case type management, and a Save button on its own is
		// satisfied by any settings page the framework happens to mount.
		//
		// NOT `{ name, exact: true }`. A settings section that sets `doc-url`
		// renders the documentation link INSIDE its own <h2>, so the heading's
		// accessible name is the section name followed by that link's label.
		// Measured on run 34703612328, where the page rendered
		//
		//   - heading "Configuration External documentation" [level=2]:
		//     - text: Configuration
		//     - link "External documentation"
		//
		// The section, its description and its Save control were all present;
		// only the matcher was wrong. `Case Type Management` and `ZGW API
		// Mapping` set no doc-url, which is why those two passed and this one
		// did not. Anchored at the start and followed by whitespace or the end
		// of the name, so a different section cannot satisfy it by prefix.
		for (const heading of [
			'Configuration',
			'Case Type Management',
			'ZGW API Mapping',
		]) {
			const sectionHeading = new RegExp(
				`^${heading.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?:\\s|$)`,
			)
			await expect(
				page.getByRole('heading', { name: sectionHeading }).first(),
				`the administration surface must render the ${heading} section`,
			).toBeVisible({ timeout: 15000 })
		}
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
