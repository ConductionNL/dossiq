/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * enforcement-intervention-from-the-case task 4.1: the three scenarios of
 * REQ-LHS-CASE-01 (a handler asks the case type's decision table for advice on
 * the case).
 *
 * The surface is generic: the header action `advice-from-decision-table`
 * (visible when `@context.caseType.adviceDecisionTable` is set) opens
 * `DecisionTableAdviceDialog`, which reads
 * `GET /api/decision-advice/case/{caseId}/form`, asks
 * `POST /api/decision-advice/recommend` and records the choice with
 * `POST /api/decision-advice/override`. The choice is a `decisionAdvice`
 * object linked from the case and listed on the Decisions tab's
 * `case-decision-advice` widget.
 *
 * THE TABLE AS SHIPPED. The scenario names severity "ernstig", behaviour
 * "calculerend" and actor type "bedrijf". The table the `lhs-intervention`
 * package seeds under `dossiq.lhs-intervention` declares two inputs instead,
 * `behaviour` (LHS row A to D) and `consequence` (column 1 to 4), so the
 * scenario's case is entered as calculerend = C and ernstig = 3, and the
 * expected recommendation is read from the seeded rule C:3 rather than typed
 * here. Actor type is not an input of that table.
 *
 * The `handhavingszaak` case type comes from the `vth` package (install auto)
 * and names that table. An instance without it, or whose OpenRegister has no
 * decision-tables register, is skipped with the reason.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here; the nightly live pass owns it.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { openCaseTab } from './helpers/case-panels.ts'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
	trackCreatedObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction, dismissSupportDialog, openHeaderActionsMenu, PAGE_LOAD } from './helpers/nav.ts'

/** The repository root. */
const ROOT = path.join(__dirname, '..', '..')

/** The enforcement case type the vth package ships with the table named. */
const ENFORCEMENT_TYPE = (
	JSON.parse(readFileSync(path.join(ROOT, 'lib/Settings/packages/vth/case-type.json'), 'utf8')).caseType as any[]
).find((row) => row.identifier === 'handhavingszaak')

/** The intervention table the lhs-intervention package seeds. */
const TABLE = JSON.parse(
	readFileSync(path.join(ROOT, 'lib/Settings/packages/lhs-intervention/seed/table.json'), 'utf8'),
).rows[0]

/** The scenario's case: calculerend behaviour (row C), ernstig consequence (column 3). */
const INPUTS: Record<string, string> = { behaviour: 'C', consequence: '3' }

/** What the table recommends for it: rule C:3's first output. */
const RECOMMENDED: string = (TABLE.rules as any[]).find((rule) => rule.id === 'C:3').outputEntries[0]

/** Another outcome the table can give, for the deviation. */
const OTHER: string = (TABLE.rules as any[])
	.map((rule) => String(rule.outputEntries[0]))
	.find((outcome) => outcome !== RECOMMENDED) as string

/** The refusal of a deviation without a reason, as the server words it. */
const REASON_REQUIRED = 'Geef een reden voor de afwijking'

/** The header action's entry in the case page's Actions menu. */
const ACTION = 'cn-action-advice-from-decision-table'

/** dossiq's advice routes and settings write. */
const ADVICE = `/index.php/apps/${REGISTER}/api/decision-advice`
const SETTINGS_API = `/index.php/apps/${REGISTER}/api/settings`

let api: APIRequestContext
let token = ''
let skipReason = ''
let menuBefore = ''

/**
 * Headers for a CSRF-protected dossiq write.
 *
 * @return The headers.
 */
