/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The four search fields on the Tasks index (task-search-fields, row 9.11).
 *
 * The lenses answer six fixed questions. These fields let you ask your own:
 * one case, a due window, a state, a priority. REQ-TASK-021 says each one is
 * answered by the engine SERVER-SIDE, that a lens and a field compose, and
 * that the URL carries both.
 *
 * WHAT THIS FILE IS GUARDING AGAINST, and it is not a crash. A sidebar filter
 * that reaches no query renders, takes a choice, highlights it, and leaves the
 * list exactly as it was. That is indistinguishable from a filter matching
 * every row. So every scenario here asserts BOTH directions: the row that
 * should stay is visible AND the row that should go is gone. A one-sided
 * assertion passes on a filter that does nothing.
 *
 * TWO THINGS ABOUT THE FIXTURES.
 *
 * ONE. THE ROWS ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY COUNT. The Tasks
 * index is a shared inbox on a shared instance and another session's fixtures
 * land in it mid-run. "The list holds this task and not that one" is the only
 * claim a shared list supports.
 *
 * TWO. THE FILTER IS READ BACK OFF THE WIRE BEFORE THE BROWSER IS ASKED. The
 * engine answers `objectUuid`, `dueAfter`/`dueBefore`, `state` and `priority`;
 * an older openregister DROPS an argument it does not declare, silently,
 * because Nextcloud hands a controller only the parameters it names. The
 * browser assertion would then read "the filter does not narrow", pointing at
 * the sidebar rather than at the instance underneath it. `beforeAll` probes
 * each predicate by behaviour and names it if it is missing.
 *
 * @spec openspec/changes/task-search-fields/specs/task-management/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupFlowTasks,
	cleanupRunObjects,
	getRequestToken,
	listFlowTasks,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedFlowTask,
	seedStateMachine,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const TASKS_URL = `/apps/${REGISTER}/tasks`

/** The signed-in user the Mine lens is about. */
const ME = process.env.ADMIN_USER ?? 'admin'

/** The chips, in both languages: CI is English and this instance may be Dutch. */
const CHIPS = {
	all: /^(All|Alle)$/,
	mine: /^(Mine|Van mij)$/,
}

/** The sidebar filter labels, in both languages. */
const FIELDS = {
	case: /^(Case|Zaak)$/,
	state: /^(State|Staat)$/,
	priority: /^(Priority|Prioriteit)$/,
	dueBetween: /^(Due between|Uiterlijk tussen)$/,
}

const cases: Record<string, string> = {}
const tasks: Record<string, string> = {}

/**
 * An ISO instant a whole number of days from now, at a fixed hour.
 *
 * @param days  Days from today, negative for the past.
 * @param hour  The hour of that day, local.
 *
 * @return The ISO-8601 instant.
 */
function instant(days: number, hour: number): string {
	const at = new Date()
	at.setDate(at.getDate() + days)
	at.setHours(hour, 0, 0, 0)
	return at.toISOString()
}

