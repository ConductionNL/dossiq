/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case's own state, driven from the case page.
 *
 * Everything here is a seam no unit test reaches: the manifest declares a
 * configured `stages` widget and four open-modal actions, the widget talks to
 * OpenRegister's lifecycle, dossiq answers that through CaseActionProvider off
 * its own transition engine, and the engine writes back through OpenRegister.
 * A vitest can check that the declarations agree; only a browser can show that
 * a handler clicking a stage moves the case.
 *
 * 🔴 THE SURFACE CHANGED AND SO DID THIS FIXTURE. There is no transition strip
 * any more: Ruben ruled on 2026-09-12 that clicking a status in the timeline
 * sets that status. A timeline is keyed on the STATUS a move leads to, not on
 * the move, and `actionsByTarget` keeps the FIRST action per target. dossiq's
 * workflow templates name transitions, and two of them may lead to the same
 * status, so a fixture that pointed a guarded move and an open move at one
 * status would show the open one and say nothing about the guard. That is a
 * real property of the new surface, not a test problem, and it is written up
 * in the PR that made this change. The fixture below gives every guarded or
 * role-filtered move its own target status, so the claim each scenario makes
 * is still the claim this file measures. It also makes the blocked case
 * visible at all: a refused move that shared a target with an open one would
 * be hidden behind the open one whatever the widget does with `blocked`.
 *
 * WHY THE FIXTURE BUILDS ITS OWN STATE MACHINE rather than calling
 * `seedStateMachine`: that helper's workflow carries no role guard, its case
 * type has no `suspensionAllowed` / `extensionAllowed` / `extensionPeriod` /
 * `initialStatus`, and it seeds no result types. Five of the ten scenarios
 * below turn on exactly those fields, so the machine is built here and the
 * shared helper is left alone for the specs that want the simpler one.
 *
 * ASSERT IDS, NOT LABELS. Nothing forces the language of the E2E instance, so
 * every locator is a `data-testid` or a role, and the only text asserted is
 * text this fixture itself seeded (which carries RUN_PREFIX and is therefore
 * the same in either locale).
 *
 * WHERE A `data-testid` ON AN NcTextArea LANDS: on the `<textarea>` itself,
 * not on a wrapper. The component sets `inheritAttrs: false` and merges
 * `$attrs` onto the control, so `getByTestId(id)` IS the field and reaching
 * for a `textarea` under it searches inside an element that has no children.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import {
	clickHeaderAction,
	openHeaderActionsMenu,
	PAGE_LOAD,
	trackDossiqErrors,
} from './helpers/nav.ts'

/** A day in milliseconds, for the extension arithmetic. */
const DAY = 24 * 60 * 60 * 1000

/** Transition ids the seeded workflow template declares. */
const T = {
	start: 'lc-start',
	send_back: 'lc-send-back',
	approve: 'lc-approve',
	close: 'lc-close',
	needsDoc: 'lc-needs-doc',
}

/** The document type the guarded transition asks for and no case has. */
const REQUIRED_DOC = `${RUN_PREFIX}-besluitnota`

let statusReceived = ''
let statusProgress = ''
let statusHeld = ''
let statusDone = ''
let caseTypeId = ''
let resultGranted = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

