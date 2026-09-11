/*
 * SPDX-FileCopyrightText: 2026 DossiQ Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Queue page: the cases nobody has picked up yet.
 *
 * The queue is a FILTER, not a collection, so the assertions here are about the
 * filter holding rather than about rows existing. A page that silently dropped
 * its base filter would render a healthy-looking table of every case in the
 * instance, which is exactly what `assignee_isnull=true` produced before the
 * `IS NULL` sentinel replaced it: no error, no empty state, just the wrong set.
 *
 * Entries are asserted by PRESENCE where the nav is concerned: the Queue leaf
 * sits inside the collapsible My work group and is rendered but hidden until
 * the group is expanded.
 *
 * ⚠️ THE ROWS ARE READ FROM THE PAGE'S OWN ANSWER, NOT OFF THE SCREEN
 * ------------------------------------------------------------------
 * The scenarios say what is true of EVERY row, and a rendered table shows
 * twenty of them at most. So the tests below take the list response the page
 * fetched and assert over all of it: no assignee, `isFinalStatus` false, and
 * after a sidebar pick, the chosen case type. Reading the screen instead is
 * what let the deep-link test pass while asserting nothing about the filter.
 *
 * MUTATION POINTS, NOT YET RUN. The mutation runs were refused by the
 * permission system on 2026-09-11. Both are client-side, in
 * `src/manifest.json`, on the `Queue` page:
 *
 *  - drop `"assignee": "IS NULL"` from `config.filter`. Expected red: `every
 *    row on /queue must be an open case with no assignee`.
 *  - change `config.folderSidebar.filterField` from `caseType` to anything
 *    else. Expected red: `picking a case type must narrow the queue to that
 *    case type`.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	RUN_PREFIX,
	seedCase,
} from '../helpers/fixtures.ts'

let api: APIRequestContext
let token: string
let caseTypeId: string
let caseTypeName: string

/** The unassigned open case that keeps the queue from being empty. */
const WAITING_TITLE = `${RUN_PREFIX} Waiting for somebody`
/** A case with an assignee, so the queue is strictly smaller than the index. */
const HELD_TITLE = `${RUN_PREFIX} Already picked up`

/** One list answer the page fetched: its query and its parsed body. */
interface ListAnswer {
	url: string
	body: any
}

/**
 * Record every case-list answer the page receives from here on.
 *
 * The page's OWN requests are read rather than a second set of the test's
 * making: a filter the page failed to send is the defect these scenarios are
 * about, and a request the test composes itself cannot see it. They are
 * collected rather than awaited one by one, because the index fires more than
 * one list request per navigation and `waitForResponse` would hand back
 * whichever arrived first.
 *
 * @param page The page.
 * @return The growing list of answers. Empty it before each navigation.
 */
function recordListAnswers(page: Page): ListAnswer[] {
	const answers: ListAnswer[] = []
	page.on('response', async (response) => {
		const url = decodeURIComponent(response.url())
		if (
			/\/api\/objects\/dossiq\/case\?/.test(url) === false
			|| url.includes('_page=') === false
			|| response.request().method() !== 'GET'
		) {
			return
		}
		const body = await response.json().catch(() => null)
		if (body !== null) {
			answers.push({ url, body })
		}
	})
	return answers
}

/**
 * The most recent recorded answer matching `predicate`, waiting for one.
 *
 * @param answers The recorder from `recordListAnswers`.
 * @param predicate Which answer this assertion is about.
 * @param message What it means when none arrives.
 * @return The parsed OpenRegister list response.
 */
async function answerMatching(
	answers: ListAnswer[],
	predicate: (answer: ListAnswer) => boolean,
	message: string,
): Promise<any> {
	await expect
		.poll(() => answers.filter(predicate).length, {
			message,
			timeout: 120_000,
		})
		.toBeGreaterThan(0)
	const matching = answers.filter(predicate)
	return matching[matching.length - 1].body
}

/**
 * Describe a row that should not be in the queue, for the failure message.
 *
 * @param row One case from the list answer.
 * @return Its title with the two fields the base filter is about.
 */
function offendingRow(row: any): string {
	return `${String(row.title)} (assignee=${JSON.stringify(row.assignee)}, isFinalStatus=${JSON.stringify(row.isFinalStatus)})`
}

