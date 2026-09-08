/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Contacts as a place you can go: find a person, see what they have with you,
 * and do the next thing from their page.
 *
 * WHAT THIS IS GUARDING. Before this change you could reach a citizen only
 * through a case that already named them, and the case's Contacts tab was
 * inert. The failure mode of the replacement is not a blank page — it is a
 * page that renders perfectly and is empty for the wrong reason. A widget
 * whose `@objectId` filter names a property the schema does not carry returns
 * nothing, and "no cases for this contact" and "the filter is wrong" look
 * identical. So every list assertion here is paired with a row this spec
 * SEEDED, and every write is read back over the API rather than inferred from
 * the page.
 *
 * Locale: nothing forces the language of the E2E instance, so navigation is
 * addressed by href and rows by data this spec wrote. The one place an English
 * label is unavoidable — the header action buttons — matches both locales.
 *
 * Two scenarios of the delta specs are deliberately absent and are `@e2e
 * exclude`d in the spec itself: the Requester COLUMN link (blocked on
 * nextcloud-vue choosing a route from a sibling field) and the KCC panel
 * (Tier B). A third, the Organisations folder, is asserted here as ABSENT —
 * see `contacts-domain.spec` in tests/vitest for the measurement of why it
 * cannot ship.
 */

import type { APIRequestContext, Page } from '@playwright/test'

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
} from './helpers/fixtures.ts'
import { dismissSupportDialog } from './helpers/nav.ts'

/** The person the whole spec is about. Unique per run. */
const PERSON_NAME = `${RUN_PREFIX} Jansen`
/** A second person, with nothing attached, for the two empty states. */
const EMPTY_NAME = `${RUN_PREFIX} Zonder`

let api: APIRequestContext
let token: string
let caseTypeId = ''

let personId = ''
let emptyPersonId = ''
let seededCaseId = ''

/**
 * Seed one brpPerson row.
 *
 * The BSN is fictitious and 11-proef valid — OpenRegister registers a `bsn`
 * string format (ADR-011) and rejects a number that fails the checksum, so an
 * invented one would fail the CREATE rather than the assertion.
 *
 * @param displayName The name the index is searched by.
 * @param bsn A checksum-valid citizen service number.
 */
async function seedPerson(displayName: string, bsn: string): Promise<string> {
	const created = await createObject(api, token, 'brpPerson', {
		citizenServiceNumber: bsn,
		name: { givenNames: 'Test', surname: displayName },
		displayName,
		residence: {
			street: 'Lindelaan',
			houseNumber: 8,
			postcode: '1234AB',
			city: 'Utrecht',
		},
		description: `Seeded by contacts-domain.spec.ts (${RUN_PREFIX}).`,
	})
	return objectId(created)
}

/**
 * Open a contact page and wait for its body.
 *
 * @param page The Playwright page.
 * @param id The brpPerson id.
 */
