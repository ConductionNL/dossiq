/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for task-management spec.
 * Each test is tagged with the scenario it covers.
 *
 * ⚠️ THE SCENARIO ENUMERATES SIX FIELDS PER ROW, SO THE TEST READS SIX
 * -------------------------------------------------------------------
 * "View the global task list" requires each row to show the task, the case it
 * hangs off, its state, assignee, due date and priority. This test used to
 * assert that a seeded row existed and that a Subject column header existed,
 * which a list rendering five empty cells satisfies. The seed now carries a
 * case, an assignee, a priority and a due date, and each of those is read out
 * of the seeded row's own cells, by the column the header names.
 *
 * The vocabulary is the ENGINE's: `state`, `dueAt` and `objectUuid`, not the
 * deleted `caseTask` schema's `status`, `dueDate` and `case` (dossiq#2457).
 * Two clauses of the scenario were still the schema's and were corrected on
 * the spec side rather than asserted around: the Subject column resolves the
 * case's TITLE and renders no case identifier, and the Due column renders a
 * relative label rather than a date.
 *
 * ⚠️ THE SUBJECT CELL CANNOT BE READ ON THE SHARED DEV RIG. Measured
 * 2026-09-11 on localhost:8080, `GET /api/flow-tasks` answers `subject: null`
 * for a task whose `objectUuid` names a case that exists and is readable, so
 * the Subject column renders a dash and the sibling assertion in
 * `pages.spec.ts` ("the Subject column shows the case title, not its uuid")
 * fails there too. Both pass in CI, whose instance is built per run: the last
 * green `development` run before this change carried both. The assertion is
 * kept because the scenario requires it and CI is where it is proven.
 *
 * MUTATION POINT, NOT YET RUN. The mutation runs were refused by the
 * permission system on 2026-09-11. The columns come from OpenRegister's
 * `tasks` source in @conduction/nextcloud-vue: drop the `priority` (or
 * `assignee`) column from that source and the row assertion below reddens
 * with `the task row must show its priority`.
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/apps/dossiq/<route>)
 * so the Vue history-mode router can resolve the route correctly.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupFlowTasks,
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
} from '../helpers/fixtures.ts'

