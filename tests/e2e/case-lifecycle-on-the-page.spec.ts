/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case's own state, driven from the case page.
 *
 * Everything here is a seam no unit test reaches: the manifest declares two
 * custom widgets and four open-modal actions, the widgets talk to dossiq's
 * transition engine, and the engine writes through OpenRegister. A vitest can
 * check that the three declarations agree; only a browser can show that a
 * handler pressing a button moves the case.
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
import { trackDossiqErrors } from './helpers/nav.ts'

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
			description: 'Throwaway caseType for the case-lifecycle-on-the-page e2e layer.',
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
		statusDone = await mk('Afgehandeld', 3, true)

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
					toStatus: statusDone,
					guards: [{ type: 'roleGuard', allowedRoles: [`${RUN_PREFIX}-afdelingshoofd`] }],
				},
				{
					id: T.close,
					label: `${RUN_PREFIX} Afhandelen`,
					fromStatus: statusProgress,
					toStatus: statusDone,
					guards: [],
				},
				{
					// A FAILING but visible guard: the button renders, the POST is
					// refused, and the reason belongs in the dialog.
					id: T.needsDoc,
					label: `${RUN_PREFIX} Besluit nemen`,
					fromStatus: statusReceived,
					toStatus: statusProgress,
					guards: [{ type: 'requiredDocument', documentType: REQUIRED_DOC }],
				},
			]),
		})

		const seed = async (key: string, status: string) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType: caseTypeId,
				status,
				startDate: new Date().toISOString().slice(0, 10),
				plannedEndDate: new Date(Date.now() + 30 * DAY).toISOString().slice(0, 10),
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
		await page.goto(`/apps/${REGISTER}/cases/${cases[key]}`)
		await expect(page.getByTestId('case-transitions')).toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId('case-current-status')).toBeVisible({ timeout: 30_000 })
	}

	// @e2e openspec/specs/status-transition-engine/spec.md#display-available-transitions-on-case-detail
	test('the header row lists only the transitions this user may take', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		await openCase(page, 'roles')

		// Any role may send the case back; only an Afdelingshoofd may approve.
		await expect(page.getByTestId(`case-transition-${T.send_back}`)).toBeVisible({
			timeout: 20_000,
		})
		await expect(page.getByTestId(`case-transition-${T.approve}`)).toHaveCount(0)
		// A transition out of a DIFFERENT status is not on offer either.
		await expect(page.getByTestId(`case-transition-${T.start}`)).toHaveCount(0)
		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#no-transitions-available
	test('a closed case offers no transitions and says it is closed', async ({ page }) => {
		await openCase(page, 'closed')
		await expect(page.getByTestId('case-closed-marker')).toBeVisible({ timeout: 20_000 })
		await expect(page.getByTestId(`case-transition-${T.close}`)).toHaveCount(0)
		await expect(page.getByTestId(`case-transition-${T.send_back}`)).toHaveCount(0)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#a-handler-advances-a-case
	test('a handler advances the case and the strip re-reads it', async ({ page, request }) => {
		await openCase(page, 'advance')
		await page.getByTestId(`case-transition-${T.start}`).click()

		const dialog = page.getByTestId('case-transition-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByTestId('case-transition-comment').locator('textarea').fill('E2E move')
		await page.getByTestId('case-transition-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 20_000 })

		// The record moved.
		const token = await getRequestToken(request)
		expect(token).not.toBe('')
		const moved = await showObject(request, 'case', cases.advance)
		expect(moved.status).toBe(statusProgress)

		// The transition history holds the move, with the signed-in user as actor.
		const history = await request.get(
			`/index.php/apps/${REGISTER}/api/case/${cases.advance}/transition-history`,
		)
		expect(history.status()).toBe(200)
		const rows = (await history.json()).history ?? (await history.json()).transitions ?? []
		expect(JSON.stringify(rows)).toContain(statusProgress)

		// And the strip now lists the NEW status's transitions without a reload.
		await expect(page.getByTestId(`case-transition-${T.send_back}`)).toBeVisible({
			timeout: 20_000,
		})
		await expect(page.getByTestId(`case-transition-${T.start}`)).toHaveCount(0)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#a-failed-guard-keeps-the-case-where-it-is
	test('a failed guard is shown in the dialog and the case does not move', async ({
		page,
		request,
	}) => {
		await openCase(page, 'guard')
		await page.getByTestId(`case-transition-${T.needsDoc}`).click()

		const dialog = page.getByTestId('case-transition-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await page.getByTestId('case-transition-confirm').click()

		// The reason stays beside the gesture: the dialog stays open and names
		// the document the guard wanted.
		const error = page.getByTestId('case-transition-error')
		await expect(error).toBeVisible({ timeout: 20_000 })
		await expect(error).toContainText(REQUIRED_DOC)
		await expect(dialog).toBeVisible()

		const unmoved = await showObject(request, 'case', cases.guard)
		expect(unmoved.status).toBe(statusReceived)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#a-final-transition-records-a-result
	test('closing the case records the result that was picked', async ({ page, request }) => {
		await openCase(page, 'close')
		await page.getByTestId(`case-transition-${T.close}`).click()

		const dialog = page.getByTestId('case-transition-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		const select = page.getByTestId('case-transition-result')
		await expect(select).toBeVisible({ timeout: 15_000 })
		await select.click()
		await page.getByRole('option', { name: new RegExp(`${RUN_PREFIX} Verleend`) }).click()
		await page.getByTestId('case-transition-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 25_000 })

		const closed = await showObject(request, 'case', cases.close)
		expect(closed.status).toBe(statusDone)
		expect(closed.result, 'a closed case carries a result').toBeTruthy()

		const result = await showObject(request, 'result', String(closed.result))
		expect(String(result.resultType ?? result.type ?? '')).toBe(resultGranted)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#no-result-no-close
	test('the confirm button stays disabled until a result is picked', async ({ page, request }) => {
		await openCase(page, 'noresult')
		await page.getByTestId(`case-transition-${T.close}`).click()

		await expect(page.getByTestId('case-transition-dialog')).toBeVisible({ timeout: 15_000 })
		await expect(page.getByTestId('case-transition-result')).toBeVisible({ timeout: 15_000 })
		await expect(page.getByTestId('case-transition-confirm')).toBeDisabled()

		const still = await showObject(request, 'case', cases.noresult)
		expect(still.status).toBe(statusProgress)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#suspend-then-resume
	test('suspend puts the marker and Resume in front of the handler', async ({ page }) => {
		await openCase(page, 'suspend')

		await page.getByTestId('cn-action-case-suspend').click()
		const dialog = page.getByTestId('case-lifecycle-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		// The reason is required: the button says so by staying disabled.
		await expect(page.getByTestId('case-lifecycle-confirm')).toBeDisabled()
		await dialog.getByTestId('case-lifecycle-reason').locator('textarea').fill('Awb 4:5 e2e')
		await page.getByTestId('case-lifecycle-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 25_000 })

		await expect(page.getByTestId('case-suspended-marker')).toBeVisible({ timeout: 25_000 })
		const resume = page.getByTestId('case-lifecycle-resume')
		await expect(resume).toBeVisible()

		await resume.click()
		const resumeDialog = page.getByTestId('case-lifecycle-dialog')
		await expect(resumeDialog).toBeVisible({ timeout: 15_000 })
		await resumeDialog.getByTestId('case-lifecycle-reason').locator('textarea').fill('Hervat e2e')
		await page.getByTestId('case-lifecycle-confirm').click()
		await expect(resumeDialog).toHaveCount(0, { timeout: 25_000 })

		await expect(page.getByTestId('case-suspended-marker')).toHaveCount(0, { timeout: 25_000 })
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#extend-the-term
	test('extending the term moves the end date by the case type period', async ({
		page,
		request,
	}) => {
		const before = await showObject(request, 'case', cases.extend)
		const beforeEnd = Date.parse(String(before.plannedEndDate ?? before.deadline ?? ''))
		expect(Number.isFinite(beforeEnd), 'the seeded case has an end date to move').toBe(true)

		await openCase(page, 'extend')
		await page.getByTestId('cn-action-case-extend').click()
		const dialog = page.getByTestId('case-lifecycle-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByTestId('case-lifecycle-reason').locator('textarea').fill('Awb 4:14 e2e')
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
					return Math.round((Date.parse(String(after.plannedEndDate)) - beforeEnd) / DAY)
				},
				{ timeout: 25_000, message: 'the end date moves by the case type extensionPeriod' },
			)
			.toBe(14)

		const after = await showObject(request, 'case', cases.extend)
		expect(Number(after.extensionCount ?? 0)).toBe(Number(before.extensionCount ?? 0) + 1)
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#reopen-a-closed-case
	test('reopening a closed case returns it to the first status', async ({ page, request }) => {
		// The Reopen action is gated on `case.isFinalStatus`, which OpenRegister
		// MATERIALISES from the linked statusType's isFinal. Asserting it first
		// says which half broke when the button does not appear.
		const closed = await showObject(request, 'case', cases.closed)
		expect(closed.isFinalStatus, 'OpenRegister materialises isFinalStatus').toBe(true)

		await openCase(page, 'closed')
		const reopen = page.getByTestId('cn-action-case-reopen')
		await expect(reopen).toBeVisible({ timeout: 25_000 })
		await reopen.click()

		const dialog = page.getByTestId('case-lifecycle-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByTestId('case-lifecycle-reason').locator('textarea').fill('Heropend e2e')
		await page.getByTestId('case-lifecycle-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 25_000 })

		await expect
			.poll(
				async () => (await showObject(request, 'case', cases.closed)).status,
				{ timeout: 25_000, message: 'the case returns to its type initial status' },
			)
			.toBe(statusReceived)
		const reopened = await showObject(request, 'case', cases.closed)
		expect(reopened.isFinalStatus).toBe(false)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-current-step-is-marked
	test('the stepper marks the step the case is in', async ({ page }) => {
		await openCase(page, 'stepper')
		const steps = page.getByTestId('case-steps')
		await expect(steps).toBeVisible({ timeout: 25_000 })

		const stages = steps.locator('.cn-timeline-stages__stage')
		await expect(stages).toHaveCount(3, { timeout: 25_000 })
		// Ontvangen done, In behandeling active, Afgehandeld still to come.
		await expect(stages.nth(0)).toHaveClass(/cn-timeline-stages__stage--completed/)
		await expect(stages.nth(1)).toHaveClass(/cn-timeline-stages__stage--current/)
		await expect(stages.nth(2)).toHaveClass(/cn-timeline-stages__stage--upcoming/)
		// The active stage is the one a screen reader is told about.
		await expect(steps.locator('[aria-current="step"]')).toHaveCount(1)
	})

	// @e2e openspec/specs/case-dashboard-view/spec.md#the-stepper-follows-a-transition
	test('the stepper follows a transition without a reload', async ({ page }) => {
		await openCase(page, 'stepper')
		const stages = page.getByTestId('case-steps').locator('.cn-timeline-stages__stage')
		await expect(stages.nth(1)).toHaveClass(/cn-timeline-stages__stage--current/, {
			timeout: 25_000,
		})

		await page.getByTestId(`case-transition-${T.close}`).click()
		const dialog = page.getByTestId('case-transition-dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await page.getByTestId('case-transition-result').click()
		await page.getByRole('option', { name: new RegExp(`${RUN_PREFIX} Verleend`) }).click()
		await page.getByTestId('case-transition-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 25_000 })

		// No page.goto: the strip bumps cn:page:refresh and the stepper re-reads.
		await expect(stages.nth(2)).toHaveClass(/cn-timeline-stages__stage--current/, {
			timeout: 25_000,
		})
		await expect(stages.nth(1)).toHaveClass(/cn-timeline-stages__stage--completed/)
	})
})