test.describe('Queue', () => {
	// Same budget as the other dossiq shell specs: a large manifest plus an
	// OpenRegister round trip on load.
	test.setTimeout(300_000)

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		const caseType = await ensureCaseType(api, token)
		caseTypeId = caseType.id
		caseTypeName = caseType.name
		// One case the queue MUST hold and one it must not. Without the first
		// the "every row" assertions below are vacuous on an empty queue;
		// without the second the queue and the case index can be the same size
		// on a rig where nothing is assigned, and "strictly fewer" would fail
		// against a working queue.
		await seedCase(api, token, {
			title: WAITING_TITLE,
			caseType: caseTypeId,
		})
		await seedCase(api, token, {
			title: HELD_TITLE,
			caseType: caseTypeId,
			assignee: 'admin',
		})
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/specs/add-work-queue/spec.md#the-queue-holds-unassigned-open-cases
	test('every row on the queue is an open case nobody has picked up', async ({
		page,
	}) => {
		// A PATH, not `#/queue`: dossiq runs on createWebHistory, so a hash deep
		// link navigates nowhere and lands on the dashboard without throwing.
		// This test used to stop at that heading, which is why a queue that had
		// lost its base filter passed it.
		const answers = recordListAnswers(page)
		await page.goto('/index.php/apps/dossiq/queue')
		const queue = await answerMatching(
			answers,
			() => true,
			'the queue page must fetch its list',
		)

		await expect(
			page.getByRole('heading', { name: /^(Queue|Werkvoorraad)$/i }).first(),
			'the deep link must land on the QUEUE page, not the shell default',
		).toBeVisible({ timeout: 30_000 })

		expect(
			queue.results.length,
			`the queue must hold the seeded unassigned open case "${WAITING_TITLE}"`,
		).toBeGreaterThan(0)
		expect(
			queue.results
				.filter(
					(row: any) =>
						String(row.assignee ?? '') !== ''
						|| row.isFinalStatus !== false,
				)
				.map(offendingRow),
			'every row on /queue must be an open case with no assignee',
		).toEqual([])

		// And the page holds strictly fewer cases than the index, which the
		// seeded assigned case guarantees there is room for.
		answers.length = 0
		await page.goto('/index.php/apps/dossiq/cases')
		const cases = await answerMatching(
			answers,
			() => true,
			'the case index must fetch its list',
		)
		expect(
			queue.total,
			'the queue must hold strictly fewer cases than the case index',
		).toBeLessThan(cases.total)
	})

	// @e2e openspec/specs/add-work-queue/spec.md#the-queue-holds-unassigned-open-cases
	test('an assigned case is on the case index and NOT in the queue', async ({
		page,
	}) => {
		// ⚠️ THIS USED TO COUNT `table tbody tr` ON BOTH PAGES, and that number is
		// wrong twice over. The index pages at 20 rows, so on any instance past 20
		// cases both pages render exactly 20 and `20 < 20` is false — measured on a
		// demo-sized rig as "Showing 20 of 52" against "Showing 20 of 50", a
		// correct queue that the test called broken.
		//
		// Reading the totals the pages REPORT fixes the arithmetic but not the
		// test: a tally cannot say WHICH case the filter should have dropped, and
		// it only means anything while the instance happens to hold an assigned or
		// closed case. On a rig whose every case was open and unassigned the two
		// totals were equal and the comparison failed against a working queue.
		//
		// So the discriminator is seeded here rather than assumed: one case with an
		// assignee, which the queue's base filter (`assignee: "IS NULL"`) must
		// exclude and the case index must keep. Both pages are queried by that
		// case's own title, so neither answer depends on how many other cases the
		// instance holds.
		const title = `${RUN_PREFIX} Assigned case`
		await seedCase(api, token, {
			title,
			caseType: caseTypeId,
			assignee: 'admin',
		})

		const rowsMatching = async (path: string): Promise<number> => {
			await page.goto(`${path}?title=${encodeURIComponent(title)}`)
			await expect(page.locator('[data-testid="cn-page"]')).toBeVisible({
				timeout: 60_000,
			})
			// Settle on the page's own "loaded" marker rather than a bare row
			// count: `cn-page` becomes visible while the table is still fetching, so
			// counting on that signal alone reads 0 from a page about to render.
			await expect(
				page.locator('.cn-index-page__empty, table tbody tr').first(),
			).toBeVisible({ timeout: 60_000 })
			return await page.locator('table tbody tr').count()
		}

		expect(
			await rowsMatching('/index.php/apps/dossiq/cases'),
			`the case index must hold the assigned case "${title}"`,
		).toBe(1)

		expect(
			await rowsMatching('/index.php/apps/dossiq/queue'),
			`the queue must NOT hold "${title}": it has an assignee, and the base `
				+ 'filter (assignee IS NULL, isFinalStatus false) is what keeps '
				+ 'picked-up work out of the queue',
		).toBe(0)
	})

	// @e2e openspec/specs/add-work-queue/spec.md#an-empty-queue-says-so
	test('an empty result renders the empty state, not a bare table', async ({
		page,
	}) => {
		// Drive the filter to a slice that cannot match. The page must answer with
		// its empty state rather than a blank region: "mounted and empty" and
		// "never mounted" look identical without a marker to probe.
		const answers = recordListAnswers(page)
		await page.goto('/index.php/apps/dossiq/queue?caseType=__none__')
		const answer = await answerMatching(
			answers,
			(recorded) => recorded.url.includes('caseType=__none__'),
			'the queue must ask for the case type the deep link names',
		)

		// The premise first: the page really did ask for a slice that holds
		// nothing. Without this the assertions below could be satisfied by a
		// page that ignored the case type and happened to render rows.
		expect(
			answer.total,
			'the impossible case type must leave the queue with nothing to show',
		).toBe(0)

		// The locator used to be `.cn-index-page__empty, table tbody tr`, which
		// accepted a table of rows as proof of an empty state.
		await expect(
			page.locator('.cn-index-page__empty'),
			'an empty queue must say so',
		).toBeVisible({ timeout: 30_000 })
		await expect(
			page.locator('.cn-index-page__empty'),
			"the empty state carries the queue's own sentence, from the manifest",
		).toContainText('Nothing is waiting')
		await expect(
			page.locator('table'),
			'an empty queue must not render a bare table',
		).toHaveCount(0)
	})

	// @e2e openspec/specs/add-work-queue/spec.md#the-queue-narrows-by-case-type
	test('the case-type sidebar narrows the queue to that case type', async ({
		page,
	}) => {
		const answers = recordListAnswers(page)
		await page.goto('/index.php/apps/dossiq/queue')
		const unfiltered = await answerMatching(
			answers,
			() => true,
			'the queue page must fetch its list',
		)
		await expect(
			page.locator('.cn-index-page__empty, table tbody tr').first(),
			'the queue must answer before a case type is picked',
		).toBeVisible({ timeout: 30_000 })

		// The folder sidebar is the same control the Cases index carries. The
		// entry for the fixture's own case type is picked by NAME, so the test
		// knows which rows it should get back; `nth(1)` used to pick whatever
		// sat second, and the whole assertion was wrapped in a count guard that
		// passed silently when the sidebar was empty.
		const entry = page
			.locator('[data-testid="cn-folder-sidebar"] li, .cn-folder-sidebar li')
			.filter({ hasText: caseTypeName })
			.first()
		await expect(
			entry,
			`the folder sidebar must offer the case type "${caseTypeName}"`,
		).toBeVisible({ timeout: 30_000 })

		answers.length = 0
		await entry.click()

		// The page must have ASKED for that case type, and every row it got back
		// must be of it. A count that merely did not grow is what this used to
		// assert, and an unnarrowed queue satisfies that.
		const narrowed = await answerMatching(
			answers,
			(recorded) => recorded.url.includes(`caseType=${caseTypeId}`),
			`picking "${caseTypeName}" must send that case type as a filter`,
		)
		expect(
			narrowed.results.length,
			`picking "${caseTypeName}" must still hold the seeded case of that type`,
		).toBeGreaterThan(0)
		expect(
			narrowed.results
				.filter((row: any) => String(row.caseType ?? '') !== caseTypeId)
				.map(
					(row: any) =>
						`${String(row.title)} (caseType=${String(row.caseType)})`,
				),
			'picking a case type must narrow the queue to that case type',
		).toEqual([])
		// And the base filter survives the narrowing: a facet pick may not put
		// assigned or closed work back in the queue.
		expect(
			narrowed.results
				.filter(
					(row: any) =>
						String(row.assignee ?? '') !== ''
						|| row.isFinalStatus !== false,
				)
				.map(offendingRow),
			'narrowing must not widen the queue past its base filter',
		).toEqual([])
		expect(
			narrowed.total,
			'narrowing to one case type cannot widen the result set',
		).toBeLessThanOrEqual(unfiltered.total)
	})
})