async function openContact(page: Page, id: string) {
	await page.goto(`/apps/${REGISTER}/contacts/${id}`)
	await dismissSupportDialog(page)
	await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Contacts', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// REUSE a seeded case type: the `case` schema is archival, so a case
		// cannot be deleted by a user, and a case type created here and removed
		// in teardown would leave a case pointing at a type that is gone.
		const caseTypes = await listObjects(api, 'caseType')
		expect(
			caseTypes.length,
			'the instance must ship at least one case type',
		).toBeGreaterThan(0)
		caseTypeId = objectId(caseTypes[0])

		personId = await seedPerson(PERSON_NAME, '999990627')
		emptyPersonId = await seedPerson(EMPTY_NAME, '999993653')

		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} Dormer window`,
			caseType: caseTypeId,
			requester: personId,
		})
		seededCaseId = objectId(seeded)

		await createObject(api, token, 'contactmoment', {
			contact: personId,
			notificationChannel: 'phone',
			direction: 'inbound',
			identificationMethod: 'digid',
			kccEmployeeId: 'admin',
			nature: 'vraag',
			startTime: new Date().toISOString(),
			summary: `${RUN_PREFIX} asked about the dormer window`,
		})
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token, [
			'contactmoment',
			'brpPerson',
			'kvkCompany',
		])
		await api.dispose()
	})

	test('is a top-level entry that opens the index', async ({ page }) => {
		await page.goto(`/apps/${REGISTER}`)
		await dismissSupportDialog(page)

		const nav = page.locator('[id^="app-navigation"]').first()
		const link = nav.locator('a[href$="/contacts"]')
		await expect(link).toHaveCount(1)
		// Visible without expanding anything: a leaf that landed inside the
		// My work group would be display:none until the group is opened.
		await expect(link).toBeVisible({ timeout: 30_000 })

		await link.click()
		await expect(page).toHaveURL(/\/contacts$/)
		await expect(page.locator('.cn-index-page')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('finds a person by name', async ({ page }) => {
		await page.goto(
			`/apps/${REGISTER}/contacts?displayName=${encodeURIComponent(PERSON_NAME)}`,
		)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-index-page')).toBeVisible({
			timeout: 30_000,
		})

		const row = page.getByRole('row', { name: new RegExp(PERSON_NAME, 'i') })
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row).toContainText('999990627')
	})

	test('offers no Organisations folder yet', async ({ page }) => {
		await page.goto(`/apps/${REGISTER}/contacts`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-index-page')).toBeVisible({
			timeout: 30_000,
		})

		// A folder that filters this page's brpPerson rows by a kvkCompany
		// schema shows an empty list, and an empty list reads as "no
		// organisations" rather than as "this folder cannot work yet". So it is
		// absent until nextcloud-vue lets a folder carry its own schema.
		await expect(page.getByText(/^(Organisations|Organisaties)$/)).toHaveCount(0)
	})

	test("shows a person's cases, and the case links back", async ({ page }) => {
		await openContact(page, personId)

		await expect(page.getByText(PERSON_NAME).first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByText('999990627').first()).toBeVisible()

		const caseRow = page.getByRole('row', {
			name: new RegExp(`${RUN_PREFIX} Dormer window`, 'i'),
		})
		await expect(caseRow).toBeVisible({ timeout: 30_000 })

		await caseRow.click()
		await expect(page).toHaveURL(new RegExp(`/cases/${seededCaseId}$`))

		// And back again, through the number on the initiator card. This is the
		// half that used to leave the app for OpenRegister's object viewer.
		const link = page.locator('[data-testid="initiator-source-link"]')
		await expect(link).toBeVisible({ timeout: 30_000 })
		await expect(link).toHaveAttribute(
			'href',
			new RegExp(`/apps/dossiq/contacts/${personId}$`),
		)
	})

	test("shows a person's contact moments", async ({ page }) => {
		await openContact(page, personId)

		await expect(
			page.getByText(`${RUN_PREFIX} asked about the dormer window`),
		).toBeVisible({ timeout: 30_000 })
	})

	test('says so when a person has neither cases nor moments', async ({ page }) => {
		await openContact(page, emptyPersonId)

		// The empty states, not an absent widget: a widget that failed to
		// render would also show no rows.
		await expect(
			page.getByText(/No cases for this contact yet|Nog geen zaken/),
		).toBeVisible({ timeout: 30_000 })
		await expect(
			page.getByText(/No contact moments yet|Nog geen contactmomenten/),
		).toBeVisible({ timeout: 30_000 })
	})

	test('files a case with the requester already filled in', async ({ page }) => {
		await openContact(page, emptyPersonId)

		await page.getByRole('button', { name: /^(New case|Nieuwe zaak)$/ }).click()

		const dialog = page.locator('.modal-container, [role="dialog"]').first()
		await expect(dialog).toBeVisible({ timeout: 30_000 })

		// The requester is there BEFORE anything is typed. That is the whole
		// point of the action; a form that opens empty is the old flow.
		await expect(dialog.getByText(EMPTY_NAME).first()).toBeVisible({
			timeout: 30_000,
		})
	})

	test('logs a contact moment against this contact', async ({ page }) => {
		await openContact(page, emptyPersonId)

		await page
			.getByRole('button', { name: /^(Log contact|Contact vastleggen)$/ })
			.click()

		const dialog = page.locator('.modal-container, [role="dialog"]').first()
		await expect(dialog).toBeVisible({ timeout: 30_000 })

		// The form asks for the six fields the action includes and NOT for the
		// KCC bookkeeping the schema also carries.
		await expect(dialog.getByText(/Summary|Samenvatting/).first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(
			dialog.getByText(/Identification Score|Identificatiescore/),
		).toHaveCount(0)
	})

	test('the saved moment carries its contact', async () => {
		// Read back over the API rather than off the page: the list could show
		// the row for the wrong reason (an unfiltered query), and the property
		// this change adds is the thing that has to be on the record.
		const moments = await listObjects(api, 'contactmoment', {
			contact: personId,
		})
		expect(moments.length).toBeGreaterThan(0)
		for (const moment of moments) {
			expect(String(moment.contact)).toBe(personId)
		}
	})

	test('the seeded case carries its requester', async () => {
		const cases = await listObjects(api, 'case', { requester: personId })
		expect(cases.map((c) => objectId(c))).toContain(seededCaseId)
	})
})
