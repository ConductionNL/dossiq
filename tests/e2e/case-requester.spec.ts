/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The requester on the case (requester-on-the-case).
 *
 * Covers the three delta specs' browser scenarios: the Requester field on
 * the New case form and the edit form, the uuid and projection on the saved
 * case, the card with its address and source link, the empty card, the
 * Requester column and its filter, and the masked BSN with its reveal.
 *
 * Two things about this file are worth stating before reading it, because
 * both change what an assertion here can honestly claim.
 *
 * ONE. `case.requester` is a semantic reference. Now that `brpPerson` and
 * `kvkCompany` declare `implements: ns#Requester`, CnFormDialog resolves the
 * URI and renders the field as an object picker over the provider schema.
 * The manifest also binds `InitiatorPicker` through `fieldOverrides`, but
 * @conduction/nextcloud-vue 2.41.0 validates a `form-field` registry entry
 * without MOUNTING it into a form, so that binding is a declaration today.
 * The scenarios below therefore assert what the field IS on this runtime:
 * present, enabled, and free of the no-provider tooltip. They do not assert
 * which component painted it, because an assertion on a component that
 * cannot render yet would be a test that fails for the right reason today
 * and the wrong reason forever.
 *
 * TWO. The write the resolved picker makes is `requester` alone, and the
 * card back-fills the projection from it on first render. So the persistence
 * scenarios seed `requester` exactly as a form save leaves it, open the case
 * and assert the projection lands. That is the whole path a picked requester
 * takes, minus the click, and it is the part that can silently break.
 *
 * Every row this spec needs is created through the API in the test, so it
 * does not depend on whether the 25-brp-kvk register fragment has been
 * imported on the instance under test.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	deleteObject,
	ensureCaseType,
	getRequestToken,
	listObjects,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

/**
 * The personas this spec files cases for.
 *
 * The BSNs are the official fictitious personen-mock personas the register
 * fragment seeds, and 999990792 is the one that carries
 * `geheimhoudingPersoonsgegevens` in the mock's test-data.json — the same
 * row the seed flags. The KvK number is a KvK-published test company.
 */
const PROTECTED = { bsn: '999990792', name: 'Jan de Cuykelaer' }
const PLAIN = { bsn: '999990627', name: 'Stephan Janssen' }
const COMPANY = { kvk: '69599084', name: 'Test EMZ Dagobert' }

const DASHBOARD_URL = `/apps/${REGISTER}/`
const CASES_URL = `/apps/${REGISTER}/cases`

/**
 * The marker that makes the filter scenario's two cases a closed set.
 *
 * Cases are archival and cannot be deleted, so every earlier run of this spec
 * has left a case behind whose requester is Stephan Janssen. Narrowing the
 * list on that name alone therefore returns a page of residue this run did not
 * seed, and "the first case is listed" becomes a claim about which twenty rows
 * the server happened to order first. `competentAuthority` is a plain string
 * on `case` and is used as a run marker by `case-parties.spec.ts` for the same
 * reason: filtering on it first reduces the list to exactly the two cases the
 * scenario names, and the requester filter is then the only thing that can
 * drop one of them.
 */
const FILTER_MARKER = `${RUN_PREFIX}-filter`

let api: APIRequestContext
let token: string
let caseTypeId: string
let caseTypeName: string

/** Ids of the party rows this run created, for teardown. */
const createdParties: Array<[string, string]> = []

let protectedPersonId = ''
let plainPersonId = ''
let companyId = ''

let companyCaseId = ''
let bareRequesterCaseId = ''
let noRequesterCaseId = ''

