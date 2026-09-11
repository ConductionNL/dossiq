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
 * One scenario of the delta specs is deliberately absent and is `@e2e
 * exclude`d in the spec itself: the KCC panel (Tier B). Another, the
 * Organisations folder, is asserted here as ABSENT — see `contacts-domain.spec`
 * in tests/vitest for the measurement of why it cannot ship.
 *
 * The Requester COLUMN link was the third, and stopped being blocked in
 * nextcloud-vue 2.47.0: a column can now choose its route from a sibling
 * field. It is asserted here, and asserted on TWO rows in one render, because
 * a person and an organisation going to the same page is exactly what a fixed
 * route would look like.
 *
 * EXTENDED by `contacts-you-can-find`, which is the change that made this
 * file run at all: its first ever execution, 2026-09-08, failed in
 * `beforeAll` on a `contactmoment.nature` value the enum does not carry, so
 * one ✘ stood for nine tests that never started. It also adds the
 * organisations index the folder sidebar was going to be for, and asserts
 * where a unified-search hit on a contact lands, which is the half of the
 * contacts story that was silently pointing at a JSON endpoint.
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
import { clickHeaderAction, dismissSupportDialog } from './helpers/nav.ts'

/** The person the whole spec is about. Unique per run. */
const PERSON_NAME = `${RUN_PREFIX} Jansen`
/** A second person, with nothing attached, for the two empty states. */
const EMPTY_NAME = `${RUN_PREFIX} Zonder`
/** The organisation the Organisations index is asserted on. */
const COMPANY_NAME = `${RUN_PREFIX} Dakkapellen BV`
/** Fictitious, and unique to this run so the index row is unambiguous. */
const COMPANY_KVK = '90004760'

let api: APIRequestContext
let token: string
let caseTypeId = ''

let personId = ''
let emptyPersonId = ''
let companyId = ''
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
 * Seed one kvkCompany row.
 *
 * Ten of these already sit on the instance and were listed by NOTHING before
 * `contacts-you-can-find`: `OrganisationDetail` shipped with no index, so the
 * only way in was the initiator card of a case that already named the
 * company. This row is the one the Organisations index is asserted on.
 *
 * @param tradeName The name the index is searched by.
 * @param kvkNumber An eight-digit fictitious KvK number.
 */
