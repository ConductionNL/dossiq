/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for kanban-board-keyboard-status-transition
 * (WCAG 2.1.1 Keyboard on the board's status move).
 *
 * The board is the Cases list in board view (`/cases?_view=board`, the
 * library's CnBoardView). The old `/workflow-board` page redirects there.
 *
 * WHY THIS FILE SEEDS ITS OWN DATA. The board is data-dependent: with no
 * statusTypes and no cases there is nothing to render, and the tests used to
 * skip on exactly that. `seedStateMachine()` gives a caseType, ordered
 * statusTypes and an active workflowTemplate whose `t1` runs Ontvangen to In
 * behandeling with no guard; `seedCase()` gives each test its own card. Every
 * seeded object carries `RUN_PREFIX`, so the assertions target this run's
 * card and `afterAll` deletes exactly what this run created.
 *
 * BOTH TESTS MOVE A CARD AND READ THE MOVE BACK FROM STORAGE. A card in the
 * right column proves the optimistic half only, so each test asserts the
 * case's STORED `status` is the target statusType id.
 *
 * THE KEYBOARD PATH IS M ON THE FOCUSED CARD. It opens the card's menu with
 * the first target focused; the targets are what
 * `/api/case/{id}/available-transitions` offers for THAT case
 * (`caseStatusTargets`), and Enter on one runs the same move a drop runs
 * (`moveCaseStatus`). Enter on the card's title still opens the case.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'
import type { StateMachine } from '../helpers/fixtures.ts'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
} from '../helpers/fixtures.ts'
import { dismissSupportDialog } from '../helpers/nav.ts'

/** The card the keyboard test moves. Carries RUN_PREFIX for isolation. */
const KEYBOARD_TITLE = `${RUN_PREFIX} Kanban keyboard card`
/** The card the drag test moves. Its own card, so the tests stay independent. */
const DRAG_TITLE = `${RUN_PREFIX} Kanban drag card`

/** Column names as `seedStateMachine` writes them. */
const RECEIVED = `${RUN_PREFIX} Ontvangen`
const IN_PROGRESS = `${RUN_PREFIX} In behandeling`

let api: APIRequestContext
let token: string
let sm: StateMachine
let keyboardCaseId: string
let dragCaseId: string

test.describe('Cases board keyboard status transition', () => {
	// A budget sized from what these tests DO, not from slow page loads: each
	// one loads the board, completes a status move through the engine (two
	// requests plus a board re-read) and then polls OpenRegister until the
	// case's stored status changes. The keyboard test opens the case detail
	// after that. The 30s default covered the old pair, which asserted a menu
	// item and an attribute and moved nothing.
	test.setTimeout(240_000)

	// ⚠️ DELIBERATELY NOT `test.describe.configure({ mode: 'serial' })`.
	// These two tests share only the beforeAll fixture; neither depends on the
	// other's side effects, so serial mode buys nothing — and it costs the one
	// thing this file exists to fix. MEASURED, not assumed: with serial mode
	// on, a failure in the first test marks the second `did not run`, which the
	// report records as **outcome "skipped" with NO annotation at all** — a
	// skip carrying no reason whatsoever, which is strictly worse than the
	// false-reason skips this change removes. Off serial, a real failure
	// reports as a failure in both.
	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		// caseType + Ontvangen/In behandeling (non-final) + Afgehandeld (final)
		// + an active workflowTemplate whose `t1` runs Ontvangen -> In
		// behandeling with no guard. That unguarded transition is what the
		// move dialog has to offer: it lists what the ENGINE offers, so a
		// fixture with statuses but no workflow template would render an
		// empty dialog and the test would fail for the wrong reason.
		sm = await seedStateMachine(api, token)
		keyboardCaseId = objectId(
			await seedCase(api, token, {
				title: KEYBOARD_TITLE,
				caseType: sm.caseTypeId,
				status: sm.statusReceived,
			}),
		)
		dragCaseId = objectId(
			await seedCase(api, token, {
				title: DRAG_TITLE,
				caseType: sm.caseTypeId,
				status: sm.statusReceived,
			}),
		)
	})

	test.afterAll(async () => {
		// Everything this run produced goes, child-first: the statusRecords the
		// transition engine wrote, then the cases, then the machine they belong
		// to.
		//
		// This afterAll used to leave the whole machine standing, and the note
		// it carried was right about the cause. The `case` schema is archival
		// (`x-openregister-archival`), so a user-driven DELETE is refused with
		// 403 SCHEMA_ARCHIVAL_IMMUTABLE — `workflows/cases-crud.spec.ts` asserts
		// exactly that, and it passes — while the old `deleteObject` never
		// inspected the response and reported success on removing NOTHING.
		// Deleting the (non-archival) caseType and statusTypes on top of that
		// left the surviving case pointing at ids that no longer resolved:
		// the dashboard's grouped aggregations still returned the orphan's group
		// keys, the chart widget resolved each key by id, and those lookups
		// 404'd. That is what reddened `spec-coverage/ui-pages.spec.ts:55`
		// ("dashboard mounts without dossiq console errors") on a second run —
		// a test that was doing its job.
		//
		// `helpers/fixtures.ts#purgeObject` removes the case for real, through
		// the sanctioned `occ openregister:objects:purge --force --apply`, so
		// there is no longer a surviving parent whose references have to be
		// preserved, and no residue to carry into the next run.
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * The board column carrying a seeded status name.
	 *
	 * @param page The page.
	 * @param name The column's status name.
	 * @return The column whose header names that status.
	 */
	function column(page: Page, name: string): Locator {
		return page.locator('[data-testid="cn-board-column"]').filter({
			has: page.locator('.cn-board-view__column-title', { hasText: name }),
		})
	}

	/**
	 * One seeded case's card inside a column.
	 *
	 * @param page The page.
	 * @param name The column's status name.
	 * @param title The seeded card's title.
	 * @return The card.
	 */
	function cardIn(page: Page, name: string, title: string): Locator {
		return column(page, name).locator('[data-testid="cn-board-card"]', {
			hasText: title,
		})
	}

	/**
	 * Wait until a seeded card is drawn in a column, opening the column's
	 * hidden cards as they arrive. A column draws its first cards and the
	 * rest behind "Show N more", and the rows load in batches, so a seeded
	 * card can sit behind the button on an instance that already holds cases.
	 *
	 * @param page The page.
	 * @param name The column's status name.
	 * @param title The seeded card's title.
	 * @param message What the failure says.
	 */
	async function expectCardIn(
		page: Page,
		name: string,
		title: string,
		message: string,
	): Promise<void> {
		await expect
			.poll(
				async () => {
					const more = column(page, name).locator(
						'[data-testid="cn-board-show-more"]',
					)
					if ((await more.count()) > 0) {
						await more.first().click()
					}
					return cardIn(page, name, title).count()
				},
				{ message, timeout: 30_000 },
			)
			.toBe(1)
	}

	/**
	 * Open the Cases board and return one seeded case's card, asserting it
	 * starts in the Ontvangen column.
	 *
	 * Scoped by the card's own title, so on an instance that already holds
	 * cases the test never drives somebody else's card.
	 *
	 * @param page The page.
	 * @param title The seeded card's title.
	 * @return The card rendering the seeded case.
	 */
	async function openBoardAndFindSeededCard(
		page: Page,
		title: string,
	): Promise<Locator> {
		await page.goto('/index.php/apps/dossiq/cases?_view=board')
		await dismissSupportDialog(page)
		await expect(page.locator('[data-testid="cn-board-view"]')).toBeVisible({
			timeout: 30_000,
		})
		// The seeded non-final column must exist, or there is nowhere for a
		// card to be grouped; asserted separately so a missing column does not
		// present as "the card is missing".
		await expect(column(page, RECEIVED)).toBeVisible({ timeout: 30_000 })
		await expectCardIn(
			page,
			RECEIVED,
			title,
			'the seeded card must start in Ontvangen',
		)
		const card = cardIn(page, RECEIVED, title)
		await expect(card).toBeVisible()
		return card
	}

	/**
	 * Poll the case's STORED status until it equals `statusId`.
	 *
	 * The move is optimistic on screen and asynchronous underneath: the board
	 * asks the engine for the offered transitions, posts one, then re-reads.
	 * A card sitting in the right column proves the optimistic half only, so
	 * the claim that the status changed is read from OpenRegister.
	 *
	 * @param caseId The case.
	 * @param statusId The statusType id it must now carry.
	 * @param how Which gesture made the move, for the failure message.
	 */
	async function expectStoredStatus(
		caseId: string,
		statusId: string,
		how: string,
	): Promise<void> {
		await expect
			.poll(
				async () => String((await showObject(api, 'case', caseId)).status),
				{
					message: `${how} must write the "In behandeling" statusType id to the stored case`,
					timeout: 30_000,
				},
			)
			.toBe(statusId)
	}

	// @e2e openspec/specs/dashboard/spec.md#scenario-dash-v1-006f-keyboard-only-status-transition-new
	test('a card moves to another status with the keyboard alone, and Enter on the card still opens it', async ({
		page,
	}) => {
		const card = await openBoardAndFindSeededCard(page, KEYBOARD_TITLE)

		// M on the focused card opens its menu with a target focused. The
		// menu lists what the engine offers this case, so the seeded target
		// is there and allowed.
		await card.locator('[data-testid="cn-board-card-open"]').focus()
		await page.keyboard.press('m')
		const menu = card.locator('[data-testid="cn-board-card-menu-list"]')
		await expect(menu).toBeVisible({ timeout: 15000 })
		const target = menu.locator('[data-testid="cn-board-menu-move"]', {
			hasText: IN_PROGRESS,
		})
		await expect(target).toBeVisible({ timeout: 15000 })
		await expect(target).not.toHaveAttribute('aria-disabled', 'true')

		// Reached and chosen with the keyboard alone.
		for (let step = 0; step < 100; step++) {
			if (await target.evaluate((el) => el === document.activeElement)) {
				break
			}
			await page.keyboard.press('ArrowDown')
		}
		await expect(target).toBeFocused()
		await page.keyboard.press('Enter')

		// Opening the menu must not also open the case.
		await expect(page).toHaveURL(/\/cases\?_view=board/)

		await expectStoredStatus(
			keyboardCaseId,
			sm.statusInProgress,
			'selecting "In behandeling" with Enter',
		)
		await expectCardIn(
			page,
			IN_PROGRESS,
			KEYBOARD_TITLE,
			'the card must move to the "In behandeling" column',
		)
		const moved = cardIn(page, IN_PROGRESS, KEYBOARD_TITLE)
		await expect(cardIn(page, RECEIVED, KEYBOARD_TITLE)).toHaveCount(0)

		// Enter on the card's title still opens the case.
		await moved.locator('[data-testid="cn-board-card-open"]').focus()
		await page.keyboard.press('Enter')
		await expect(
			page,
			'Enter on the card title must still open the case detail',
		).toHaveURL(new RegExp(`/cases/(split/)?${keyboardCaseId}`), {
			timeout: 30_000,
		})
	})

	// @e2e openspec/specs/dashboard/spec.md#scenario-dash-v1-006g-drag-path-shares-the-move-new
	test('dragging a card onto another column still moves the case', async ({
		page,
	}) => {
		const card = await openBoardAndFindSeededCard(page, DRAG_TITLE)

		// The card's drag is the browser's own (HTML5 draggable), so
		// Playwright's dragTo drives it.
		const target = column(page, IN_PROGRESS)
		await target.scrollIntoViewIfNeeded()
		await card.dragTo(target)

		await expectStoredStatus(
			dragCaseId,
			sm.statusInProgress,
			'dropping the card on "In behandeling"',
		)
		await expectCardIn(
			page,
			IN_PROGRESS,
			DRAG_TITLE,
			'the dragged card must land in the "In behandeling" column',
		)
	})
})