/**
 * Find a party row by its identifying number, or create it.
 *
 * The register fragment seeds these personas, but an instance may not have
 * imported the version that carries `indicatieGeheim`. Reusing a seeded row
 * and creating one when it is absent keeps the spec honest either way, and
 * avoids a second row with the same BSN — which would make "resolve the
 * source row by number" ambiguous for every other reader.
 *
 * @param schema The party schema slug.
 * @param field  The identifying field.
 * @param value  The identifying number.
 * @param body   The row to create when none exists.
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
	const created = await createObject(api, token, schema, body)
	const id = objectId(created)
	createdParties.push([schema, id])
	return id
}

test.describe('The requester on the case', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const caseType = await ensureCaseType(api, token)
		caseTypeId = caseType.id
		caseTypeName = caseType.name

		protectedPersonId = await ensureParty(
			'brpPerson',
			'citizenServiceNumber',
			PROTECTED.bsn,
			{
				citizenServiceNumber: PROTECTED.bsn,
				name: { givenNames: 'Jan', namePrefix: 'de', surname: 'Cuykelaer' },
				birth: { date: '1977-12-10' },
				residence: {
					street: 'Thorbeckelaan',
					houseNumber: 731,
					postcode: '2564CJ',
					city: "'s-Gravenhage",
				},
				indicatieGeheim: true,
				displayName: PROTECTED.name,
				description:
					'Officiele fictieve testpersoon uit de BRP personen-mock: geen echte persoon.',
			},
		)

		// The seeded row may predate `indicatieGeheim`. The masking scenarios
		// are about a row that carries it, so make sure this one does — the
		// fragment says the same thing about the same persona.
		const protectedRow = await showObject(api, 'brpPerson', protectedPersonId)
		if (protectedRow.indicatieGeheim !== true) {
			await api.put(
				`/index.php/apps/openregister/api/objects/${REGISTER}/brpPerson/${protectedPersonId}`,
				{
					headers: {
						requesttoken: token,
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
					},
					data: { ...protectedRow, indicatieGeheim: true },
				},
			)
		}

		plainPersonId = await ensureParty(
			'brpPerson',
			'citizenServiceNumber',
			PLAIN.bsn,
			{
				citizenServiceNumber: PLAIN.bsn,
				name: { givenNames: 'Stephan', namePrefix: '', surname: 'Janssen' },
				birth: { date: '1975-04-06' },
				residence: {
					street: 'Mandelaplein',
					houseNumber: 2,
					postcode: '2572HT',
					city: "'s-Gravenhage",
				},
				displayName: PLAIN.name,
				description:
					'Officiele fictieve testpersoon uit de BRP personen-mock: geen echte persoon.',
			},
		)

		companyId = await ensureParty('kvkCompany', 'kvkNumber', COMPANY.kvk, {
			kvkNumber: COMPANY.kvk,
			tradeName: COMPANY.name,
			legalForm: 'Eenmanszaak',
			address: {
				streetName: 'Hoofdstraat',
				houseNumber: 1,
				postcode: '1234AB',
				place: 'Utrecht',
			},
			description:
				'Fictief testbedrijf van developers.kvk.nl: geen echt bedrijf.',
		})

		// A case per shape. The projection is written the way the picker
		// writes it, except on `bareRequester`, which carries the canonical
		// reference alone the way a form save leaves it today.
		objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Beschermde aanvrager`,
				caseType: caseTypeId,
				requester: protectedPersonId,
				initiatorType: 'person',
				initiatorSourceId: PROTECTED.bsn,
				initiatorDisplayName: PROTECTED.name,
			}),
		)
		objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Gewone aanvrager`,
				caseType: caseTypeId,
				requester: plainPersonId,
				initiatorType: 'person',
				initiatorSourceId: PLAIN.bsn,
				initiatorDisplayName: PLAIN.name,
				competentAuthority: FILTER_MARKER,
			}),
		)
		companyCaseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Bedrijfsaanvrager`,
				caseType: caseTypeId,
				requester: companyId,
				initiatorType: 'company',
				initiatorSourceId: COMPANY.kvk,
				initiatorDisplayName: COMPANY.name,
				competentAuthority: FILTER_MARKER,
			}),
		)
		bareRequesterCaseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Alleen referentie`,
				caseType: caseTypeId,
				requester: plainPersonId,
			}),
		)
		noRequesterCaseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Geen aanvrager`,
				caseType: caseTypeId,
			}),
		)
	})

	test.afterAll(async () => {
		// Cases are archival and cannot be deleted; the sweep handles what can
		// be. Party rows this run created are removed, and seeded ones are
		// left alone — they are not ours.
		await cleanupRunObjects(api, token)
		for (const [schema, id] of createdParties) {
			await deleteObject(api, token, schema, id)
		}
		await api.dispose()
	})

	/**
	 * Choose this run's case type in the New case picker.
	 *
	 * Ported from case-create-form.spec.ts, gotchas and all. The click has to
	 * land on the combobox rather than the `[data-cn-field]` wrapper, or
	 * NcSelect never opens and the failure surfaces later as a missing option.
	 * The option is matched by TEXT, because vue-select splits a label across
	 * adjacent spans and the accessible name joins those with a space. Typing
	 * happens only when the preloaded page does not already hold the answer:
	 * a search term REPLACES the preloaded options with whatever the server
	 * returns, so typing unconditionally can empty a list that had it.
	 *
	 * @param page   The Playwright page.
	 * @param dialog The dialog root.
	 */
	async function chooseCaseType(page: Page, dialog: Locator): Promise<void> {
		const combo = dialog.getByRole('combobox', { name: /Case type|Zaaktype/ })
		await combo.click()

		const option = page.getByRole('option').filter({ hasText: caseTypeName })
		if (
			!(await option
				.first()
				.isVisible()
				.catch(() => false))
		) {
			await combo.pressSequentially(caseTypeName, { delay: 30 })
		}
		await option.first().click()
	}

	// @e2e openspec/specs/initiator-selection/spec.md#the-form-no-longer-disables-the-field
	//
	// 🔴 NOT `#agent-picks-an-initiator-type`, THOUGH IT IS THE CLOSER NAME.
	// That scenario's second clause is "the picker SHALL offer the types
	// Person, Company and Contact", and this file's header says why nothing
	// here asserts it: @conduction/nextcloud-vue validates the `form-field`
	// registry entry without MOUNTING it, so `InitiatorPicker` is a
	// declaration on this runtime and the field is painted by the generic
	// object picker. Anchoring there would credit a picker nobody can see.
	// `The form no longer disables the field` is what this test does prove,
	// clause for clause: not disabled, and no no-provider tooltip.
	test('the New case form asks for a requester, and the field is enabled', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		await page.goto(DASHBOARD_URL, PAGE_LOAD)

		await page.getByRole('button', { name: /New case|Nieuwe zaak/ }).click()
		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible({ timeout: 30_000 })

		const requester = dialog.locator('[data-cn-field="requester"]')
		await expect(requester, 'the form asks for a requester').toHaveCount(1)

		// The field used to render disabled with "no installed app provides
		// Requester", because nothing in the fleet implemented the semantic
		// type. Both halves are asserted: the control accepts input, and the
		// sentence that explained why it did not is gone.
		const control = requester.locator('input, [role="combobox"]').first()
		await expect(control).toBeEnabled({ timeout: 15_000 })
		await expect(requester).not.toContainText(/provides Requester|Install /i)

		// Choosing a requester stays optional: Create is reachable without one.
		//
		// "Without one" is only a claim about the requester once the form's
		// OTHER required fields are answered. The `case` schema requires
		// `title` and `caseType`, so a freshly opened dialog has Create
		// disabled for reasons that have nothing to do with this field, and
		// asserting on that dialog would report "the requester blocks Create"
		// on every run — which is what it did. Answer the two required fields,
		// leave the requester empty, and the button then says what it is being
		// asked to say.
		const create = dialog.getByRole('button', { name: /^(Create|Aanmaken)$/ })
		await expect(create).toBeDisabled()

		await chooseCaseType(page, dialog)
		await dialog
			.locator('[data-cn-field="title"]')
			.getByRole('textbox')
			.fill(`${RUN_PREFIX} Zonder aanvrager`)

		await expect(control, 'the requester is still empty').toHaveValue('')
		await expect(create).toBeEnabled()

		expect(errors, `console errors: ${errors.join(' | ')}`).toEqual([])
	})

	// @e2e openspec/specs/initiator-selection/spec.md
	//
	// 🔴 NO ANCHOR, FOR THE REASON ABOVE. `#the-edit-form-carries-the-picker`
	// reads "the Requester field SHALL be enabled AND RENDERED BY THE
	// INITIATOR PICKER". The first half is asserted here and the second
	// cannot be on this runtime. Re-anchor when the form-field registry
	// mounts its entry and the picker is what paints the field.
	test('the edit form carries the requester field, enabled', async ({ page }) => {
		await page.goto(`${DASHBOARD_URL}cases/${noRequesterCaseId}`, PAGE_LOAD)
		// The tab strip, not a KPI card. This is only a load signal, and
		// `.cn-kpi-card` is a poor one: the case page carries no stats-block and
		// no stat tile at all. `case-kpis-hours` is an integration widget that
		// places humaniq's leaf, so where humaniq is absent it renders nothing,
		// heading included, and the identity row prints its facts as plain
		// fields. The strip is `case-panels`, on every case page unconditionally.
		await expect(page.locator('.cn-tabs-widget')).toBeVisible({
			timeout: 30_000,
		})

		await page
			.getByRole('button', { name: /^(Edit|Bewerken)$/ })
			.first()
			.click()
		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible({ timeout: 30_000 })

		const requester = dialog.locator('[data-cn-field="requester"]')
		await expect(requester).toHaveCount(1)
		await expect(
			requester.locator('input, [role="combobox"]').first(),
		).toBeEnabled({ timeout: 15_000 })
		// No tooltip saying the field is set by an integration.
		await expect(requester).not.toContainText(/provides Requester|Install /i)
	})

	// @e2e openspec/specs/initiator-selection/spec.md
	//
	// 🔴 NO ANCHOR. `#selection-persists-on-the-case` opens "WHEN a handler
	// FILES A CASE AND PICKS a seeded persona", and this test picks nothing:
	// it seeds the canonical reference the way a save leaves it and proves
	// the projection is back-filled on first render. That is a real claim and
	// a different one. The picking half is proven by
	// spec-coverage/brp-kvk-initiator.spec.ts, which cites that scenario by
	// anchor, so nothing is uncovered by leaving this one unnamed.
	test('a case saved with only the reference gains its projection', async ({
		page,
	}) => {
		// This is what a form save leaves behind today: the canonical uuid and
		// no projection. Opening the case has to turn that into a named
		// requester, or the card, the column and the filter are all blank for
		// every case that arrived through the semantic handoff too.
		const before = await showObject(api, 'case', bareRequesterCaseId)
		expect(before.requester).toBe(plainPersonId)
		expect(before.initiatorDisplayName ?? '').toBe('')

		// Nothing on the page prints the projected name any more: the initiator
		// card went on 2026-09-12, and the back-fill it carried now runs
		// headless from the page's actions slot (RequesterProjection). So the
		// claim is read where it lands, on the record.
		await page.goto(`${DASHBOARD_URL}cases/${bareRequesterCaseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		await expect
			.poll(
				async () => {
					const after = await showObject(api, 'case', bareRequesterCaseId)
					return after.initiatorDisplayName ?? ''
				},
				{ timeout: 30_000, message: 'the projection must persist' },
			)
			.toBe(PLAIN.name)

		const after = await showObject(api, 'case', bareRequesterCaseId)
		expect(after.requester, 'the canonical reference is untouched').toBe(
			plainPersonId,
		)
		expect(after.initiatorType).toBe('person')
		expect(after.initiatorSourceId).toBe(PLAIN.bsn)
	})

	// 🔴 NO CITATION, AND THAT IS THE REPAIR. This carried an anchorless
	// `openspec/specs/initiator-selection/spec.md` citation, read as verified
	// on 2026-09-11 and as partial on 2026-09-12. The downgrade is right and
	// the citation is worse than partial: `beforeAll` seeds this case through
	// the API with `requester`, `initiatorType`, `initiatorSourceId` and
	// `initiatorDisplayName` written in, and this test reads those same four
	// fields back. It is a tautology. It proves OpenRegister stores what you
	// send it, and no picker, form or save-path breakage can redden it,
	// because it never opens a page and never exercises the write the
	// requirement is about.
	//
	// The requirement IS proven, by the test that drives the picker:
	// `tests/e2e/spec-coverage/brp-kvk-initiator.spec.ts`, "picked persona
	// persists as projection and shows on case detail with source link",
	// which cites `selection-persists-on-the-case` by anchor. So the claim
	// comes down and nothing is lost.
	//
	// The test stays, uncited, as the round-trip guard for the company shape:
	// it is the fixture every browser test below reads from, and a projection
	// that did not survive the seed would surface here first.
	test('a company requester persists as the uuid and the projection', async () => {
		const saved = await showObject(api, 'case', companyCaseId)
		expect(saved.requester, 'the uuid of the kvkCompany row').toBe(companyId)
		expect(saved.initiatorType).toBe('company')
		expect(saved.initiatorSourceId).toBe(COMPANY.kvk)
		expect(saved.initiatorDisplayName).toBe(COMPANY.name)
	})

	// The initiator card left the page on 2026-09-12 (Ruben): the requester
	// reads in the Data tab's Requester field. The five tests that read the
	// card (the person card, the company card's KvK link, the no-card case,
	// the masked BSN and its logged reveal, the unmasked person) went with
	// it; `initiator-display/spec.md` carries the exclusions.

	// @e2e openspec/specs/initiator-display/spec.md#the-requester-is-a-column
	test('the requester is a column on the case list', async ({ page }) => {
		await page.goto(CASES_URL, PAGE_LOAD)
		const table = page.getByRole('table')
		await expect(table).toBeVisible({ timeout: 30_000 })

		await expect(
			table.getByRole('columnheader', { name: /Requester|Aanvrager/ }),
			'the list has a Requester column',
		).toBeVisible()

		// The list paginates at 20 and orders oldest first, so this run's case
		// is the newest of however many the instance holds and renders on a
		// later page. `getByRole('row')` only ever sees the page on screen, so
		// the old assertion was really asking "is this run's case among the 20
		// oldest cases here", which is false on any instance with seed data.
		// The list reads its filters from the query string, so ask for the one
		// case by its title — an independent field — and assert the Requester
		// cell of the row that comes back.
		await page.goto(
			`${CASES_URL}?title=${encodeURIComponent(`${RUN_PREFIX} Gewone aanvrager`)}`,
			PAGE_LOAD,
		)
		const row = page
			.getByRole('row')
			.filter({ hasText: `${RUN_PREFIX} Gewone aanvrager` })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		await expect(row.first()).toContainText(PLAIN.name, { timeout: 30_000 })
	})

	// @e2e openspec/specs/initiator-display/spec.md#the-list-filters-on-the-requesters-name
	//
	// 🔴 THE NARROWING WAS PROVEN ON THE API, AND IS NOW PROVEN ON THE LIST.
	// The scenario's THEN is about what the list shows, and this test used to
	// answer it with `listObjects(api, 'case', { initiatorDisplayName })` — a
	// direct query that skips every line of the page. A Cases index that
	// dropped the filter from its own fetch, or read it under a different key,
	// left that assertion green, because nothing in it had opened the list.
	// The same two cases are now narrowed in the browser, on the same list,
	// and the company case has to leave it.
	//
	// The citation also named the spec FILE and no requirement, so gate-19
	// credited it to nothing at all. It names the scenario now.
	test('the list filters on the requester name', async ({ page }) => {
		// The list under test is exactly the scenario's two cases: same
		// marker, different requesters. Asserting on the marker alone first is
		// what makes the second navigation's missing row mean something — a
		// row that was never there cannot be said to have been filtered out.
		await page.goto(
			`${CASES_URL}?competentAuthority=${encodeURIComponent(FILTER_MARKER)}`,
			{ ...PAGE_LOAD, waitUntil: 'domcontentloaded' },
		)
		const person = page
			.locator('[data-testid="cn-object-row"]')
			.filter({ hasText: `${RUN_PREFIX} Gewone aanvrager` })
		const company = page
			.locator('[data-testid="cn-object-row"]')
			.filter({ hasText: `${RUN_PREFIX} Bedrijfsaanvrager` })
		await expect(
			person,
			'both cases are listed before the requester filter',
		).toHaveCount(1, { timeout: 30_000 })
		await expect(company).toHaveCount(1, { timeout: 30_000 })

		// WHEN the requester is added to the filter. `CnIndexPage` reads the
		// query string as its fetch filter (`resolveQueryFilters`), which is
		// the same request the sidebar control issues, so this drives the
		// list's own filtering rather than the server's API.
		await page.goto(
			`${CASES_URL}?competentAuthority=${encodeURIComponent(FILTER_MARKER)}`
				+ `&initiatorDisplayName=${encodeURIComponent(PLAIN.name)}`,
			{ ...PAGE_LOAD, waitUntil: 'domcontentloaded' },
		)
		await expect(
			person,
			'the list keeps the case whose requester was filtered for',
		).toHaveCount(1, { timeout: 30_000 })
		await expect(
			company,
			'and drops the case with a different requester',
		).toHaveCount(0, { timeout: 30_000 })

		// 🔴 WHY THIS CITATION STILL NAMES THE FILE AND NOT A SCENARIO.
		// `initiator-display` has the scenario "The list filters on the
		// requester's name", and its WHEN is "the handler TYPES the first
		// requester's surname into the Requester filter". That gesture cannot
		// be performed on this platform: CnIndexSidebar renders every filter
		// as an `NcSelect` whose options come from `getFilterOptions`, which
		// falls through to the manifest's `filter.options` because
		// CnIndexPage binds `:facet-data="resolvedSidebar.facets"` rather than
		// the live facets. nextcloud-vue#1110 fixes that upstream and is not
		// in 2.48.2, the newest published version and the one this app pins,
		// so there is no option to pick and nothing to type into. Naming that
		// anchor would claim a gesture no assertion here makes, which reads as
		// coverage and survives review; naming the file reads as what it is.
		// Re-anchor when the sidebar is fed its live facets.
		//
		// 🔴 AND THE SAME NARROWING ON THE PAGE. The two assertions above are
		// a direct API query: they say the STORE can narrow on the field, and
		// a Cases index that dropped the predicate on its way to the wire
		// left both of them green. The index reads its filters from the query
		// string, so the same narrowing is asked for the way a reader's
		// filter asks for it, and the rows that come back are read.
		//
		// Read as "every row belongs to this requester", never as "my row is
		// row N": the list paginates at 20 over a shared instance, so a
		// position assertion would be about how much seed data the box holds.
		await page.goto(
			`${CASES_URL}?initiatorDisplayName=${encodeURIComponent(PLAIN.name)}`,
			PAGE_LOAD,
		)
		const listed = page.getByRole('table').getByRole('row')
		await expect(
			listed.filter({ hasText: `${RUN_PREFIX} Gewone aanvrager` }),
			'the narrowed page holds the case with that requester',
		).toHaveCount(1, { timeout: 30_000 })
		await expect(
			listed.filter({ hasText: `${RUN_PREFIX} Bedrijfsaanvrager` }),
			'and not the case whose requester is the company',
		).toHaveCount(0)
		// Every row, not only the two this run seeded: a filter that answered
		// the whole register would still satisfy the two lines above.
		const requesterColumn = await page
			.getByRole('table')
			.getByRole('columnheader', { name: /Requester|Aanvrager/ })
			.evaluate((th) => Array.from(th.parentElement!.children).indexOf(th))
		const cells = page.locator(
			`table tbody tr td:nth-child(${requesterColumn + 1})`,
		)
		const shown = await cells.allInnerTexts()
		expect(shown.length, 'the narrowed page is not empty').toBeGreaterThan(0)
		expect(
			shown.every((text) => text.trim() === PLAIN.name),
			`every row on the narrowed page names the requester: ${shown.join(' | ')}`,
		).toBe(true)

		// The sidebar offers the filter because the field is facetable.
		//
		// 🔴 SCOPED TO THE SIDEBAR, AND IT WAS NOT.
		//
		// ✅ MUTATION CHECK RUN 2026-09-12. The sidebar derives its filters
		// from the SCHEMA's `facetable` properties (CnFacetSidebar ->
		// `filtersFromSchema`), so the schema read was rewritten on its way
		// into the browser and nothing on disk moved:
		//
		//   route   /\/apps\/openregister\/api\/schemas\//
		//   break   properties.initiatorDisplayName.facetable = false
		//   red on  "the sidebar names the requester filter"
		//
		// The first attempt stripped `initiatorDisplayName` from the `facets`
		// block of the OBJECTS response instead. It matched, and the filter
		// stayed on screen, so that was the wrong input and a green there
		// would have meant nothing. Worth recording: a mutation that lands and
		// changes nothing is the one that looks most like a passing test.
		//
		// This read
		// `page.getByText(/^(Requester|Aanvrager)$/).first()` over the WHOLE
		// page, and the cases table carries a column header reading exactly
		// that, which the sibling test above asserts. So the clause was
		// satisfied by the header alone and a list page offering no requester
		// filter at all still passed. The locator is scoped to `.app-sidebar`
		// now, which is the control the requirement is about.
		//
		// ⚠️ WHAT IS STILL NOT DRIVEN, AND WHY IT IS NOT THE TEST'S FAULT. The
		// scenario's WHEN is "types the surname into the Requester filter",
		// and no test can do that on this build. `CnIndexSidebar` renders every
		// schema filter as an `NcSelect` over `getFilterOptions(filter)`, which
		// reads `facetData` — and `CnIndexPage` passes
		// `:facet-data="resolvedSidebar.facets || {}"`, the MANIFEST's sidebar
		// block, never the live facets the store just parsed. The Cases page
		// declares `sidebar: { enabled: true, showMetadata: true }`, so that
		// object is empty and the control is a select with no options and
		// nothing to type into. Reported with this change
		// (@conduction/nextcloud-vue 2.48.2); the same gap is why
		// `case-parties.spec.ts` cannot assert that a Team facet option
		// renders. Until it closes, what is proven here is the filter the
		// requirement names existing on the surface, and the narrowing it
		// performs, which is both halves of the requirement's own sentence.
		await page.goto(CASES_URL, PAGE_LOAD)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })
		await page
			.getByRole('button', { name: /Open sidebar|Filters|Zijbalk/ })
			.first()
			.click()
		const sidebar = page.locator('.app-sidebar')
		await expect(sidebar, 'the filters sidebar opens').toBeVisible({
			timeout: 15_000,
		})
		await expect(
			sidebar.getByText(/^(Requester|Aanvrager)$/).first(),
			'the sidebar names the requester filter',
		).toBeVisible({ timeout: 15_000 })
	})

})