function writeHeaders(): Record<string, string> {
	return { requesttoken: token, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
}

/**
 * Save the app's menu structure.
 *
 * @param value `simple` or `full`.
 */
async function saveMenuStructure(value: string): Promise<void> {
	const saved = await api.post(SETTINGS_API, {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
		data: { menu_structure: value },
	})
	expect(saved.status(), `POST /api/settings menu_structure=${value}`).toBe(200)
}

/**
 * Seed a handhavingszaak.
 *
 * @param label A label after the run prefix.
 * @return The case id.
 */
async function enforcementCase(label: string): Promise<string> {
	return objectId(
		await seedCase(api, token, {
			title: `${RUN_PREFIX} handhaving ${label}`,
			caseType: String(ENFORCEMENT_TYPE.id),
		}),
	)
}

/**
 * Ask the table for advice on a case through the route the dialog uses.
 *
 * @param caseId The case.
 * @return The stored advice.
 */
async function recommend(caseId: string): Promise<any> {
	const res = await api.post(`${ADVICE}/recommend`, { headers: writeHeaders(), data: { caseId, inputs: INPUTS } })
	const body = await res.json().catch(() => ({}))
	expect(res.status(), `recommend -> ${JSON.stringify(body)}`).toBe(200)
	trackCreatedObject('decisionAdvice', objectId(body))
	return body
}

/**
 * Pick an option in one of the dialog's selects, by text.
 *
 * @param page   The page.
 * @param select The NcSelect.
 * @param text   The option's text, whole.
 */
async function choose(page: Page, select: Locator, text: string): Promise<void> {
	await select.getByRole('combobox').click()
	const escaped = text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	await page.getByRole('option').filter({ hasText: new RegExp(`^\\s*${escaped}\\s*$`) }).first().click()
}

/**
 * Open a case, open the advice dialog, fill the inputs and ask.
 *
 * @param page   The page.
 * @param caseId The case.
 * @return The dialog, showing the recommendation.
 */
async function askOnThePage(page: Page, caseId: string): Promise<Locator> {
	await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
	await dismissSupportDialog(page)
	await clickHeaderAction(page, ACTION)

	const dialog = page.getByTestId('decision-advice-dialog')
	await expect(dialog).toBeVisible({ timeout: 20_000 })
	for (const [name, value] of Object.entries(INPUTS)) {
		await choose(page, dialog.getByTestId(`decision-advice-input-${name}`), value)
	}
	await dialog.getByTestId('decision-advice-ask').click()
	await expect(dialog.getByTestId('decision-advice-recommendation')).toContainText(RECOMMENDED, { timeout: 20_000 })

	return dialog
}

test.describe("Advice from the case type's decision table", () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		expect(ENFORCEMENT_TYPE?.adviceDecisionTable, 'the vth package names the table on handhavingszaak').toBe(TABLE.key)
		expect(RECOMMENDED, 'the seeded table has rule C:3').toBeTruthy()
		expect(OTHER, 'and another outcome to deviate to').toBeTruthy()

		const type = await api.get(`/index.php/apps/openregister/api/objects/${REGISTER}/caseType/${ENFORCEMENT_TYPE.id}`)
		if (type.status() !== 200) {
			skipReason = 'this instance carries no handhavingszaak case type; the vth package has not installed here'
			return
		}

		// Probe the table once on a throwaway case: an OpenRegister without the
		// decision-tables register refuses the lhs-intervention package, and the
		// form then answers 409 naming the table it could not find.
		const probe = await api.get(`${ADVICE}/case/${await enforcementCase('probe')}/form`)
		const form: any = await probe.json().catch(() => ({}))
		if (probe.status() === 409) {
			skipReason = `the decision table is not on this instance: ${form.error ?? probe.status()}`
			return
		}
		expect(probe.status(), JSON.stringify(form)).toBe(200)
		expect(form.available).toBe(true)
		expect((form.inputs as any[]).map((input) => input.name), 'the form asks what the table declares').toEqual(
			(TABLE.inputs as any[]).map((input) => input.name),
		)

		// The advice widget is on the full profile's Decisions tab.
		const current = await api.get(SETTINGS_API, { headers: { 'OCS-APIRequest': 'true' } })
		const body = await current.json()
		menuBefore = String((body.config ?? body).menu_structure ?? '')
		await saveMenuStructure('full')
	})

	test.afterAll(async () => {
		if (menuBefore !== '') {
			await saveMenuStructure(menuBefore === 'full' ? 'full' : 'simple')
		}
		await cleanupRunObjects(api, token, ['decisionAdvice', 'case', 'caseType'])
		await api?.dispose()
	})

	test.beforeEach(() => {
		test.skip(skipReason !== '', skipReason)
	})

	// @e2e openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#scenario-the-handler-accepts-the-recommended-intervention
	test('the handler accepts the recommended intervention and the case lists it', async ({ page }) => {
		const caseId = await enforcementCase('accept')
		const dialog = await askOnThePage(page, caseId)

		const save = dialog.getByTestId('decision-advice-save')
		await expect(save).toHaveText(/Accept the advice/)
		await save.click()
		await expect(dialog.getByTestId('decision-advice-saved')).toBeVisible({ timeout: 20_000 })

		const stored = await listObjects(api, 'decisionAdvice', { case: caseId })
		stored.forEach((row: any) => trackCreatedObject('decisionAdvice', objectId(row)))
		expect(stored, 'one advice is stored, linked from the case').toHaveLength(1)
		const advice = stored[0]
		expect(advice.case).toBe(caseId)
		expect(advice.decisionTable).toBe(TABLE.key)
		expect(advice.recommendation).toBe(RECOMMENDED)
		expect(advice.outcome, 'the outcome is the recommendation').toBe(RECOMMENDED)
		expect(advice.accepted).toBe(true)
		expect(String(advice.decidedBy ?? ''), 'who decided').not.toBe('')
		expect(String(advice.decidedAt ?? ''), 'and when').not.toBe('')

		await dialog.getByTestId('decision-advice-close').click()
		await openCaseTab(page, /^(Decisions|Besluiten)\b/)
		const widget = page
			.locator('section, [role="region"], .cn-tabs__panel, [role="tabpanel"]')
			.filter({ hasText: 'Advice from a decision table' })
			.last()
		await expect(
			widget.locator('tr, [role="row"], li').filter({ hasText: RECOMMENDED }).first(),
			"the case's decisions widget lists the advice",
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#scenario-an-override-needs-a-reason
	test('the server refuses a deviation without a reason and records nothing', async () => {
		const caseId = await enforcementCase('override api')
		const advice = await recommend(caseId)
		expect(advice.recommendation).toBe(RECOMMENDED)

		const res = await api.post(`${ADVICE}/override`, {
			headers: writeHeaders(),
			data: { adviceId: objectId(advice), outcome: OTHER, reason: '' },
		})
		const body: any = await res.json().catch(() => ({}))
		expect(res.status(), JSON.stringify(body)).toBe(400)
		expect(body.error).toBe(REASON_REQUIRED)

		const after = await showObject(api, 'decisionAdvice', objectId(advice))
		expect(String(after.outcome ?? ''), 'the refused choice was not stored').toBe('')
	})

	// @e2e openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#scenario-an-override-needs-a-reason
	test('the dialog shows the refusal when the handler deviates without a reason', async ({ page }) => {
		const caseId = await enforcementCase('override page')
		const dialog = await askOnThePage(page, caseId)

		await choose(page, dialog.getByTestId('decision-advice-outcome'), OTHER)
		await expect(dialog.getByTestId('decision-advice-reason'), 'a deviation asks for a reason').toBeVisible()
		const save = dialog.getByTestId('decision-advice-save')
		await expect(save).toHaveText(/Record the deviation/)
		await save.click()

		await expect(dialog.getByTestId('decision-advice-error')).toContainText(REASON_REQUIRED, { timeout: 20_000 })
		await expect(dialog.getByTestId('decision-advice-saved')).toHaveCount(0)

		const stored = await listObjects(api, 'decisionAdvice', { case: caseId })
		stored.forEach((row: any) => trackCreatedObject('decisionAdvice', objectId(row)))
		expect(stored.every((row: any) => String(row.outcome ?? '') === ''), 'no outcome was recorded').toBe(true)
	})

	// @e2e openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#scenario-a-case-type-without-a-decision-table-shows-no-action
	test('a case whose type names no decision table shows no advice action', async ({ page }) => {
		const caseType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} Zonder beslistabel`,
			identifier: `${RUN_PREFIX.toLowerCase()}-zonder-beslistabel`,
			description: 'Throwaway caseType that names no advice decision table.',
			isDraft: false,
		})
		const caseId = objectId(
			await seedCase(api, token, { title: `${RUN_PREFIX} geen advies`, caseType: objectId(caseType) }),
		)

		const form = await api.get(`${ADVICE}/case/${caseId}/form`)
		expect(form.status()).toBe(200)
		expect((await form.json()).available, 'the server says there is nothing to ask').toBe(false)

		const asked = await api.post(`${ADVICE}/recommend`, { headers: writeHeaders(), data: { caseId, inputs: INPUTS } })
		expect(asked.status(), 'and refuses a request made anyway').toBe(409)

		await page.goto(`/apps/${REGISTER}/cases/${caseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await openHeaderActionsMenu(page)
		await expect(page.getByTestId(ACTION), 'the advice action is not shown').toHaveCount(0)
	})
})
