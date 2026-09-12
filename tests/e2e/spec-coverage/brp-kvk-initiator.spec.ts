/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the brp-kvk-register-sets initiator UI
 * (initiator-selection + initiator-display specs). Register-side scenarios
 * (repair import, OR bsn validation, fixture parity against the live mock /
 * KvK test API) carry @e2e excludes in the specs: they are proven by
 * PHPUnit (BrpKvkRegisterSetsTest) and the external-integrations contract
 * lanes. These tests drive the dossiq-owned UI through real clicks: the
 * StartCaseWidget initiator step, cross-source search on the register sets,
 * persistence of the projection fields, and the detail display (including
 * the no-initiator empty case).
 *
 * ⚠️ WHY EVERY TEST HERE WAS `fixme`, AND WHY NONE IS NOW
 * -------------------------------------------------------
 * The six fixme reasons blamed two things, and both were misdiagnosed.
 *
 *  1. "StartCaseWidget is NOT among the Dashboard manifest's widgets." True,
 *     and beside the point: it was looked for in the wrong dashboard.
 *     StartCaseWidget is a NEXTCLOUD Dashboard widget
 *     (`lib/Dashboard/StartCaseWidget.php`, id `procest_start_case_widget`),
 *     not a dossiq manifest widget. It renders on `/apps/dashboard/` when it
 *     is in the user's layout, never at dossiq's app root, which is where
 *     these tests went looking. `beforeAll` puts it in the layout through
 *     the dashboard's own OCS endpoint and `afterAll` puts the old one back.
 *  2. "No BRP / KvK objects exist on a runner." They are not an external
 *     absence. `brpPerson` and `kvkCompany` are schemas in dossiq's OWN
 *     register, and `lib/Settings/register.d/25-brp-kvk.json` seeds twenty
 *     rows including `brp-999990627` (Stephan Janssen) and `kvk-69599084`
 *     (Test EMZ Dagobert). The fixture reuses those rows and creates one only
 *     where the import did not, the way `case-requester.spec.ts` does, so no
 *     mock is involved and none is needed.
 *
 * There WAS a real defect under the first one, and it is fixed in
 * `StartCaseWidget.vue`: mounted standalone on the Nextcloud Dashboard, in
 * its own bundle with an empty type registry, the widget fetched before it
 * registered its object types, so it always answered "No case types
 * configured" and its picker and Skip failed the same way.
 *
 * The widget creates real cases. It titles each one after its case type, so
 * the case type seeded here carries `RUN_PREFIX` and every case the widget
 * makes carries it too, which is what lets `cleanupRunObjects` find and purge
 * them (a `case` is archival and only leaves through occ, see
 * `helpers/fixtures.ts#purgeObject`). Two tests create a case: the persisted
 * pick and the Skip. The other four only open the step.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	purgeObject,
	RUN_PREFIX,
	showObject,
} from '../helpers/fixtures.ts'
import { PAGE_LOAD } from '../helpers/nav.ts'

/** Nextcloud's own dashboard, where StartCaseWidget renders. */
const NC_DASHBOARD_URL = '/index.php/apps/dashboard/'

/**
 * The dashboard's layout endpoint. OCS, so every call carries
 * `OCS-APIRequest: true`: without it the CSRF guard answers 412, which reads
 * as "no session" rather than as a missing header.
 */
const LAYOUT_API = '/ocs/v2.php/apps/dashboard/api/v3/layout?format=json'

/**
 * StartCaseWidget's id. FROZEN at the old app-id prefix, see
 * `lib/Dashboard/StartCaseWidget.php::getId()`, so it does not read `dossiq`.
 */
const WIDGET_ID = 'procest_start_case_widget'

/**
 * The personas the searches look for: an official fictitious personen-mock
 * persona and a KvK-published test company, both seeded by the 25-brp-kvk
 * register fragment.
 */
const PERSON = {
	bsn: '999990627',
	name: 'Stephan Janssen',
	surname: 'Janssen',
	birthDate: '1975-04-06',
}
const COMPANY = { kvk: '69599084', name: 'Test EMZ Dagobert' }

