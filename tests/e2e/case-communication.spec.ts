/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Communication tab: every call, visit and message logged on one case.
 *
 * `contactmoment` was the KCC workplace's schema alone. It carried
 * `relatedCases`, a LIST, and a list cannot be an object-list filter, so no
 * case page could show a case's contacts at all. `contactmoment.case` is the
 * single reference the filter needs, and this spec is what tells a working
 * filter apart from the two things that look exactly like it: a widget whose
 * query 404s, and a case that genuinely has no contacts. Both render the same
 * empty state, so the seeded rows are asserted by their own text and the
 * saved object is read back over the API rather than inferred from the page.
 *
 * Locale: nothing forces the language of the E2E instance, so tab and button
 * names are matched in either locale the app ships, the way the sibling
 * case-detail specs do. Rows are matched on their run-prefixed summary, which
 * is data this spec wrote and no translation touches.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openCasePanel } from './helpers/case-panels.ts'
import {
	adoptableCaseTypes,
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { clickHeaderAction } from './helpers/nav.ts'

/** The five fields the Log contact action asks a handler to fill. */
const FORM_FIELDS = [
	'notificationChannel',
	'direction',
	'startTime',
	'summary',
	'callerIdentification',
]

/**
 * The KCC fields the form must NOT ask for. They are seeded through the
 * action's `props` and written without ever being rendered, which is the
 * whole point: a handler logging a call does not know what a
 * `identificationMethod` is.
 */
const KCC_FIELDS = ['identificationMethod', 'nature', 'kccEmployeeId']

const EARLIER_SUMMARY = `${RUN_PREFIX} called about the hearing date`
const LATER_SUMMARY = `${RUN_PREFIX} e-mailed the inspection report`
const OTHER_SUMMARY = `${RUN_PREFIX} another case entirely`
const TYPED_SUMMARY = `${RUN_PREFIX} asked about the hearing date`

const EARLIER_START = '2026-05-04T09:12:00+00:00'
const LATER_START = '2026-05-06T14:30:00+00:00'

let api: APIRequestContext
let token: string
let caseTypeId = ''
/** The case the tab is read on: two contacts. */
let caseId = ''
/** A second case with one contact of its own, which must never show up. */
let otherCaseId = ''
/** A case with no contacts at all. */
let emptyCaseId = ''
/** A case the form writes to, kept apart so the logged row is unambiguous. */
let formCaseId = ''

/**
 * Seed one contact moment through the API.
 *
 * The API path goes through ContactMomentController, so the KCC fields are
 * spelled out here rather than left to the form's `props`.
 *
 * @param onCase    The case id the contact is about.
 * @param summary   The summary, carrying RUN_PREFIX so teardown finds it.
 * @param channel   The notification channel.
 * @param direction inbound or outbound.
 * @param startTime ISO start time.
 */
async function seedContact(
	onCase: string,
	summary: string,
	channel: string,
	direction: string,
	startTime: string,
): Promise<string> {
	const created = await createObject(api, token, 'contactmoment', {
		notificationChannel: channel,
		direction,
		startTime,
		summary,
		identificationMethod: 'non_geidentificeerd',
		kccEmployeeId: 'admin',
		nature: 'informatieverzoek',
		case: onCase,
		relatedCases: [onCase],
	})
	return objectId(created)
}

/**
 * Open a case and switch to its Communication tab.
 *
 * The tab panels are LAZY: the widget does not mount, and therefore does not
 * query, until its tab is opened. Without the click every assertion below
 * would time out on an unmounted panel and read as a broken filter.
 *
 * @param page The Playwright page.
 * @param id   The case id to open.
 */
async function openCommunicationTab(page, id: string) {
	await page.goto(`/apps/${REGISTER}/cases/${id}`)
	await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

	// The SECTION, not the whole open panel. Now that the strip holds six tabs
	// instead of fourteen, a tab carries two collections, so an assertion made
	// against the panel root can be satisfied by the wrong half of it. The
	// tab-to-section mapping lives in helpers/case-panels.ts, so the next fold
	// moves one table rather than every spec that opens a panel.
	//
	// It is a testid and not a widget id because CnDetailPage sets `aria-label`
	// to the manifest widget id only on the top-level widgets it lays out, so a
	// widget rendered inside a tab carries no such label.
	return await openCasePanel(page, 'communication')
}

test.describe('Case detail — the Communication tab', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// REUSE a seeded case type. The `case` schema is archival, so a case
		// cannot be deleted by a user; creating a case type here and deleting
		// it in teardown would leave every case pointing at a type that is
		// gone, which reddens unrelated specs.
		const caseTypes = await adoptableCaseTypes(api)
		expect(
			caseTypes.length,
			'the instance must ship at least one PUBLISHED case type — adoptableCaseTypes() excludes drafts (isDraft !== false) and fixture-owned rows',
		).toBeGreaterThan(0)
		caseTypeId = objectId(caseTypes[0])

		const seeded = await Promise.all([
			seedCase(api, token, {
				title: `${RUN_PREFIX} Communication`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Communication other`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Communication empty`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Communication form`,
				caseType: caseTypeId,
			}),
		])
		;[caseId, otherCaseId, emptyCaseId, formCaseId] = seeded.map(objectId)

		// Seeded in the wrong order on purpose: the list must sort on
		// `startTime`, not on the order the rows were written.
		await seedContact(caseId, EARLIER_SUMMARY, 'phone', 'inbound', EARLIER_START)
		await seedContact(caseId, LATER_SUMMARY, 'email', 'outbound', LATER_START)
		await seedContact(
			otherCaseId,
			OTHER_SUMMARY,
			'balie',
			'inbound',
			LATER_START,
		)
	})

	test.afterAll(async () => {
		if (!api) return
		// Contact moments only. The cases are archival and cannot be removed by
		// a user; they carry the family prefix, so global-setup's residue sweep
		// takes them before the next run rather than this teardown failing on
		// a 403 it was never going to win.
		await cleanupRunObjects(api, token, ['contactmoment'])
		await api.dispose()
	})

	// @e2e openspec/changes/contact-moments/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-the-case-page-lists-its-contact-moments-req-kwz-12
	test('the tab lists this case contacts and not another case one', async ({
		page,
	}) => {
		const widget = await openCommunicationTab(page, caseId)

		const rows = widget.locator('tbody tr')
		await expect(rows).toHaveCount(2, { timeout: 20_000 })

		// The four columns the spec names, read off the row that holds the
		// inbound phone call.
		const call = rows.filter({ hasText: EARLIER_SUMMARY })
		await expect(call).toHaveCount(1)
		await expect(call).toContainText('phone')
		await expect(call).toContainText('inbound')
		// The date column renders the start time; asserting the YEAR-MONTH is
		// enough to prove the cell is bound without pinning a locale format.
		await expect(call).toContainText(/2026/)

		const mail = rows.filter({ hasText: LATER_SUMMARY })
		await expect(mail).toHaveCount(1)
		await expect(mail).toContainText('email')
		await expect(mail).toContainText('outbound')

		// The other case's contact exists and is filtered out. Without the
		// filter this widget would list every contact moment on the instance,
		// which on a demo-seeded install still looks plausible.
		await expect(widget.getByText(OTHER_SUMMARY)).toHaveCount(0)
	})

	// @e2e openspec/changes/contact-moments/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-the-case-page-lists-its-contact-moments-req-kwz-12
	test('the newest contact is the first row', async ({ page }) => {
		const widget = await openCommunicationTab(page, caseId)
		await expect(widget.locator('tbody tr')).toHaveCount(2, {
			timeout: 20_000,
		})

		const texts = await widget.locator('tbody tr').allInnerTexts()
		const later = texts.findIndex((t) => t.includes(LATER_SUMMARY))
		const earlier = texts.findIndex((t) => t.includes(EARLIER_SUMMARY))
		expect(later, `rows: ${texts.join(' | ')}`).toBe(0)
		expect(earlier).toBe(1)
	})

	// @e2e openspec/changes/contact-moments/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-the-case-page-lists-its-contact-moments-req-kwz-12
	test('a case without contacts shows the tab and its empty state', async ({
		page,
	}) => {
		// A widget whose query fails renders an empty state too, so the
		// REQUEST is asserted beside the text. Without it this test passes on
		// a 404 and the tab looks correct while showing nothing it should.
		const statuses: number[] = []
		page.on('response', (r) => {
			if (r.url().includes('/objects/dossiq/contactmoment')) {
				statuses.push(r.status())
			}
		})

		const widget = await openCommunicationTab(page, emptyCaseId)

		await expect(widget).toContainText(
			/No contact logged on this case yet|Nog geen contact vastgelegd op deze zaak/,
			{ timeout: 20_000 },
		)
		await expect
			.poll(() => statuses.length, { timeout: 20_000 })
			.toBeGreaterThan(0)
		expect(
			statuses.every((s) => s < 400),
			`contactmoment queries: ${statuses.join(',')}`,
		).toBe(true)
	})

	// @e2e openspec/changes/contact-moments/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-you-log-a-contact-from-the-case-req-kwz-13
	test('the Log contact form asks for five fields and none of the KCC ones', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${formCaseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-log-contact')

		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		for (const key of FORM_FIELDS) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"]`),
				`the form should ask for ${key}`,
			).toHaveCount(1)
		}
		for (const key of KCC_FIELDS) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"]`),
				`${key} is seeded, never asked`,
			).toHaveCount(0)
		}
		// `case` is seeded too: the handler opened the form FROM the case, so
		// being asked which case it is about would be the form forgetting.
		await expect(dialog.locator('[data-cn-field="case"]')).toHaveCount(0)
	})

	// @e2e openspec/changes/contact-moments/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-a-contact-moment-names-its-case-req-kwz-11
	// @e2e openspec/changes/contact-moments/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-you-log-a-contact-from-the-case-req-kwz-13
	test('a logged call carries the case and shows up in the tab', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${formCaseId}`)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-log-contact')

		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		// The enum options are the schema's raw values (`phone`, `inbound`) —
		// the property declares no `x-enum-labels` — so these choices are the
		// same in either locale.
		await dialog
			.locator('[data-cn-field="notificationChannel"]')
			.getByRole('combobox')
			.click()
		await page
			.getByRole('option')
			.filter({ hasText: /^phone$/i })
			.click()

		await dialog
			.locator('[data-cn-field="direction"]')
			.getByRole('combobox')
			.click()
		await page
			.getByRole('option')
			.filter({ hasText: /^inbound$/i })
			.click()

		await dialog
			.locator('[data-cn-field="summary"]')
			.getByRole('textbox')
			.fill(TYPED_SUMMARY)

		await dialog
			.getByRole('button', { name: /^(Create|Save|Aanmaken|Opslaan)$/ })
			.click()

		// THE SAVED OBJECT, and deliberately not a prefilled field. Seeding the
		// case through the action's `props` is the shipped mechanism, not a
		// stand-in for one: CnActionButtons resolves those props through the
		// filter-token grammar and hands them to CnFormDialog as `initial-data`.
		// REQ-KWZ-13 names the five fields the form asks for and says the case
		// is PASSED, so there is no `case` input to read it back off, and the
		// sibling test asserts that absence. The outcome is what can be
		// asserted, and it is also the only thing that matters here.
		let saved: any
		await expect(async () => {
			const rows = await listObjects(api, 'contactmoment', { _limit: '200' })
			saved = rows.find((r) => String(r.summary ?? '') === TYPED_SUMMARY)
			expect(saved, 'the contact should have been created').toBeTruthy()
		}).toPass({ timeout: 30_000 })

		const stored = await showObject(api, 'contactmoment', objectId(saved))
		expect(String(stored.case)).toBe(formCaseId)
		expect(
			(stored.relatedCases ?? []).map(String),
			'the KCC voorblad reads relatedCases, so the case belongs in it too',
		).toContain(formCaseId)
		expect(String(stored.notificationChannel)).toBe('phone')
		expect(String(stored.direction)).toBe('inbound')
		// The signed-in user, seeded through `@me` rather than typed.
		expect(String(stored.kccEmployeeId)).not.toBe('')

		const widget = await openCommunicationTab(page, formCaseId)
		const row = widget.locator('tbody tr').filter({ hasText: TYPED_SUMMARY })
		await expect(row).toHaveCount(1, { timeout: 20_000 })
		await expect(row).toContainText('phone')
		await expect(row).toContainText('inbound')
	})
})
