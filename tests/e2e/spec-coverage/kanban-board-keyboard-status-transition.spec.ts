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
 * They used to stop short. The keyboard test opened the "Move to…" menu,
 * saw the target offered, and pressed Escape; the drag test read
 * `draggable="true"` off the card. Neither could fail when the move itself
 * broke: a menu item whose handler did nothing, or a drop handler that
 * ignored the card, left both green. Each test now owns its own card (one
 * move would otherwise change the column the other starts from), completes
 * the move, and asserts the case's STORED `status` is the target statusType
 * id, which is what both scenarios require.
 *
 * MUTATION POINTS, NOT YET RUN. The mutation runs for this file were refused
 * by the permission system on 2026-09-11, so each point below is where the
 * check goes, with the assertion that should redden. Both are client-side.
 *
 *  - Keyboard (006f): in `src/views/workflow-board/CaseCard.vue`, make the
 *    "Move to…" item a no-op (`@click="$emit('move', caseItem.id, col.id)"`
 *    becomes `@click="() => {}"`). Expected red: `selecting "In behandeling"
 *    with Enter must write the "In behandeling" statusType id to the stored
 *    case`. Dropping `@keydown.enter` from the card root instead should
 *    redden `Enter on the card body must still open the case detail`.
 *  - Drag (006g): in the same file, `onDragStart` writes the id under
 *    `text/plain`; write it under any other type and `BoardColumn.onDrop`
 *    reads nothing. Expected red: `dropping the card on "In behandeling" must
 *    write the "In behandeling" statusType id to the stored case`. That exact
 *    assertion was seen red on 2026-09-11 when the drop did not fire (the
 *    `dragTo()` attempt this file replaced), which shows it binds to an
 *    unpersisted move; it is not a substitute for the mutation.
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
		// behandeling with no guard. Two non-final statusTypes is the minimum
		// the move control needs: CaseCard renders its NcActions only when
		// `otherColumns.length > 0`, i.e. when a card has somewhere to go.
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

		// TAB to the control, as the scenario's keyboard-only user does. The
		// card body takes focus first; the move trigger is a separate focusable
		// NcActions control a few tab stops further on (the selection checkbox
		// sits between them).
		const moveTrigger = card.locator('.case-card__move-actions button').first()
		await expect(moveTrigger).toBeVisible()
		await card.focus()
		let reached = false
		for (let stop = 0; stop < 6 && !reached; stop++) {
			await page.keyboard.press('Tab')
			reached = await moveTrigger.evaluate(
				(button) => document.activeElement === button,
			)
		}
		expect(reached, 'Tab must reach the card\'s "Move to…" control').toBe(true)

		await page.keyboard.press('Enter')
		const target = page.getByRole('menuitem', {
			name: new RegExp(`Move to ${IN_PROGRESS}`),
		})
		await expect(target).toBeVisible({ timeout: 5000 })

		// Arrow to the target item rather than clicking it: no mouse event may
		// take part in this move. The menu lists every other board column, so
		// on a populated instance the target can sit several items down.
		let focused = false
		for (let step = 0; step < 60 && !focused; step++) {
			focused = await target.evaluate(
				(item) =>
					document.activeElement === item
					|| item.contains(document.activeElement),
			)
			if (!focused) {
				await page.keyboard.press('ArrowDown')
			}
		}
		expect(focused, 'ArrowDown must reach the "In behandeling" menu item').toBe(
			true,
		)
		await page.keyboard.press('Enter')

		// The move control stops propagation, so activating it must not also
		// fire the card's own open-detail handler.
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

		// The drag the scenario says must not regress, through the same
		// handlers a mouse user fires: the card's `dragstart` stashes its id on
		// the DataTransfer, the target column's `drop` reads it back and asks
		// the board to move the case. `draggable="true"` alone was what this
		// used to assert, and a card with a broken dragstart or a column with a
		// broken drop handler carries that attribute too.
		//
		// Dispatched rather than `dragTo()`. The board scrolls horizontally, one
		// column per non-final status on the instance, and a synthesised mouse
		// drag across that scroller dropped nothing when measured on 2026-09-11
		// while the keyboard move beside it went through. One DataTransfer is
		// shared across the events, as the browser shares it during a
		// real drag, so the id `dragstart` wrote is the id `drop` reads. No
		// `dragend` follows: by then the card has left the column `card` is
		// scoped to, and the board does nothing on it.
		await expect(card).toHaveAttribute('draggable', 'true')
		const target = column(page, IN_PROGRESS)
		const dataTransfer = await page.evaluateHandle(() => new DataTransfer())
		await card.dispatchEvent('dragstart', { dataTransfer })
		await target.dispatchEvent('dragover', { dataTransfer })
		await target.dispatchEvent('drop', { dataTransfer })

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