let api: APIRequestContext
let token: string
let caseTypeId = ''
let caseTypeTitle = ''

/** The admin's layout before this file touched it, or null if never read. */
let originalLayout: string[] | null = null

/** Party rows this run CREATED (not the seeded ones it reused). */
const createdParties: Array<[string, string]> = []

/** Cases the widget created, by the id their page URL carried. */
const createdCases: string[] = []

/**
 * Find a party row by its identifying number, or create it.
 *
 * Reused rather than always created, for the reason `case-requester.spec.ts`
 * gives: a second row with the same BSN would make "resolve the source row by
 * number" ambiguous for every other reader. Created only on an instance whose
 * import did not merge register.d (`ci-seed.sh` falls back to the monolith
 * when `settings#load` is unavailable), and removed again in teardown.
 *
 * @param schema The party schema slug.
 * @param field  The identifying field.
 * @param value  The identifying number.
 * @param body   The row to create when none exists.
 * @return The uuid of the row.
 */
async function ensureParty(
	schema: string,
	field: string,
	value: string,
	body: Record<string, unknown>,
): Promise<string> {
	const existing = await listObjects(api, schema, { [field]: value })
	const match = existing.find((row: any) => String(row[field]) === value)
	if (match) {
		return objectId(match)
	}
	const id = objectId(await createObject(api, token, schema, body))
	createdParties.push([schema, id])
	return id
}

/**
 * Read the dashboard layout, as a list of widget ids.
 *
 * @return The widget ids, in order.
 */
