/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for task-management spec.
 * Each test is tagged with the scenario it covers.
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/apps/dossiq/<route>)
 * so the Vue history-mode router can resolve the route correctly.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupFlowTasks,
	getRequestToken,
	RUN_PREFIX,
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
		await seedFlowTask(api, token, {
			title: `${RUN_PREFIX} spec-coverage task`,
			state: 'available',
			dueAt: FAR_FUTURE_DUE,
		})
	})

	test.afterAll(async () => {
		if (api === undefined) return
		// A flow task is not an OpenRegister object, so it is cancelled
		// through the engine's own verb rather than swept by prefix.
		await cleanupFlowTasks(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/task-management/spec.md#view-the-global-task-list
	test('global task list page renders its rows and columns, and offers no add button', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/dossiq/tasks')

		// The scenario is "view the global task list", so the list must
		// RENDER, and the seeded row is the one that says it did. This used
		// to accept "No items found" as satisfaction, which is a state a
		// broken list reaches too.
		await expect(
			page.locator('tbody tr').filter({ hasText: RUN_PREFIX }).first(),
		).toBeVisible({ timeout: 30_000 })

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

		// Should not show broken state
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})