test.describe('Task Management spec coverage', () => {
	let api: APIRequestContext
	let token = ''

	/**
	 * Far enough out that the inbox's `-dueAt` sort keeps this file's own
	 * task on page one of a list that pages at 25.
	 *
	 * ⚠️ Tolerance, not a guarantee: the engine orders by `due_at` alone and
	 * where an UNDATED task sorts is the datastore's business. It fails as
	 * "the seeded row is not there", never as a silent pass.
	 */
	const FAR_FUTURE_DUE = '2099-12-31T09:00:00+00:00'

	/** The task this file drives, found by its title. */
	const TASK_TITLE = `${RUN_PREFIX} spec-coverage task`
	/** The case it hangs off, which the Subject column must name. */
	const CASE_TITLE = `${RUN_PREFIX} spec-coverage case`
	/** The uid the task is assigned to, which the Assignee column must show. */
	const ASSIGNEE = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		// The signed-in storage state, explicitly. `playwright.request` is
		// the raw API and inherits nothing from `use`, so a context built
		// without it carries no session and the seed below answers 401.
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// 🔴 THE LIST HAS TO HOLD A ROW FOR ITS COLUMNS TO EXIST.
		// CnDataTable renders no `<table>` at all when there is nothing to
		// show, so a column assertion on an empty instance would be
		// unreachable — and an "empty state OR rows" poll next to it would
		// pass whether or not the list works. Owning one row makes both
		// halves of the scenario assertable.
		//
		// In the ENGINE: the index is `entitySource: "tasks"` since
		// dossiq#2408, so a `caseTask` object is a row this page cannot see.
		// The task hangs off a case of its own, because the Subject column
		// resolves `objectUuid` and a task with none shows a dash there,
		// which is a state the row assertion must not accept.
		const caseType = await ensureCaseType(api, token)
		const parent = await seedCase(api, token, {
			title: CASE_TITLE,
			caseType: caseType.id,
		})
		await seedFlowTask(api, token, {
			title: TASK_TITLE,
			objectUuid: objectId(parent),
			assignee: ASSIGNEE,
			priority: 'high',
			state: 'available',
			dueAt: FAR_FUTURE_DUE,
		})
	})

	test.afterAll(async () => {
		if (api === undefined) return
		// A flow task is not an OpenRegister object, so it is cancelled
		// through the engine's own verb rather than swept by prefix. The case
		// it hangs off IS one, and goes the ordinary way.
		await cleanupFlowTasks(api, token)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * The cell of `row` under the column header matching `header`.
	 *
	 * Read by header rather than by position: the engine's task source owns
	 * the column order, and a test that hard-codes an index asserts about
	 * whichever column happens to sit there.
	 *
	 * @param page The page.
	 * @param row The seeded task's row.
	 * @param header The column header's text.
	 * @return The cell locator.
	 */
	async function cellUnder(
		page: Page,
		row: Locator,
		header: RegExp,
	): Promise<Locator> {
		const columnHeader = page.getByRole('columnheader', { name: header })
		await expect(
			columnHeader,
			`the task list must carry a ${String(header)} column`,
		).toBeVisible({ timeout: 10_000 })
		const index = await columnHeader.evaluate((th) =>
			Array.from(th.parentElement!.children).indexOf(th),
		)
		return row.locator(`td:nth-child(${index + 1})`)
	}

	// @e2e openspec/specs/task-management/spec.md#view-the-global-task-list
	test('every task row shows its case, state, assignee, due date and priority, and the list offers no add button', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/dossiq/tasks')

		// The scenario is "view the global task list", so the list must
		// RENDER, and the seeded row is the one that says it did. This used
		// to accept "No items found" as satisfaction, which is a state a
		// broken list reaches too.
		const row = page.locator('tbody tr').filter({ hasText: TASK_TITLE })
		await expect(row).toHaveCount(1, { timeout: 30_000 })

		// 🔴 THE ADD BUTTON IS GONE, BY DESIGN, and this used to assert it
		// was visible — exactly backwards now. The tasks source sets
		// `showAdd: false`: a task is raised by a flow, never by a person
		// clicking Add, and an Add here would open the index's schema form
		// and write an object the engine's inbox never reads.
		//
		// `toHaveCount(0)` rather than `not.toBeVisible()`: nc-vue renders
		// zero-size chrome that is still in the accessibility tree, so
		// "not visible" would pass against a button that is merely
		// collapsed.
		await expect(
			page.getByRole('button', { name: /^Add (Task|Item)$/ }),
		).toHaveCount(0)

		// And it is the ENGINE's list, not an object list over a `caseTask`
		// schema. The column strip is what tells the two apart: the subject
		// column reads Subject where the schema list read Case, and there is
		// no Team column at all, because the engine models candidate groups
		// as a LIST and has no single `assigneeGroup` a column could bind to.
		await expect(
			page.getByRole('columnheader', { name: /^(Subject|Onderwerp)$/ }),
		).toBeVisible({ timeout: 10_000 })
		await expect(
			page.getByRole('columnheader', { name: /^(Team|Groep)$/ }),
		).toHaveCount(0)

		// The six fields the scenario enumerates, read off the seeded row.
		// Each one is a separate assertion, so a failure names the field that
		// went missing rather than "the row text changed".
		await expect(
			await cellUnder(page, row, /^(Task|Taak)$/),
			'the task row must show its own title',
		).toContainText(TASK_TITLE)
		await expect(
			await cellUnder(page, row, /^(Subject|Onderwerp)$/),
			'the task row must name the case it hangs off',
		).toContainText(CASE_TITLE)
		await expect(
			await cellUnder(page, row, /^(State|Status)$/),
			'the task row must show its state',
		).toContainText(/available|beschikbaar/i)
		await expect(
			await cellUnder(page, row, /^(Assignee|Toegewezen aan)$/),
			'the task row must show its assignee',
		).toContainText(ASSIGNEE)
		// The engine's list renders the due date as a RELATIVE label, built by
		// `taskDueLabel`: "Due in {days} days" for a future date. Asserting
		// the seeded ISO date would fail against a correct list.
		await expect(
			await cellUnder(page, row, /^(Due|Vervaldatum)$/),
			'the task row must show its due date',
		).toHaveText(/Due in \d+ days|Vervalt over \d+ dagen/i)
		await expect(
			await cellUnder(page, row, /^(Priority|Prioriteit)$/),
			'the task row must show its priority',
		).toContainText(/high|hoog/i)

		// Should not show broken state
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})