async function readLayout(): Promise<string[]> {
	const res = await api.get(LAYOUT_API, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(res.ok(), `read layout -> ${res.status()} ${await res.text()}`).toBe(true)
	const layout = (await res.json())?.ocs?.data?.layout
	expect(Array.isArray(layout), 'the layout answer carries a list').toBe(true)
	return layout
}

/**
 * Replace the dashboard layout.
 *
 * @param layout The widget ids to show, in order.
 */
async function writeLayout(layout: string[]): Promise<void> {
	const res = await api.post(LAYOUT_API, {
		headers: {
			'OCS-APIRequest': 'true',
			requesttoken: token,
			'Content-Type': 'application/json',
		},
		data: { layout },
	})
	expect(res.ok(), `write layout -> ${res.status()} ${await res.text()}`).toBe(
		true,
	)
}

/**
 * Open the Nextcloud Dashboard, pick this run's case type in StartCaseWidget
 * and return the initiator step it opens.
 *
 * The card is found by this run's title, never `.first()`: the widget lists
 * every published case type, and the first one belongs to somebody else.
 *
 * @param page The page.
 * @return The initiator dialog.
 */
async function openInitiatorStep(page: Page): Promise<Locator> {
	await page.goto(NC_DASHBOARD_URL, PAGE_LOAD)
	const card = page.locator('.start-case-widget__card', {
		hasText: caseTypeTitle,
	})
	// The widget registers its stores, then fetches, so the card arrives
	// after two round trips on a page that is loading everything else too.
	await expect(card, 'StartCaseWidget lists the seeded case type').toBeVisible({
		timeout: 60_000,
	})
	await card.click()

	// NcModal renders its `name` as the dialog's h2, so the heading is what
	// tells this dialog from any other the dashboard might open.
	const heading = page.getByRole('heading', { name: 'Who is the initiator?' })
	const dialog = page.getByRole('dialog').filter({ has: heading })
	await expect(dialog).toBeVisible({ timeout: 15_000 })
	return dialog
}

/**
 * The id a reference field holds, whether the API answered it as a bare
 * uuid or as an object carrying one.
 *
 * @param value The reference field.
 * @return The id, or '' when the field is empty.
 */
function refId(value: any): string {
	return String(value?.id ?? value ?? '')
}

/**
 * Wait for the widget's navigation to the case it created, record the id for
 * teardown, and return it.
 *
 * @param page The page.
 * @return The id of the created case.
 */
async function createdCaseId(page: Page): Promise<string> {
	await expect(page).toHaveURL(/\/apps\/dossiq\/cases\/[^/?#]+/, {
		timeout: 30_000,
	})
	const id = new URL(page.url()).pathname.split('/cases/')[1]?.split('/')[0] ?? ''
	expect(id, 'the case URL carries an id').not.toBe('')
	createdCases.push(id)
	return id
}

test.describe('Initiator selection (brp-kvk-register-sets)', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ baseURL }) => {
		test.setTimeout(120_000)
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)

		// A case type this file OWNS, published, with RUN_PREFIX in its title.
		// Not `ensureCaseType`, which adopts a shipped type when it can: the
		// widget titles each case after its type, and a case titled after a
		// shipped type carries nothing a teardown could find it by.
		caseTypeTitle = `${RUN_PREFIX} Startwidget`
		const caseType = await createObject(api, token, 'caseType', {
			title: caseTypeTitle,
			identifier: `${RUN_PREFIX.toLowerCase()}-startwidget`,
			description: 'Throwaway caseType for the StartCaseWidget e2e layer.',
			// The widget fetches `isDraft: false` and the schema defaults the
			// field to TRUE, so a type seeded without this is never offered.
			isDraft: false,
		})
		caseTypeId = objectId(caseType)

		await ensureParty('brpPerson', 'citizenServiceNumber', PERSON.bsn, {
			citizenServiceNumber: PERSON.bsn,
			name: { givenNames: 'Stephan', namePrefix: '', surname: PERSON.surname },
			birth: { date: PERSON.birthDate },
			residence: {
				street: 'Mandelaplein',
				houseNumber: 2,
				postcode: '2572HT',
				city: "'s-Gravenhage",
			},
			displayName: PERSON.name,
			description:
				'Officiele fictieve testpersoon uit de BRP personen-mock: geen echte persoon.',
		})
		await ensureParty('kvkCompany', 'kvkNumber', COMPANY.kvk, {
			kvkNumber: COMPANY.kvk,
			tradeName: COMPANY.name,
			legalForm: 'Eenmanszaak',
			address: { streetName: 'Abebe Bikilalaan', place: 'Amsterdam' },
			description:
				'Fictief testbedrijf van developers.kvk.nl: geen echt bedrijf.',
		})

		// The widget only mounts when it is in the layout. Saved first, so
		// teardown can put back exactly what was there. Shown ALONE rather
		// than appended: every other widget on the page is load time and
		// console noise this file does not assert on.
		originalLayout = await readLayout()
		await writeLayout([WIDGET_ID])
	})

	test.afterAll(async () => {
		// Every step runs whatever the one before it did, and the failures are
		// reported together at the end. Otherwise a throw from the layout
		// restore would skip the purge and leave archival cases on the
		// instance for good.
		const failures: string[] = []

		/**
		 * Purge one object and record it when it survives or cannot be purged.
		 *
		 * @param schema Schema slug.
		 * @param id     Object id.
		 */
		const purge = async (schema: string, id: string): Promise<void> => {
			const gone = await purgeObject(api, token, schema, id).catch(
				(error: unknown) => {
					failures.push(`${schema}/${id}: ${String(error)}`)
					return true
				},
			)
			if (gone === false) failures.push(`${schema}/${id} survived`)
		}

		// The layout first: it is the admin's own dashboard, and a later run
		// on this instance should find it as the admin left it. One residue is
		// stated rather than discovered: a layout that was never set reads
		// back as the system default, so it is restored as an explicit
		// preference holding that same default.
		if (originalLayout !== null) {
			await writeLayout(originalLayout).catch((error: unknown) =>
				failures.push(`dashboard layout: ${String(error)}`),
			)
		}

		// The cases the widget made, by the id their page carried, then the
		// prefix sweep. The sweep also takes the case type and any case whose
		// navigation a failed test never reached, since both carry RUN_PREFIX.
		for (const id of createdCases) {
			await purge('case', id)
		}
		await cleanupRunObjects(api, token).catch((error: unknown) =>
			failures.push(String(error)),
		)

		// Party rows last: a case names its requester, so the row goes after
		// the case. Only rows this run created; seeded ones are not ours.
		for (const [schema, id] of createdParties) {
			await purge(schema, id)
		}
		await api?.dispose()
		expect(
			failures,
			'teardown did not finish, so the next run starts dirty',
		).toEqual([])
	})

	// @e2e openspec/specs/initiator-selection/spec.md#agent-picks-an-initiator-type
	test('start-case flow offers Person / Company / Contact and stays skippable', async ({
		page,
	}) => {
		// The widget's own case type grid, not the New case form, which
		// `case-requester.spec.ts` covers: picking a case type here opens the
		// optional initiator step.
		const dialog = await openInitiatorStep(page)
		await expect(dialog.getByText('Person', { exact: true })).toBeVisible()
		await expect(dialog.getByText('Company', { exact: true })).toBeVisible()
		await expect(dialog.getByText('Contact', { exact: true })).toBeVisible()

		// Optional, both halves: with nothing picked the confirm is disabled
		// and Skip is not, so the only way on without an initiator is open.
		// Clicking Skip, and what it creates, is the last test in this file.
		await expect(
			dialog.getByRole('button', { name: 'Use as initiator' }),
		).toBeDisabled()
		await expect(dialog.getByRole('button', { name: 'Skip' })).toBeEnabled()
	})

	// @e2e openspec/specs/initiator-selection/spec.md#person-search-hits-the-brp-register-set
	test('person search lists a seeded personen-mock persona with BSN', async ({
		page,
	}) => {
		const dialog = await openInitiatorStep(page)
		// Person is the default tab, so this is a search on `brpPerson`.
		await dialog.getByLabel('Search initiator').fill(PERSON.surname)
		const result = dialog
			.locator('.initiator-picker__result', { hasText: PERSON.name })
			.first()
		// "listed with name, birthdate, and BSN": the name matched above, the
		// other two out of the result's detail line.
		await expect(result).toBeVisible({ timeout: 15_000 })
		await expect(result).toContainText(`BSN ${PERSON.bsn}`)
		await expect(result).toContainText(PERSON.birthDate)
	})

	// @e2e openspec/specs/initiator-selection/spec.md#company-search-hits-the-kvk-register-set
	test('company search by pinned KvK number lists the fixture company', async ({
		page,
	}) => {
		const dialog = await openInitiatorStep(page)
		await dialog.getByText('Company', { exact: true }).click()
		// By number, not name: a KvK number is what a handler types off a
		// letterhead, and it is the one field a trade name search cannot reach.
		await dialog.getByLabel('Search initiator').fill(COMPANY.kvk)
		const result = dialog
			.locator('.initiator-picker__result', { hasText: COMPANY.name })
			.first()
		await expect(result).toBeVisible({ timeout: 15_000 })
		await expect(result).toContainText(`KVK ${COMPANY.kvk}`)
	})

	// @e2e openspec/specs/initiator-selection/spec.md#contacts-source-degrades-gracefully
	test('contact tab shows an explicit empty state, never an error toast', async ({
		page,
	}) => {
		const dialog = await openInitiatorStep(page)
		await dialog.getByText('Contact', { exact: true }).click()

		// Wait for the contacts search to ANSWER before judging the result.
		// The picker shows its empty state as soon as a query is typed, so an
		// assertion without this would pass before the source was ever asked,
		// and could not tell a graceful degrade from a search never made. Any
		// status is accepted: an unavailable source is the case under test.
		const answered = page.waitForResponse(
			(res) => res.url().includes('/contactsmenu/contacts'),
			{ timeout: 30_000 },
		)
		await dialog.getByLabel('Search initiator').fill('zzz-no-such-contact-zzz')
		await answered

		await expect(dialog.getByText('No contacts found')).toBeVisible({
			timeout: 15_000,
		})
		// `role="alert"` as well as `.toast-error`. @nextcloud/dialogs 7.5,
		// which dossiq bundles, renders an error toast as an assertive live
		// region with CSS-module classes, so the class selector alone matched
		// nothing even with a toast on screen and this assertion could not
		// fail. The class stays for core's own toasts on this page, which ship
		// with whatever dialogs version the server was built with.
		await expect(page.locator('[role="alert"], .toast-error')).toHaveCount(0)
	})

	// @e2e openspec/specs/initiator-selection/spec.md#selection-persists-on-the-case
	// @e2e openspec/specs/initiator-display/spec.md#initiator-visible-on-the-case
	test('picked persona persists as projection and shows on case detail with source link', async ({
		page,
	}) => {
		const dialog = await openInitiatorStep(page)
		await dialog.getByLabel('Search initiator').fill(PERSON.surname)
		await dialog
			.locator('.initiator-picker__result', { hasText: PERSON.name })
			.first()
			.click()
		await expect(dialog.getByTestId('initiator-picker-selection')).toContainText(
			PERSON.name,
		)
		await dialog.getByRole('button', { name: 'Use as initiator' }).click()
		const caseId = await createdCaseId(page)

		// "the saved case SHALL carry `requester` equal to that `brpPerson`
		// row's uuid". Resolved through the row rather than compared to the
		// uuid `ensureParty` returned: the widget stores the row the search
		// answered, and the claim is that it is THE row for this BSN.
		const saved = await showObject(api, 'case', caseId)
		expect(
			refId(saved.caseType),
			'the case is of the type that was picked',
		).toBe(caseTypeId)
		expect(String(saved.requester ?? ''), 'the requester uuid').not.toBe('')
		const person = await showObject(api, 'brpPerson', String(saved.requester))
		expect(person.citizenServiceNumber).toBe(PERSON.bsn)
		expect(saved.initiatorType).toBe('person')
		expect(saved.initiatorSourceId).toBe(PERSON.bsn)
		expect(saved.initiatorDisplayName).toBe(PERSON.name)

		// The widget sent the browser to the case it made. Detail overview:
		// name, type, and the number linking to the person's page in this app.
		// Not OpenRegister's object viewer any more: that moved with
		// `contacts-domain` (#1947), as case-requester.spec.ts records.
		const card = page.getByTestId('initiator-section')
		await expect(card).toBeVisible({ timeout: 30_000 })
		await expect(card.getByTestId('initiator-name')).toHaveText(PERSON.name)
		await expect(card.getByTestId('initiator-type')).toHaveText(/Person|Persoon/)
		const link = card.getByTestId('initiator-source-link')
		await expect(link).toHaveText(PERSON.bsn)
		await expect(link).toHaveAttribute(
			'href',
			new RegExp(`/contacts/${String(saved.requester)}$`),
		)
	})

	// @e2e openspec/specs/initiator-display/spec.md#no-initiator-no-clutter
	test('a case created without initiator renders no initiator block', async ({
		page,
	}) => {
		const dialog = await openInitiatorStep(page)
		await dialog.getByRole('button', { name: 'Skip' }).click()
		const caseId = await createdCaseId(page)

		// Skip creates the case with no requester: no uuid and no projection,
		// so nothing a later reader could take for a half-picked initiator.
		// This is the widget's Skip path; case-requester.spec.ts seeds its
		// no-requester case through the API, so it never exercises this write.
		const saved = await showObject(api, 'case', caseId)
		expect(
			refId(saved.caseType),
			'the case is of the type that was picked',
		).toBe(caseTypeId)
		expect(saved.requester ?? '').toBe('')
		expect(saved.initiatorType ?? '').toBe('')
		expect(saved.initiatorDisplayName ?? '').toBe('')

		// The tab strip is on every case page unconditionally, so once it is
		// there an absent card is a decision rather than a render still due.
		await expect(page.locator('.cn-tabs-widget')).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByTestId('initiator-section')).toHaveCount(0)
		// ...and the widget cell says so, under its own testid, rather than
		// sitting as a titled empty box.
		await expect(page.getByTestId('initiator-empty')).toBeVisible({
			timeout: 15_000,
		})
	})
})
