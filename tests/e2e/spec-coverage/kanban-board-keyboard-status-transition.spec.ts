/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for kanban-board-keyboard-status-transition
 * (WCAG 2.1.1 Keyboard fix on the Workflow Board's status-move control).
 *
 * ⚠️ WHY THIS FILE SEEDS ITS OWN DATA
 * -----------------------------------
 * Both tests here used to open the board, look for a `.case-card`, find none,
 * and `test.skip(true, 'No cases on the Workflow Board …')`. That reason was
 * TRUE — and that is exactly the problem. The board is a data-dependent
 * surface: `WorkflowBoard.fetchData()` builds one column per NON-FINAL
 * statusType and then groups cases into a column by resolving `case.status`
 * to that statusType's NAME. With no statusTypes and no cases in the target
 * register there is nothing to render, so the assertions below never ran —
 * on CI they had never run at all.
 *
 * A skip that is permanently true is an invisible pass under L8: the tests report
 * "not applicable" rather than "untested", and the skip count hides them.
 * The fix is therefore a FIXTURE change, not a timing change: seed the same
 * shape `workflows/case-lifecycle.spec.ts` seeds — `seedStateMachine()` for a
 * caseType + three ordered statusTypes + an active workflowTemplate, then
 * `seedCase()` for the cards — and then ASSERT, with no escape hatch. If the
 * board does not render the seeded card, that is a failure, and it should be.
 *
 * `case-lifecycle.spec.ts:185` ("the workflow board renders a column per
 * status type with real case rows") passes in CI using precisely this fixture,
 * so the shape is known-good; it is the one this file adopts rather than a
 * new one.
 *
 * Every seeded object carries `RUN_PREFIX` in a human-visible field, so the
 * assertions target THIS run's card (never another run's or an instance's
 * demo data) and `afterAll` deletes exactly what this run created.
 *
 * Note: navigation is `page.goto('/index.php/apps/dossiq/workflow-board')` —
 * the identical path `spec-coverage/workflow-operations.spec.ts:18` uses to
 * reach the same board, and that test passes.
 *
 * ⚠️ BOTH TESTS NOW MOVE A CARD, AND READ THE MOVE BACK FROM STORAGE
 * -----------------------------------------------------------------
 * They used to stop short. The keyboard test opened the move menu, saw the
 * target offered, and pressed Escape; the drag test read `draggable="true"`
 * off the card. Neither could fail when the move itself broke: a menu item
 * whose handler did nothing, or a drop handler that ignored the card, left
 * both green. Each test now owns its own card (one move would otherwise
 * change the column the other starts from), completes the move, and asserts
 * the case's STORED `status` is the target statusType id, which is what both
 * scenarios require.
 *
 * ⚠️ THE KEYBOARD PATH IS NOW THE M KEY, NOT A TABBABLE MENU
 * ---------------------------------------------------------
 * The card used to carry an NcActions listing every board column, and that is
 * what this test tabbed to. A column exists per status NAME across every case
 * type on the instance, so on a populated register the menu offered two
 * hundred statuses, nearly all of them out of workflows the case has nothing
 * to do with — which is why the old version of this test had to ArrowDown up
 * to sixty times to reach its target.
 *
 * The control is gone. Moving is asked for by right-clicking the card or
 * pressing M on it, and answered by a dialog listing what
 * `/api/case/{id}/available-transitions` offers for THAT case. The keyboard
 * path this file exists to protect is preserved, and it is now shorter than
 * it was; the test asserts the old control's ABSENCE first, so a leftover
 * cannot quietly coexist with it.
 *
 * MUTATION POINTS, NOT YET RUN. The mutation runs for this file were refused
 * by the permission system on 2026-09-11, so each point below is where the
 * check goes, with the assertion that should redden. Both are client-side.
 *
 *  - Keyboard (006f): in `src/views/workflow-board/CaseCard.vue`, drop the
 *    `@keydown.m` binding. Expected red: `selecting "In behandeling" with
 *    Enter must write the "In behandeling" statusType id to the stored case`,
 *    because the dialog never opens. Making `WorkflowBoard.onMoveConfirmed` a
 *    no-op reddens the same assertion one step later. Dropping
 *    `@keydown.enter` from the card root instead should redden `Enter on the
 *    card body must still open the case detail`.
 *  - Drag (006g): in `src/views/workflow-board/BoardColumn.vue`, drop the
 *    `@add="onAdd"` binding on the list; Sortable then moves the card between
 *    the lists and nobody posts the transition. Expected red: `dropping the
 *    card on "In behandeling" must write the "In behandeling" statusType id
 *    to the stored case`. Dropping `@move="onMove"` instead should leave the
 *    move green, since the offer is only what refuses a column early.
 *
 * THE DRAG TEST IS REWRITTEN FOR SORTABLE AND NOT YET RUN. The card's drag is
 * Sortable's (vue-draggable-plus, fallback mode) since the board stopped using
 * the browser's HTML5 drag: it listens to pointer events, so the DataTransfer
 * dispatch this test used to make reaches nothing. The pointer drag below is
 * verified by reading only; it needs the Playwright browsers and the seeded
 * fixtures.
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

test.describe('Workflow Board keyboard status transition', () => {
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
	 * @return The `.board-column` whose header names that status.
	 */
	function column(page: Page, name: string): Locator {
		return page.locator('.board-column').filter({
			has: page.locator('.board-column__name', { hasText: name }),
		})
	}

	/**
	 * Open the Workflow Board and return one seeded case's card, asserting it
	 * starts in the Ontvangen column.
	 *
	 * Scoped by the card's own title rather than `.case-card` first(): on an
	 * instance that already holds cases, `.first()` would drive somebody
	 * else's card and the test would be asserting about data it did not
	 * create.
	 *
	 * @param page The page.
	 * @param title The seeded card's title.
	 * @return The `.case-card` element rendering the seeded case.
	 */
	async function openBoardAndFindSeededCard(
		page: Page,
		title: string,
	): Promise<Locator> {
		await page.goto('/index.php/apps/dossiq/workflow-board')
		await dismissSupportDialog(page)

		// The board renders its heading unconditionally; the columns and cards
		// arrive after fetchData() resolves three collections.
		await expect(
			page.getByRole('heading', { name: /Workflow Board/ }).first(),
		).toBeVisible({ timeout: 15000 })
		// The seeded non-final column must exist, or there is nowhere for a
		// card to be grouped — assert it separately so a missing column does
		// not present as "the card is missing".
		await expect(column(page, RECEIVED)).toBeVisible({ timeout: 15000 })

		const card = column(page, RECEIVED)
			.locator('.case-card', { hasText: title })
			.first()
		await expect(card).toBeVisible({ timeout: 15000 })
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

		// THE CARD CARRIES NO MOVE CONTROL ANY MORE, and asserting its absence
		// is half of what this test is for: the tabbable NcActions it used to
		// hold listed every board column — one per status NAME across every
		// case type on the instance — so on a populated register it offered
		// two hundred statuses, nearly none of them reachable. A leftover
		// would mean two ways to move a card that disagree about what is on
		// offer.
		await expect(card.locator('.case-card__move-actions')).toHaveCount(0)

		// The keyboard path is now M on the focused card, which opens the move
		// dialog directly. It is announced in the card's accessible name,
		// because there is nothing on screen to find it by.
		await expect(card).toHaveAttribute('aria-label', /M to move/)
		await card.focus()
		await page.keyboard.press('m')

		const dialog = page.locator('[data-testid="move-case-dialog"]')
		await expect(dialog).toBeVisible({ timeout: 15000 })

		// The dropdown offers what the ENGINE offers for this case, so the
		// seeded target is there and the statuses of other workflows are not.
		const select = dialog.locator('[data-testid="move-case-select"]')
		await expect(select).toBeVisible()
		await select.click()
		const target = page.getByRole('option', {
			name: new RegExp(IN_PROGRESS),
		})
		await expect(target).toBeVisible({ timeout: 5000 })

		// Typed and chosen with the keyboard alone: no mouse event may take
		// part in the move itself. Typing is what the dropdown buys over the
		// list it replaced — the handler narrows instead of scrolling.
		await page.keyboard.type(IN_PROGRESS)
		await page.keyboard.press('Enter')

		const confirm = dialog.locator('[data-testid="move-case-confirm"]')
		await expect(confirm).toBeEnabled()
		await confirm.focus()
		await page.keyboard.press('Enter')

		// Opening the dialog must not also fire the card's own open-detail
		// handler: M is a keydown on the same element Enter opens the case on.
		await expect(page).toHaveURL(/\/workflow-board/)

		await expectStoredStatus(
			keyboardCaseId,
			sm.statusInProgress,
			'selecting "In behandeling" with Enter',
		)
		const moved = column(page, IN_PROGRESS).locator('.case-card', {
			hasText: KEYBOARD_TITLE,
		})
		await expect(
			moved,
			'the card must move to the "In behandeling" column',
		).toBeVisible({ timeout: 30_000 })
		await expect(
			column(page, RECEIVED).locator('.case-card', {
				hasText: KEYBOARD_TITLE,
			}),
		).toHaveCount(0)

		// And the card's existing keyboard activation is unaffected: Enter on
		// the card BODY still opens the case.
		await moved.focus()
		await page.keyboard.press('Enter')
		await expect(
			page,
			'Enter on the card body must still open the case detail',
		).toHaveURL(new RegExp(`/cases/${keyboardCaseId}`), { timeout: 30_000 })
	})

	// @e2e openspec/specs/dashboard/spec.md#scenario-dash-v1-006g-drag-path-unchanged-new
	test('dragging a card onto another column still moves the case', async ({
		page,
	}) => {
		const card = await openBoardAndFindSeededCard(page, DRAG_TITLE)

		// A pointer drag in steps, which is what a person does: press, move
		// past Sortable's fallback tolerance so the drag starts and the ghost
		// appears, cross to the target column, let go. Sortable finds the
		// column under the pointer with elementFromPoint, so the target has to
		// be on screen first: the board scrolls horizontally, one column per
		// non-final status on the instance.
		const target = column(page, IN_PROGRESS)
		await target.scrollIntoViewIfNeeded()
		const from = await card.boundingBox()
		const to = await target.boundingBox()
		if (!from || !to) {
			throw new Error('the card or the target column is not on screen')
		}
		const grabX = from.x + from.width / 2
		const grabY = from.y + from.height / 2
		await page.mouse.move(grabX, grabY)
		await page.mouse.down()
		await page.mouse.move(grabX + 12, grabY + 12, { steps: 4 })
		await page.mouse.move(to.x + to.width / 2, to.y + 80, { steps: 12 })
		await page.mouse.up()

		await expectStoredStatus(
			dragCaseId,
			sm.statusInProgress,
			'dropping the card on "In behandeling"',
		)
		await expect(
			column(page, IN_PROGRESS).locator('.case-card', { hasText: DRAG_TITLE }),
			'the dragged card must land in the "In behandeling" column',
		).toBeVisible({ timeout: 30_000 })
	})
})