test.describe('Case lifecycle on the case page', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		const caseType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Lifecycle`,
			identifier: `${RUN_PREFIX.toLowerCase()}-lifecycle`,
			description:
				'Throwaway caseType for the case-lifecycle-on-the-page e2e layer.',
			processingDeadline: 'P30D',
			suspensionAllowed: true,
			extensionAllowed: true,
			extensionPeriod: 'P14D',
		})
		caseTypeId = objectId(caseType)

		const mk = async (name: string, order: number, isFinal: boolean) =>
			objectId(
				await createObject(api, token, 'statusType', {
					name: `${RUN_PREFIX} ${name}`,
					caseType: caseTypeId,
					order,
					isFinal,
				}),
			)
		statusReceived = await mk('Ontvangen', 1, false)
		statusProgress = await mk('In behandeling', 2, false)
		// The target the two FILTERED moves lead to, and nothing else does. A
		// stage is disabled exactly when no action reaches it, so a guarded or
		// role-hidden move only shows as a refusal when it owns its target.
		statusHeld = await mk('Aangehouden', 3, false)
		statusDone = await mk('Afgehandeld', 4, true)

		// The initial status can only be named once the statuses exist, and
		// reopen refuses without it (`initial_status_not_configured`).
		await updateObject(api, token, 'caseType', caseTypeId, {
			initialStatus: statusReceived,
		})

		resultGranted = objectId(
			await createObject(api, token, 'resultType', {
				name: `${RUN_PREFIX} Verleend`,
				caseType: caseTypeId,
			}),
		)
		await createObject(api, token, 'resultType', {
			name: `${RUN_PREFIX} Geweigerd`,
			caseType: caseTypeId,
		})

		await createObject(api, token, 'workflowTemplate', {
			title: `${RUN_PREFIX} Lifecycle workflow`,
			caseType: caseTypeId,
			isActive: true,
			isDraft: false,
			version: 1,
			transitions: JSON.stringify([
				{
					id: T.start,
					label: `${RUN_PREFIX} Start behandeling`,
					fromStatus: statusReceived,
					toStatus: statusProgress,
					guards: [],
				},
				{
					id: T.send_back,
					label: `${RUN_PREFIX} Terugsturen`,
					fromStatus: statusProgress,
					toStatus: statusReceived,
					guards: [],
				},
				{
					// A roleGuard mismatch is SILENT: the engine drops the
					// transition from the list rather than offering a button that
					// refuses. Playwright signs in as admin, who is in no group
					// named after this role.
					id: T.approve,
					label: `${RUN_PREFIX} Goedkeuren`,
					fromStatus: statusProgress,
					toStatus: statusHeld,
					guards: [
						{
							type: 'roleGuard',
							allowedRoles: [`${RUN_PREFIX}-afdelingshoofd`],
						},
					],
				},
				{
					id: T.close,
					label: `${RUN_PREFIX} Afhandelen`,
					fromStatus: statusProgress,
					toStatus: statusDone,
					guards: [],
				},
				{
					// A FAILING but visible guard: the stage renders, disabled,
					// with the guard's own sentence beside it. It is published
					// with `blocked: true` by CaseActionProvider, so the widget
					// never has to decide; a stage nothing reaches is disabled
					// by construction.
					id: T.needsDoc,
					label: `${RUN_PREFIX} Besluit nemen`,
					fromStatus: statusReceived,
					toStatus: statusHeld,
					guards: [
						{ type: 'requiredDocument', documentType: REQUIRED_DOC },
					],
				},
			]),
		})

		const seed = async (key: string, status: string) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType: caseTypeId,
				status,
				startDate: new Date().toISOString().slice(0, 10),
				plannedEndDate: new Date(Date.now() + 30 * DAY)
					.toISOString()
					.slice(0, 10),
			})
			cases[key] = objectId(row)
		}
		await seed('advance', statusReceived)
		await seed('guard', statusReceived)
		await seed('stepper', statusProgress)
		await seed('roles', statusProgress)
		await seed('close', statusProgress)
		await seed('noresult', statusProgress)
		await seed('closed', statusDone)
		await seed('suspend', statusReceived)
		await seed('extend', statusReceived)

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open one case and wait for the strip to have answered.
	 *
	 * @param page The Playwright page.
	 * @param key  Which seeded case to open.
	 */
	const openCase = async (page: any, key: string) => {
		await page.goto(`/apps/${REGISTER}/cases/${cases[key]}`, PAGE_LOAD)
		await expect(page.getByTestId('cn-stages-widget')).toBeVisible({
			timeout: 30_000,
		})
		// The status pill in the identity row. It is the library's own badge
		// inside a configured `stat` tile, so it carries the library testid.
		await expect(page.getByTestId('cn-stat-widget-badge')).toBeVisible({
			timeout: 30_000,
		})
	}

	/**
	 * One stage of the timeline, by the status it leads to.
	 *
	 * The timeline is keyed on the STATUS, which is what makes clicking it the
	 * gesture: a transition id would be a name for a move, and a person reads
	 * the place they are moving the case to.
	 *
	 * @param page The Playwright page.
	 * @param statusId The statusType uuid.
	 */
	const stage = (page: any, statusId: string) =>
		page.getByTestId(`cn-stages-widget-stage-${statusId}`)

	/**
	 * What the timeline says under one stage: the move's own note, or the
	 * guard's refusal.
	 *
	 * @param page The Playwright page.
	 * @param statusId The statusType uuid.
	 */
	const stageReason = (page: any, statusId: string) =>
		page.getByTestId(`cn-stages-widget-reason-${statusId}`)

	/**
	 * The clickable element of one stage, which is the list item, not the
	 * label. CnTimelineStages puts the role and the click handler on the
	 * `<li>`; the testid above is on the label inside it.
	 *
	 * @param page The Playwright page.
	 * @param statusId The statusType uuid.
	 */
	const stageControl = (page: any, statusId: string) =>
		page.locator('.cn-timeline-stages__stage').filter({
			has: page.getByTestId(`cn-stages-widget-stage-${statusId}`),
		})

	// @e2e openspec/specs/status-transition-engine/spec.md#display-available-transitions-on-case-detail
	test('the timeline offers only the moves this user may make', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		await openCase(page, 'roles')

		// EVERY stage renders, because the timeline is the process and not the
		// list of next steps. What changes is which of them can be chosen.
		await expect(stage(page, statusReceived)).toBeVisible({ timeout: 20_000 })
		await expect(stage(page, statusHeld)).toBeVisible()

		// Any role may send the case back, so that stage is live.
		await expect(stageControl(page, statusReceived)).not.toHaveAttribute(
			'aria-disabled',
			'true',
		)
		// Only an Afdelingshoofd may approve, and Playwright signs in as admin,
		// who is in no group named after that role. The engine DROPS a
		// role-hidden transition rather than publishing it blocked, so nothing
		// reaches this stage and it is disabled with the configured reason.
		await expect(stageControl(page, statusHeld)).toHaveAttribute(
			'aria-disabled',
			'true',
		)
		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#no-transitions-available
	test('a closed case offers no move at all', async ({ page }) => {
		await openCase(page, 'closed')

		// The timeline still draws the whole process, with the final stage
		// current. Nothing on it can be chosen, because the engine offers no
		// transition out of a terminal status and a stage no action reaches is
		// disabled by construction.
		await expect(stage(page, statusDone)).toBeVisible({ timeout: 20_000 })
		for (const target of [statusReceived, statusProgress, statusHeld]) {
			await expect(stageControl(page, target)).toHaveAttribute(
				'aria-disabled',
				'true',
			)
		}

		// 🔴 THE CLOSED MARKER IS GONE, and this assertion is what says so on
		// purpose rather than by omission. The strip printed "This case is
		// closed" beside its buttons; the timeline says it by putting the case
		// on a final stage with nowhere to go. Nothing else on the page prints
		// that sentence, so asserting its absence keeps a second one from
		// quietly reappearing.
		await expect(page.getByTestId('case-closed-marker')).toHaveCount(0)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#a-handler-advances-a-case
	test('a handler advances the case by clicking the next stage', async ({
		page,
		request,
	}) => {
		await openCase(page, 'advance')

		// One click and the move is taken. A transition that declares no
		// `inputs` asks nothing first: the strip used to open a dialog for a
		// comment on every move, and the comment field went with it.
		await stageControl(page, statusProgress).click()

		// The record moved.
		const token = await getRequestToken(request)
		expect(token).not.toBe('')
		const moved = await showObject(request, 'case', cases.advance)
		expect(moved.status).toBe(statusProgress)

		// The transition history holds the move, with the signed-in user as actor.
		// `StatusTransitionController::history()` carries `@NoAdminRequired` and
		// NOT `@NoCSRFRequired`, so the token harvested above is not decoration:
		// without it Nextcloud's CSRF guard answers this GET with 412 before the
		// controller runs, and the body still parses as JSON.
		const history = await request.get(
			`/index.php/apps/${REGISTER}/api/case/${cases.advance}/transition-history`,
			{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
		)
		expect(history.status()).toBe(200)
		const rows =
			(await history.json()).history
			?? (await history.json()).transitions
			?? []
		expect(JSON.stringify(rows)).toContain(statusProgress)

		// And the timeline shows the NEW stage as current without a reload: the
		// widget moves its own marker and fires cn:page:refresh, then re-reads
		// which stages the case can reach from where it now is.
		await expect(stageControl(page, statusProgress)).toHaveAttribute(
			'aria-current',
			'step',
			{ timeout: 20_000 },
		)
		// Send back is offered from the new status, and was not from the old.
		await expect(stageControl(page, statusReceived)).not.toHaveAttribute(
			'aria-disabled',
			'true',
			{ timeout: 20_000 },
		)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#required-document-guard-evaluation
	test('a failed guard disables the button and names what is missing', async ({
		page,
		request,
	}) => {
		// 🔴 THE REFUSAL MOVED AHEAD OF THE POST, AND THE SPEC ALWAYS ASKED FOR
		// THAT. `Scenario: Required document guard evaluation` reads "the
		// transition button SHALL be disabled" and "SHALL display: Vereist
		// document ontbreekt: Besluit". This test used to click the button,
		// confirm the dialog and read the error out of the failed response,
		// which is the behaviour the spec did NOT describe.
		//
		// It also anchored at `#a-failed-guard-keeps-the-case-where-it-is`,
		// which matches no scenario in that spec, so the anchor pointed at
		// nothing while looking like coverage.
		await openCase(page, 'guard')

		// The guarded move leads to Aangehouden and nothing else does, so the
		// stage carries that move's verdict rather than a neighbour's.
		await expect(stage(page, statusHeld)).toBeVisible({ timeout: 15_000 })

		// The reason stays beside the gesture, which is the point of putting
		// the gesture on the stage. CaseActionProvider publishes the guard's
		// own `failureMessage` as the action's `description`, and the widget
		// prints an action's description under its stage.
		const reason = stageReason(page, statusHeld)
		await expect(reason).toBeVisible({ timeout: 15_000 })
		await expect(reason).toContainText(REQUIRED_DOC)

		// THE STAGE IS DISABLED, BEFORE THE CLICK. CaseActionProvider publishes
		// the refused move with `blocked: true`, and @conduction/nextcloud-vue
		// 2.50's `stageAccess()` reads that flag, so the refusal is drawn
		// rather than met. 2.49 did not, and this assertion is the difference:
		// a refused move used to render enabled and answer with an error after
		// the post was attempted.
		await expect(stageControl(page, statusHeld)).toHaveAttribute(
			'aria-disabled',
			'true',
		)

		// And a click on it SAYS something rather than doing nothing. A
		// disabled control that answers silence reads as broken, which is why
		// CnTimelineStages emits `stageBlocked` for a disabled stage instead of
		// swallowing the click.
		await stageControl(page, statusHeld).click()
		const blocked = page.getByTestId('cn-stages-widget-blocked')
		await expect(blocked).toBeVisible({ timeout: 20_000 })
		await expect(blocked).toContainText(REQUIRED_DOC)

		// And the case has not moved, which is what the refusal is FOR. A
		// disabled stage that posted anyway would pass every assertion above.
		// `onStageClick` refuses a blocked move as well, so no path through the
		// widget reaches the POST, and OpenRegister re-validates it regardless.
		const unmoved = await showObject(request, 'case', cases.guard)
		expect(unmoved.status).toBe(statusReceived)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#a-final-transition-records-a-result
	test('closing the case records the result that was picked', async ({
		page,
		request,
	}) => {
		await openCase(page, 'close')
		await stageControl(page, statusDone).click()

		// A closing move declares `resultTypeId` as a required input, which
		// CaseActionProvider publishes, so the library's shared transition
		// dialog asks for it before it posts.
		//
		// ⚠️ IT ASKS AS A TEXT BOX, NOT AS A PICKER. CnTransitionInputDialog
		// derives each input's widget from the RECORD'S schema, and
		// `resultTypeId` is not a property of `case`: it is an argument to the
		// move. So an undeclared field falls back to a plain text input and a
		// handler is asked to type a uuid, where the strip offered the case
		// type's result types in a select. That is the sharpest thing this
		// change gives up, and it is written into the PR body rather than left
		// for the next reader to find.
		const dialog = page.getByTestId('cn-transition-input-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog
			.getByTestId('cn-transition-input-resultTypeId')
			.getByRole('textbox')
			.fill(resultGranted)
		await page.getByTestId('cn-transition-input-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 25_000 })

		const closed = await showObject(request, 'case', cases.close)
		expect(closed.status).toBe(statusDone)
		expect(closed.result, 'a closed case carries a result').toBeTruthy()

		const result = await showObject(request, 'result', String(closed.result))
		expect(String(result.resultType ?? result.type ?? '')).toBe(resultGranted)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#no-result-no-close
	test('the confirm button stays disabled until a result is picked', async ({
		page,
		request,
	}) => {
		await openCase(page, 'noresult')
		await stageControl(page, statusDone).click()

		await expect(page.getByTestId('cn-transition-input-dialog')).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			page.getByTestId('cn-transition-input-resultTypeId'),
		).toBeVisible({ timeout: 15_000 })
		// The input is declared `required: true`, so the dialog's own confirm
		// stays disabled. The question is asked before the post rather than the
		// refusal being met after it.
		await expect(page.getByTestId('cn-transition-input-confirm')).toBeDisabled()

		const still = await showObject(request, 'case', cases.noresult)
		expect(still.status).toBe(statusProgress)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#suspend-then-resume
	test('a case can be suspended and resumed from the Actions menu', async ({
		page,
		request,
	}) => {
		// 🔴 THE SUSPENDED MARKER IS GONE FROM THE PAGE, and this test says so
		// rather than quietly asserting less. The transition strip carried it,
		// and the strip went with Ruben's 2026-09-12 ruling. It cannot be a
		// configured tile: a `stat` tile's `overrides` test the LOADED RECORD,
		// and suspension is derived from the case's `activity` journal by
		// GET /api/case/{id}/lifecycle, so no property of the case says it.
		//
		// The GESTURE still has a home, which is what this scenario is about:
		// `case-resume` is a header action, and CaseLifecycleActionDialog reads
		// /lifecycle before it posts, so Resume on a case that is not suspended
		// is refused with a sentence. What is lost is the marker and the second
		// Resume button that sat in front of the handler; the state is asserted
		// against the endpoint here, because that is now the only place it is
		// visible at all.
		await openCase(page, 'suspend')

		await clickHeaderAction(page, 'cn-action-case-suspend')
		const dialog = page.getByTestId('case-lifecycle-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		// The reason is required: the button says so by staying disabled.
		await expect(page.getByTestId('case-lifecycle-confirm')).toBeDisabled()
		await dialog.getByTestId('case-lifecycle-reason').fill('Awb 4:5 e2e')
		await page.getByTestId('case-lifecycle-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 25_000 })

		/**
		 * What the case's own lifecycle endpoint says about it.
		 *
		 * @return The `suspended` flag, or null when the read failed.
		 */
		const suspended = async () => {
			const res = await request.get(
				`/index.php/apps/${REGISTER}/api/case/${cases.suspend}/lifecycle`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			if (res.status() !== 200) {
				return null
			}
			return (await res.json()).suspended
		}

		await expect
			.poll(suspended, {
				timeout: 25_000,
				message: 'the case reads as suspended after the gesture',
			})
			.toBe(true)

		// And no marker anywhere on the page. Asserting the absence keeps a
		// second one from reappearing without the reasoning above being read.
		await expect(page.getByTestId('case-suspended-marker')).toHaveCount(0)

		await clickHeaderAction(page, 'cn-action-case-resume')
		const resumeDialog = page.getByTestId('case-lifecycle-dialog')
		await expect(resumeDialog).toBeVisible({ timeout: 15_000 })
		await resumeDialog.getByTestId('case-lifecycle-reason').fill('Hervat e2e')
		await page.getByTestId('case-lifecycle-confirm').click()
		await expect(resumeDialog).toHaveCount(0, { timeout: 25_000 })

		await expect
			.poll(suspended, {
				timeout: 25_000,
				message: 'the case reads as running again',
			})
			.toBe(false)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#extend-the-term
	test('extending the term moves the end date by the case type period', async ({
		page,
		request,
	}) => {
		const before = await showObject(request, 'case', cases.extend)
		const beforeEnd = Date.parse(
			String(before.plannedEndDate ?? before.deadline ?? ''),
		)
		expect(
			Number.isFinite(beforeEnd),
			'the seeded case has an end date to move',
		).toBe(true)

		await openCase(page, 'extend')
		await clickHeaderAction(page, 'cn-action-case-extend')
		const dialog = page.getByTestId('case-lifecycle-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByTestId('case-lifecycle-reason').fill('Awb 4:14 e2e')
		await page.getByTestId('case-lifecycle-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 25_000 })

		// `plannedEndDate` is the field the extension writes. `deadline` is
		// COMPUTED by OpenRegister from the case type's duration and does not
		// move with a per-case extension, so asserting it would assert the
		// wrong number.
		await expect
			.poll(
				async () => {
					const after = await showObject(request, 'case', cases.extend)
					return Math.round(
						(Date.parse(String(after.plannedEndDate)) - beforeEnd) / DAY,
					)
				},
				{
					timeout: 25_000,
					message: 'the end date moves by the case type extensionPeriod',
				},
			)
			.toBe(14)

		const after = await showObject(request, 'case', cases.extend)
		expect(Number(after.extensionCount ?? 0)).toBe(
			Number(before.extensionCount ?? 0) + 1,
		)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#reopen-a-closed-case
	test('reopening a closed case returns it to the first status', async ({
		page,
		request,
	}) => {
		// The Reopen action is gated on `case.isFinalStatus`, which OpenRegister
		// MATERIALISES from the linked statusType's isFinal. Asserting it first
		// says which half broke when the button does not appear.
		const closed = await showObject(request, 'case', cases.closed)
		expect(closed.isFinalStatus, 'OpenRegister materialises isFinalStatus').toBe(
			true,
		)

		await openCase(page, 'closed')
		// Opened explicitly rather than through clickHeaderAction, because what
		// this test is about is that Reopen is OFFERED on a closed case: the
		// visibility assertion has to be its own step, and an entry in a shut
		// menu is absent whether the gate let it through or not.
		await openHeaderActionsMenu(page)
		const reopen = page.getByTestId('cn-action-case-reopen')
		await expect(reopen).toBeVisible({ timeout: 25_000 })
		await reopen.click()

		const dialog = page.getByTestId('case-lifecycle-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByTestId('case-lifecycle-reason').fill('Heropend e2e')
		await page.getByTestId('case-lifecycle-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 25_000 })

		await expect
			.poll(
				async () => (await showObject(request, 'case', cases.closed)).status,
				{
					timeout: 25_000,
					message: 'the case returns to its type initial status',
				},
			)
			.toBe(statusReceived)
		const reopened = await showObject(request, 'case', cases.closed)
		expect(reopened.isFinalStatus).toBe(false)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-current-step-is-marked
	test('the timeline marks the step the case is in', async ({ page }) => {
		await openCase(page, 'stepper')
		const widget = page.getByTestId('cn-stages-widget')
		await expect(widget).toBeVisible({ timeout: 25_000 })

		const stages = widget.locator('.cn-timeline-stages__stage')
		// Four, in the case type's `order`: the stage list is the PROCESS, not
		// the moves on offer, which is why a role-hidden target still renders.
		await expect(stages).toHaveCount(4, { timeout: 25_000 })
		await expect(stages.nth(0)).toHaveClass(
			/cn-timeline-stages__stage--completed/,
		)
		await expect(stages.nth(1)).toHaveClass(/cn-timeline-stages__stage--current/)
		await expect(stages.nth(2)).toHaveClass(
			/cn-timeline-stages__stage--upcoming/,
		)
		await expect(stages.nth(3)).toHaveClass(
			/cn-timeline-stages__stage--upcoming/,
		)
		// The active stage is the one a screen reader is told about.
		await expect(widget.locator('[aria-current="step"]')).toHaveCount(1)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-stepper-follows-a-transition
	test('the timeline follows its own move without a reload', async ({ page }) => {
		await openCase(page, 'stepper')
		const stages = page
			.getByTestId('cn-stages-widget')
			.locator('.cn-timeline-stages__stage')
		await expect(stages.nth(1)).toHaveClass(
			/cn-timeline-stages__stage--current/,
			{
				timeout: 25_000,
			},
		)

		await stageControl(page, statusDone).click()
		const dialog = page.getByTestId('cn-transition-input-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog
			.getByTestId('cn-transition-input-resultTypeId')
			.getByRole('textbox')
			.fill(resultGranted)
		await page.getByTestId('cn-transition-input-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 25_000 })

		// No page.goto. The widget shows the new stage at once, fires
		// cn:page:refresh so the page re-reads the case, and re-reads which
		// stages the case can reach from where it now is.
		await expect(stages.nth(3)).toHaveClass(
			/cn-timeline-stages__stage--current/,
			{
				timeout: 25_000,
			},
		)
		await expect(stages.nth(1)).toHaveClass(
			/cn-timeline-stages__stage--completed/,
		)
	})
})