/** The window the "due between" scenario asks for: days 2 through 5. */
const WINDOW_FROM = instant(2, 0)
const WINDOW_TO = instant(5, 23)

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ playwright, baseURL }) => {
	const api: APIRequestContext = await playwright.request.newContext({ baseURL })
	const token = await getRequestToken(api)

	// A throwaway case type, so the two cases are this run's alone.
	const machine = await seedStateMachine(api, token)
	for (const key of ['alpha', 'beta']) {
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} search-${key}`,
			caseType: machine.caseTypeId,
			status: machine.statusReceived,
		})
		cases[key] = objectId(seeded)
	}

	// One task per case, so "narrow to one case" has something to exclude.
	tasks['alpha-task'] = await seedFlowTask(api, token, {
		title: `${RUN_PREFIX} alpha-task`,
		objectUuid: cases.alpha,
		assignee: ME,
		state: 'active',
		priority: 'high',
		dueAt: instant(3, 9),
	})
	tasks['beta-task'] = await seedFlowTask(api, token, {
		title: `${RUN_PREFIX} beta-task`,
		objectUuid: cases.beta,
		assignee: ME,
		state: 'active',
		priority: 'low',
		dueAt: instant(3, 9),
	})
	// Mine, and OUTSIDE the window, so the due-window scenario excludes a row
	// the lens itself would have kept.
	tasks['alpha-far'] = await seedFlowTask(api, token, {
		title: `${RUN_PREFIX} alpha-far`,
		objectUuid: cases.alpha,
		assignee: ME,
		state: 'active',
		priority: 'low',
		dueAt: instant(40, 9),
	})

	// 🔴 PROBE EACH PREDICATE BY BEHAVIOUR, NOT BY VERSION STRING. An argument
	// the controller does not declare is DROPPED, and the read then answers
	// everything: a task on the other case coming back under `objectUuid` is
	// the filter not being applied at all.
	const oneCase = await listFlowTasks(api, {
		scope: 'all',
		objectUuid: cases.alpha,
		limit: '200',
	})
	const oneCaseIds = oneCase.map((t: any) => String(t.uuid))
	expect(
		oneCaseIds,
		'this openregister does not answer the objectUuid filter; the sidebar '
			+ 'cannot narrow to one case on it',
	).not.toContain(tasks['beta-task'])
	expect(oneCaseIds).toContain(tasks['alpha-task'])

	const window = await listFlowTasks(api, {
		scope: 'all',
		isTerminal: 'false',
		dueAfter: WINDOW_FROM,
		dueBefore: WINDOW_TO,
		objectUuid: cases.alpha,
		limit: '200',
	})
	const windowIds = window.map((t: any) => String(t.uuid))
	expect(
		windowIds,
		'this openregister does not answer the dueAfter/dueBefore window '
			+ '(openregister#3581); the due-between field cannot narrow on it',
	).not.toContain(tasks['alpha-far'])
	expect(windowIds).toContain(tasks['alpha-task'])

	await api.dispose()
})

test.afterAll(async ({ playwright, baseURL }) => {
	const api: APIRequestContext = await playwright.request.newContext({ baseURL })
	const token = await getRequestToken(api)
	await cleanupFlowTasks(api, token)
	await cleanupRunObjects(api, token)
	await api.dispose()
})

/**
 * Open the Tasks index with its filters sidebar showing.
 *
 * @param page The page.
 */
async function openTasksWithFilters(page: any): Promise<any> {
	await page.goto(TASKS_URL, PAGE_LOAD)
	await dismissSupportDialog(page)
	await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })
	await page
		.getByRole('button', { name: /Open sidebar|Filters|Zijbalk/ })
		.first()
		.click()
	const sidebar = page.locator('.app-sidebar')
	await expect(sidebar, 'the filters sidebar opens').toBeVisible({
		timeout: 15_000,
	})
	return sidebar
}

/**
 * The row for one seeded task, addressed by its run-prefixed title.
 *
 * @param page The page.
 * @param key  The key the task was seeded under.
 */
function row(page: any, key: string): any {
	return page.getByRole('row').filter({ hasText: `${RUN_PREFIX} ${key}` })
}

test.describe('the Tasks index offers its search fields', () => {
	test('names all four fields and no assignee picker', async ({ page }) => {
		const sidebar = await openTasksWithFilters(page)

		for (const label of Object.values(FIELDS)) {
			await expect(
				sidebar.getByText(label).first(),
				`the sidebar names the ${String(label)} filter`,
			).toBeVisible({ timeout: 15_000 })
		}

		// The one field a task search is expected to have and this inbox
		// cannot answer. It must be ABSENT rather than present and inert:
		// `TaskInboxCriteria` has no assignee predicate, so a picker here
		// would answer about everybody while looking like it answered about
		// one person. See tasks.md 1.1.
		await expect(
			sidebar.getByText(/^(Assignee|Toegewezen aan)$/),
			'the sidebar must not offer a filter the engine cannot answer',
		).toHaveCount(0)
	})
})

test.describe('Narrow to one case', () => {
	test("keeps that case's tasks and drops the other case's", async ({ page }) => {
		const sidebar = await openTasksWithFilters(page)
		await page.getByRole('tab', { name: CHIPS.all }).click()

		await expect(row(page, 'alpha-task')).toBeVisible({ timeout: 20_000 })
		await expect(row(page, 'beta-task')).toBeVisible({ timeout: 20_000 })

		// The case picker lists the cases by title; picking one is the whole
		// interaction the requirement names.
		await sidebar.getByText(FIELDS.case).first().click()
		await page.getByText(`${RUN_PREFIX} search-alpha`).last().click()

		await expect(
			row(page, 'alpha-task'),
			'the chosen case keeps its task',
		).toBeVisible({ timeout: 20_000 })
		await expect(
			row(page, 'beta-task'),
			"the other case's task is gone; a filter that leaves it looks "
				+ 'exactly like one that matched everything',
		).toHaveCount(0, { timeout: 20_000 })

		// The second half of the requirement: the view can be shared.
		await expect
			.poll(() => new URL(page.url()).searchParams.get('objectUuid'), {
				timeout: 10_000,
			})
			.toBe(cases.alpha)
	})
})

test.describe('Due window inside a lens', () => {
	test('narrows within Mine rather than replacing it', async ({ page }) => {
		const sidebar = await openTasksWithFilters(page)
		await page.getByRole('tab', { name: CHIPS.mine }).click()

		// Both are mine and both are open, so the lens alone keeps both. Only
		// the window separates them.
		await expect(row(page, 'alpha-task')).toBeVisible({ timeout: 20_000 })
		await expect(row(page, 'alpha-far')).toBeVisible({ timeout: 20_000 })

		const dueFrom = sidebar.getByRole('textbox', { name: /^(From|Van)$/ })
		const dueTo = sidebar.getByRole('textbox', { name: /^(To|Aan)$/ })
		await dueFrom.fill(WINDOW_FROM.slice(0, 10))
		await dueFrom.press('Enter')
		await dueTo.fill(WINDOW_TO.slice(0, 10))
		await dueTo.press('Enter')

		await expect(
			row(page, 'alpha-task'),
			'the task due inside the window stays',
		).toBeVisible({ timeout: 20_000 })
		await expect(
			row(page, 'alpha-far'),
			'the task due outside the window goes, even though the lens keeps it',
		).toHaveCount(0, { timeout: 20_000 })

		// The lens is still the one that was picked: a field narrows within a
		// lens, it does not replace it.
		await expect(
			page.getByRole('tab', { name: CHIPS.mine }),
			'the Mine lens is still active',
		).toHaveAttribute('aria-selected', 'true')

		await expect
			.poll(() => new URL(page.url()).searchParams.get('dueAt'), {
				timeout: 10_000,
			})
			.toContain('..')
	})
})
