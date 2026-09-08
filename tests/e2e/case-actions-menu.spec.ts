/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a handler can do TO a case from its own page: copy it, start an
 * allowed sub-process for it, and plan a follow-up.
 *
 * Everything here is a seam no unit test reaches. The manifest declares three
 * header actions, each opening a registry modal; the modals talk to dossiq's
 * endpoints; and the endpoints write through OpenRegister and its flow store.
 * A vitest can check that the declarations agree. Only a browser can show that
 * a handler pressing Copy case ends up on a second case.
 *
 * ASSERT IDS, NOT LABELS. Nothing forces the language of the E2E instance, so
 * every locator is a `data-testid` or a role, and the only text asserted is
 * text this fixture itself seeded (which carries RUN_PREFIX and is therefore
 * the same in either locale). The two tab names are matched against both
 * locales for the same reason.
 *
 * THE TAB PANELS ARE LAZY and the sidebar has tabpanels of its own, so the
 * Related cases assertions address the OPEN PANEL inside the strip,
 * `[role="tabpanel"]:not([hidden])`, and never a page-wide locator.
 */

import { expect, test } from '@playwright/test'
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
	updateObject,
} from './helpers/fixtures.ts'
import { tickCheckbox, trackDossiqErrors } from './helpers/nav.ts'

/** The case types this suite seeds: one that allows a flow, one that does not. */
let typeWithFlows = ''
let typeWithoutFlows = ''

/** The status a new case of either type starts in. */
let statusReceived = ''

/** The flow the allowing case type marks startable, and its title. */
let startableFlowId = ''
let startableFlowTitle = ''

/** One case per scenario, so no test depends on another's writes. */
const cases: Record<string, string> = {}

/** The document link seeded on the case that is copied with documents. */
const DOCUMENT_URI = 'nc://e2e/case-actions/bouwtekening.pdf'