async function seedCompany(tradeName: string, kvkNumber: string): Promise<string> {
	const created = await createObject(api, token, 'kvkCompany', {
		kvkNumber,
		tradeName,
		legalForm: 'Besloten Vennootschap',
		address: {
			streetName: 'Lindelaan',
			// Integer, like brpPerson's. A string 400s on the CREATE, in a
			// hook, which reads as every test in the file failing at once.
			houseNumber: 8,
			postcode: '1234AB',
			place: 'Utrecht',
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
		//
		// A PUBLISHED one, not simply the first. `case.caseType` carries
		// `x-relation-filter: {isDraft: false}` since #1918, and the caseType
		// schema DEFAULTS `isDraft` to true — so a draft type is filtered out
		// of the relation and the case this spec seeds would carry no usable
		// case type. The cases list on the contact page would then be empty and
		// read as "this person has no cases", which is the one failure this
		// spec exists to tell apart from a broken filter. #1944 fixed the
		// fixtures that CREATE a case type; six other specs still take
		// `caseTypes[0]` blind, and this is the same hazard in the one file
		// this change owns.
		const caseTypes = await listObjects(api, 'caseType')
		const published = caseTypes.filter((t) => t.isDraft === false)
		expect(
			published.length,
			`the instance must ship at least one PUBLISHED case type `
				+ `(saw ${caseTypes.length} type(s), all draft)`,
		).toBeGreaterThan(0)
		caseTypeId = objectId(published[0])

		personId = await seedPerson(PERSON_NAME, '999990627')
		emptyPersonId = await seedPerson(EMPTY_NAME, '999993653')
		companyId = await seedCompany(COMPANY_NAME, COMPANY_KVK)

		// A case names its `requester` and NOTHING ELSE about the initiator.
		// `initiatorType`, `initiatorDisplayName` and `initiatorSourceId` are
		// display projections OpenRegister derives from the reference.
		//
		// An earlier version of this file wrote `initiatorType` and
		// `initiatorDisplayName` explicitly, on the assumption that an API create
		// names the reference only. That assumption was wrong and it cost a
		// neighbouring test: with them written by hand, `initiatorSourceId` came
		// back EMPTY, so `InitiatorSection.resolveSource()` returned before its
		// lookup, the initiator card rendered no link, and "the case links back"
		// failed twice while passing on `development` with the same code. Writing
		// a projection by hand is not a neutral act here.
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} Dormer window`,
			caseType: caseTypeId,
			requester: personId,
		})
		seededCaseId = objectId(seeded)

		// A second case, requested by the COMPANY. The Requester column has to
		// resolve two different pages, so one row cannot prove it.
		await seedCase(api, token, {
			title: `${RUN_PREFIX} Roof terrace`,
			caseType: caseTypeId,
			requester: companyId,
		})

		await createObject(api, token, 'contactmoment', {
			contact: personId,
			notificationChannel: 'phone',
			direction: 'inbound',
			identificationMethod: 'digid',
			kccEmployeeId: 'admin',
			// `informatieverzoek`, not `vraag`. `contactmoment.nature` is an
			// enum of six, and `vraag` is not one of them — the CREATE 400s
			// with "should be one of". It happened in `beforeAll`, so the
			// first execution of this file, on 2026-09-08, reported ONE ✘ at
			// the hook and NINE tests that never started; the file had never
			// run anywhere when it was written, and a hook failure looks
			// nothing like nine failures in the summary line.
			nature: 'informatieverzoek',
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

	test('offers no folder sidebar, and reaches organisations another way', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/contacts`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-index-page')).toBeVisible({
			timeout: 30_000,
		})

		// The folder pane itself, NOT the word. This assertion used to read
		// `getByText(/^(Organisations|Organisaties)$/)` over the whole page,
		// and `contacts-you-can-find` puts an Organisations entry in the
		// NAVIGATION of this very page — so the unscoped match would now find
		// the fix and report it as the defect. A folder that filters this
		// page's brpPerson rows by a kvkCompany schema still shows an empty
		// list, and an empty list still reads as "no organisations" rather
		// than "this folder cannot work yet", so the pane stays absent until
		// nextcloud-vue lets a folder carry its own schema.
		await expect(page.locator('.cn-index-page__folder-pane')).toHaveCount(0)

		// And the way to an organisation is the child entry, visible here
		// without expanding anything.
		const nav = page.locator('[id^="app-navigation"]').first()
		await expect(nav.locator('a[href$="/organisations"]')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('lists organisations under Contacts, spending no top-level slot', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/contacts`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-index-page')).toBeVisible({
			timeout: 30_000,
		})

		const nav = page.locator('[id^="app-navigation"]').first()
		const link = nav.locator('a[href$="/organisations"]')
		await expect(link).toHaveCount(1)
		await link.click()

		await expect(page).toHaveURL(/\/organisations$/)
		await expect(page.locator('.cn-index-page')).toBeVisible({
			timeout: 30_000,
		})

		const row = page.getByRole('row', { name: new RegExp(COMPANY_NAME, 'i') })
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row).toContainText(COMPANY_KVK)
	})

	test('opens an organisation on its own page', async ({ page }) => {
		await page.goto(`/apps/${REGISTER}/organisations`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-index-page')).toBeVisible({
			timeout: 30_000,
		})

		const row = page.getByRole('row', { name: new RegExp(COMPANY_NAME, 'i') })
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.click()

		await expect(page).toHaveURL(new RegExp(`/organisations/${companyId}$`))
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByText(COMPANY_NAME).first()).toBeVisible()
		await expect(page.getByText(COMPANY_KVK).first()).toBeVisible()
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

	test('the Requester column sends a person and an organisation to different pages', async ({
		page,
	}) => {
		// contacts-domain 3.6. The claim under test is that ONE column resolves
		// TWO routes, so one row cannot prove it: a fixed `widgetProps.route`
		// would send both requesters to the same page and still pass a
		// single-row check.
		//
		// Each row is reached by its own EXACT `?title=` deep link rather than
		// both together under a shared filter. The column definition is static
		// in the manifest, so two navigations exercise the same config and a
		// fixed route still fails the second one — and an exact filter returns
		// exactly the seeded row, where a shared filter would return this run's
		// rows plus every other case of the same type and could push either row
		// onto a second page.
		const requesterLink = async (title: string, name: string) => {
			await page.goto(
				`/apps/${REGISTER}/cases?title=${encodeURIComponent(title)}`,
			)
			await dismissSupportDialog(page)
			await expect(page.locator('.cn-index-page')).toBeVisible({
				timeout: 30_000,
			})
			const row = page.getByRole('row', { name: new RegExp(title, 'i') })
			await expect(row).toBeVisible({ timeout: 30_000 })
			const link = row.getByRole('link', { name })
			await expect(link).toBeVisible({ timeout: 30_000 })
			return link
		}

		const personLink = await requesterLink(
			`${RUN_PREFIX} Dormer window`,
			PERSON_NAME,
		)
		await expect(personLink).toHaveAttribute(
			'href',
			new RegExp(`/contacts/${personId}$`),
		)

		const companyLink = await requesterLink(
			`${RUN_PREFIX} Roof terrace`,
			COMPANY_NAME,
		)
		await expect(companyLink).toHaveAttribute(
			'href',
			new RegExp(`/organisations/${companyId}$`),
		)

		// And it is a real in-app route, not an href that reads right and 404s.
		await companyLink.click()
		await expect(page).toHaveURL(new RegExp(`/organisations/${companyId}$`))
		await expect(page.getByText(COMPANY_KVK).first()).toBeVisible({
			timeout: 30_000,
		})
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

		await clickHeaderAction(page, 'cn-action-new-case-for-contact')

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

		await clickHeaderAction(page, 'cn-action-log-contact')

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

	test('a search hit on a contact opens the contact page', async () => {
		// Measured on the dev instance 2026-09-08, BEFORE this change: a
		// unified-search hit on a seeded person already existed (OpenRegister
		// defaults a schema to searchable, so no flag was ever missing) and
		// resolved to `/apps/openregister/api/objects/23/250/<uuid>` — the raw
		// JSON endpoint. The manifest deepLink is what moves it onto the
		// contact page, and a wrong urlTemplate would still return a result:
		// the failure is a 404 one click later, which no assertion on the
		// search itself would see.
		for (const [term, id, prefix] of [
			[PERSON_NAME, personId, '/apps/dossiq/contacts/'],
			[COMPANY_NAME, companyId, '/apps/dossiq/organisations/'],
		] as const) {
			const res = await api.get(
				`/ocs/v2.php/search/providers/openregister_objects/search`
					+ `?term=${encodeURIComponent(term)}&format=json`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			expect(res.ok(), `search for ${term} -> ${res.status()}`).toBeTruthy()

			const entries = (await res.json())?.ocs?.data?.entries ?? []
			const urls = entries.map((e: { resourceUrl?: string }) => e.resourceUrl)
			expect(
				urls,
				`no result for ${term} pointed at ${prefix}${id} (saw ${urls.join(', ')})`,
			).toContain(`${prefix}${id}`)
		}
	})
})