test.describe('The case Actions menu', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)

		// A flow to mark startable. The flows dossiq SHIPS are what an install
		// actually has, so the fixture names one of those rather than authoring
		// its own: a flow this suite created would also have to be published and
		// adopted, and the suite would then be testing the adoption path.
		const flows = await api.get('/index.php/apps/openregister/api/flows')
		if (flows.ok() === true) {
			const body = await flows.json()
			const rows = (body?.results ?? body?.flows ?? body ?? []) as any[]
			const first = Array.isArray(rows) ? rows[0] : null
			if (first) {
				startableFlowId = String(first.uuid ?? first.id ?? '')
				startableFlowTitle = String(first.name ?? '')
			}
		}

		const mkType = async (label: string, startableFlows: string[]) =>
			objectId(
				await createObject(api, token, 'caseType', {
					title: `${RUN_PREFIX} ${label}`,
					identifier: `${RUN_PREFIX.toLowerCase()}-${label}`,
					description:
						'Throwaway caseType for the case-actions-menu e2e layer.',
					processingDeadline: 'P30D',
					startableFlows,
				}),
			)

		typeWithFlows = await mkType(
			'allows',
			startableFlowId === '' ? [] : [startableFlowId],
		)
		typeWithoutFlows = await mkType('allows-none', [])

		// One status per type, and the type's initial status, so a copy lands
		// somewhere rather than nowhere.
		const mkStatus = async (caseTypeId: string) =>
			objectId(
				await createObject(api, token, 'statusType', {
					name: `${RUN_PREFIX} Ontvangen`,
					caseType: caseTypeId,
					order: 1,
					isFinal: false,
				}),
			)
		statusReceived = await mkStatus(typeWithFlows)
		await updateObject(api, token, 'caseType', typeWithFlows, {
			initialStatus: statusReceived,
		})
		const otherStatus = await mkStatus(typeWithoutFlows)
		await updateObject(api, token, 'caseType', typeWithoutFlows, {
			initialStatus: otherStatus,
		})

		// The cases are created AFTER the case types carry their startableFlows,
		// because `hasStartableFlows` is materialised on the case at save time
		// from the type it points at. A case seeded first would carry false and
		// the Start entry would be hidden on a type that allows a flow.
		const seed = async (key: string, caseTypeId: string, extra = {}) => {
			const row = await seedCase(api, token, {
				title: `${RUN_PREFIX} ${key}`,
				caseType: caseTypeId,
				description: `Seeded for the ${key} scenario.`,
				confidentiality: 'openbaar',
				priority: 'high',
				intakeChannel: 'website',
				startDate: new Date().toISOString().slice(0, 10),
				...extra,
			})
			cases[key] = objectId(row)
		}

		await seed('copy', typeWithFlows)
		await seed('copy-with-documents', typeWithFlows)
		await seed('start', typeWithFlows)
		await seed('no-start', typeWithoutFlows)
		await seed('plan', typeWithFlows)

		await createObject(api, token, 'caseDocument', {
			case: cases['copy-with-documents'],
			document: DOCUMENT_URI,
			title: `${RUN_PREFIX} Bouwtekening`,
			registrationDate: new Date().toISOString().slice(0, 10),
		})

		await api.dispose()
	})

	test.afterAll(async ({ playwright, baseURL }) => {
		const api = await playwright.request.newContext({ baseURL })
		const token = await getRequestToken(api)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open one case and wait for the detail page to have rendered.
	 *
	 * @param page The Playwright page.
	 * @param key Which seeded case to open.
	 */
	const openCase = async (page: any, key: string) => {
		await page.goto(`/apps/${REGISTER}/cases/${cases[key]}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
	}

	// @e2e openspec/specs/case-management/spec.md#a-handler-copies-a-case
	test('a handler copies a case and lands on the copy', async ({
		page,
		request,
	}) => {
		const errors = trackDossiqErrors(page)
		await openCase(page, 'copy')

		await page.getByTestId('cn-action-copy-case').click()
		const dialog = page.getByTestId('case-copy-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		// The title is proposed, not blank: the button is enabled straight away.
		//
		// 🔴 NO `.locator('input')`. `NcTextField` sets `inheritAttrs: false` and
		// merges `$attrs` onto the control, so `data-testid` lands ON the
		// `<input>` itself. Asking for an input INSIDE it searches an element
		// that has no children, matches nothing, and reports
		// `element(s) not found` after the full timeout, which reads as a
		// dialog that never opened rather than as a locator one level too deep.
		const title = dialog.getByTestId('case-copy-title')
		await expect(title).toHaveValue(/Copy of/, { timeout: 20_000 })

		await page.getByTestId('case-copy-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 30_000 })

		// The browser is now on a DIFFERENT case.
		await expect(page).not.toHaveURL(new RegExp(`${cases.copy}$`), {
			timeout: 30_000,
		})

		const source = await showObject(request, 'case', cases.copy)
		const copies = (
			await listObjects(request, 'case', { _limit: '200' })
		).filter(
			(row: any) =>
				String(row.title ?? '').startsWith('Copy of')
				&& String(row.title ?? '').includes(`${RUN_PREFIX} copy`),
		)
		expect(copies.length, 'the copy was written').toBeGreaterThan(0)

		const copy = copies[0]
		expect(copy.caseType).toBe(source.caseType)
		expect(copy.confidentiality).toBe(source.confidentiality)
		expect(copy.priority).toBe(source.priority)
		expect(copy.intakeChannel).toBe(source.intakeChannel)

		// Its own number, and the type's initial status.
		expect(String(copy.identifier ?? '')).not.toBe(
			String(source.identifier ?? ''),
		)
		expect(copy.status).toBe(statusReceived)

		// The source is under the copy's related cases.
		expect(JSON.stringify(copy.relatedCases ?? '')).toContain(cases.copy)

		// And none of the source's legal facts came along.
		expect(copy.result ?? null).toBeFalsy()
		expect(copy.statusHistory ?? null).toBeFalsy()

		expect(errors, errors.join('\n')).toEqual([])
	})

	// @e2e openspec/specs/case-management/spec.md#documents-come-along-as-links
	test('documents come along as links, and the file exists once', async ({
		page,
		request,
	}) => {
		await openCase(page, 'copy-with-documents')

		await page.getByTestId('cn-action-copy-case').click()
		const dialog = page.getByTestId('case-copy-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		// The same two traps at once: the testid is on the input, and the input
		// sits under a label span that swallows the click. `tickCheckbox`
		// clicks the label, which is the control a person clicks, and then
		// asserts the box really is ticked.
		await tickCheckbox(dialog.getByTestId('case-copy-documents'))
		await page.getByTestId('case-copy-confirm').click()
		await expect(dialog).toHaveCount(0, { timeout: 30_000 })

		// TWO links, ONE document. That is the whole requirement: the copy has
		// the document, and nothing was duplicated in storage.
		const links = (
			await listObjects(request, 'caseDocument', { _limit: '200' })
		).filter((row: any) => String(row.document ?? '') === DOCUMENT_URI)
		expect(links.length, 'the document is linked twice').toBe(2)

		const owners = new Set(links.map((row: any) => String(row.case ?? '')))
		expect(owners.has(cases['copy-with-documents'])).toBe(true)
		expect(owners.size, 'the two links belong to two different cases').toBe(2)
	})

	// @e2e openspec/specs/workflow-definition-engine/spec.md#the-start-list-follows-the-case-type
	test('Start lists the flows the case type allows, and nothing else', async ({
		page,
	}) => {
		test.skip(
			startableFlowId === '',
			'this instance has no flow to mark startable, so there is no list to follow',
		)

		await openCase(page, 'start')

		await page.getByTestId('cn-action-start-flow').click()
		const dialog = page.getByTestId('case-start-flow-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		const list = dialog.getByTestId('case-start-flow-list')
		await expect(list).toBeVisible({ timeout: 20_000 })
		await expect(list).toContainText(startableFlowTitle)
		// Exactly one row: the type names one flow, so the list is that flow.
		await expect(list.locator('li')).toHaveCount(1)
	})

	// @e2e openspec/specs/workflow-definition-engine/spec.md#a-type-without-startable-flows-hides-start
	test('a case type that allows none does not offer Start', async ({ page }) => {
		await openCase(page, 'no-start')

		// The Actions menu itself is there; the entry is not.
		await expect(page.getByTestId('cn-action-copy-case')).toBeVisible({
			timeout: 20_000,
		})
		await expect(page.getByTestId('cn-action-start-flow')).toHaveCount(0)
	})

	// @e2e openspec/specs/workflow-definition-engine/spec.md#a-planned-case-shows-on-the-tab
	test('a planned follow-up shows on the Related cases tab, and no case exists yet', async ({
		page,
		request,
	}) => {
		await openCase(page, 'plan')

		const nextMonth = new Date()
		nextMonth.setMonth(nextMonth.getMonth() + 1)
		const due = nextMonth.toISOString().slice(0, 10)
		const plannedTitle = `${RUN_PREFIX} Controle`

		// The endpoint is posted directly rather than driven through the date
		// picker: a calendar widget's month navigation is the flakiest thing on
		// this page, and what the scenario is about is the ROW appearing, not
		// how the date was typed. The dialog's own wiring is asserted by the
		// vitest over the manifest and by the picker's presence below.
		await page.getByTestId('cn-action-plan-follow-up').click()
		const dialog = page.getByTestId('case-plan-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		await expect(dialog.getByTestId('case-plan-date')).toBeVisible()
		await page.getByTestId('case-plan-cancel').click()
		await expect(dialog).toHaveCount(0, { timeout: 20_000 })

		const token = await getRequestToken(request)
		const planned = await request.post(
			`/index.php/apps/${REGISTER}/api/case/${cases.plan}/plan`,
			{
				headers: {
					'Content-Type': 'application/json',
					requesttoken: token,
					'OCS-APIRequest': 'true',
				},
				data: { caseType: typeWithFlows, date: due, title: plannedTitle },
			},
		)
		expect(
			planned.status(),
			`plan -> ${planned.status()} ${await planned.text()}`,
		).toBe(200)

		// The Related cases tab shows it, with its date.
		await page.reload()
		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })
		await strip
			.getByRole('tab', { name: /Related cases|Gerelateerde zaken/ })
			.click()

		const panel = strip.locator('[role="tabpanel"]:not([hidden])')
		await expect(panel).toContainText(plannedTitle, { timeout: 30_000 })
		await expect(panel).toContainText(due, { timeout: 30_000 })

		// And no case of that title exists yet: the follow-up is planned, not made.
		const existing = (
			await listObjects(request, 'case', { _limit: '200' })
		).filter((row: any) => String(row.title ?? '') === plannedTitle)
		expect(existing.length, 'the planned case does not exist yet').toBe(0)
	})
})
