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
import { trackDossiqErrors } from './helpers/nav.ts'

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

let api: APIRequestContext
let token: string
let caseTypeId: string
let caseTypeName: string

/** Ids of the party rows this run created, for teardown. */
const createdParties: Array<[string, string]> = []

let protectedPersonId = ''
let plainPersonId = ''
let companyId = ''

let protectedCaseId = ''
let plainCaseId = ''
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
		protectedCaseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Beschermde aanvrager`,
				caseType: caseTypeId,
				requester: protectedPersonId,
				initiatorType: 'person',
				initiatorSourceId: PROTECTED.bsn,
				initiatorDisplayName: PROTECTED.name,
			}),
		)
		plainCaseId = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} Gewone aanvrager`,
				caseType: caseTypeId,
				requester: plainPersonId,
				initiatorType: 'person',
				initiatorSourceId: PLAIN.bsn,
				initiatorDisplayName: PLAIN.name,
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
		if (!(await option.first().isVisible().catch(() => false))) {
			await combo.pressSequentially(caseTypeName, { delay: 30 })
		}
		await option.first().click()
	}

	// @e2e openspec/specs/initiator-selection/spec.md
	test('the New case form asks for a requester, and the field is enabled', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		await page.goto(DASHBOARD_URL)

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
	test('the edit form carries the requester field, enabled', async ({ page }) => {
		await page.goto(`${DASHBOARD_URL}cases/${noRequesterCaseId}`)
		await expect(page.locator('.cn-kpi-card').first()).toBeVisible({
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

		await page.goto(`${DASHBOARD_URL}cases/${bareRequesterCaseId}`)
		await expect(page.locator('[data-testid="initiator-name"]')).toHaveText(
			PLAIN.name,
			{ timeout: 30_000 },
		)

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

	// @e2e openspec/specs/initiator-selection/spec.md
	test('a company requester persists as the uuid and the projection', async () => {
		const saved = await showObject(api, 'case', companyCaseId)
		expect(saved.requester, 'the uuid of the kvkCompany row').toBe(companyId)
		expect(saved.initiatorType).toBe('company')
		expect(saved.initiatorSourceId).toBe(COMPANY.kvk)
		expect(saved.initiatorDisplayName).toBe(COMPANY.name)
	})

	// @e2e openspec/specs/initiator-display/spec.md
	test('the case page names the person, the number and the address', async ({
		page,
	}) => {
		await page.goto(`${DASHBOARD_URL}cases/${plainCaseId}`)

		const card = page.locator('[data-testid="initiator-section"]')
		await expect(card).toBeVisible({ timeout: 30_000 })
		await expect(card.locator('[data-testid="initiator-name"]')).toHaveText(
			PLAIN.name,
		)
		await expect(card.locator('[data-testid="initiator-type"]')).toHaveText(
			/Person|Persoon/,
		)
		await expect(
			card.locator('[data-testid="initiator-source-link"]'),
		).toHaveText(PLAIN.bsn)
		// The address comes off the source row, not off the case: a card that
		// renders the projection alone cannot show it.
		await expect(
			card.locator('[data-testid="initiator-address"]'),
			'the address is resolved from the brpPerson row',
		).toContainText('Mandelaplein', { timeout: 20_000 })
		await expect(
			card.locator('[data-testid="initiator-source-link"]'),
		).toHaveAttribute('href', new RegExp(`brpPerson/${plainPersonId}`))
	})

	// @e2e openspec/specs/initiator-display/spec.md
	test('a company card links to the KvK record', async ({ page }) => {
		await page.goto(`${DASHBOARD_URL}cases/${companyCaseId}`)

		const card = page.locator('[data-testid="initiator-section"]')
		await expect(card).toBeVisible({ timeout: 30_000 })
		await expect(card.locator('[data-testid="initiator-name"]')).toHaveText(
			COMPANY.name,
		)
		await expect(card.locator('[data-testid="initiator-type"]')).toHaveText(
			/Company|Bedrijf/,
		)
		await expect(
			card.locator('[data-testid="initiator-source-link"]'),
		).toHaveText(COMPANY.kvk)
		await expect(
			card.locator('[data-testid="initiator-source-link"]'),
		).toHaveAttribute('href', new RegExp(`kvkCompany/${companyId}`))
	})

	// @e2e openspec/specs/initiator-display/spec.md
	test('a case without a requester shows no card', async ({ page }) => {
		await page.goto(`${DASHBOARD_URL}cases/${noRequesterCaseId}`)
		await expect(page.locator('.cn-kpi-card').first()).toBeVisible({
			timeout: 30_000,
		})

		// The page has loaded, so an absent card is a decision rather than a
		// render that has not happened yet.
		await expect(page.locator('[data-testid="initiator-section"]')).toHaveCount(
			0,
		)
	})

	// @e2e openspec/specs/initiator-display/spec.md
	test('the requester is a column on the case list', async ({ page }) => {
		await page.goto(CASES_URL)
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
		)
		const row = page
			.getByRole('row')
			.filter({ hasText: `${RUN_PREFIX} Gewone aanvrager` })
		await expect(row).toHaveCount(1, { timeout: 30_000 })
		await expect(row.first()).toContainText(PLAIN.name, { timeout: 30_000 })
	})

	// @e2e openspec/specs/initiator-display/spec.md
	test('the list filters on the requester name', async ({ page }) => {
		// Filtering is a query, so the honest assertion is on what comes back
		// from the server rather than on what the sidebar looks like: the
		// filter's whole job is to narrow the result set.
		const filtered = await listObjects(api, 'case', {
			initiatorDisplayName: PLAIN.name,
		})
		const titles = filtered.map((c: any) => String(c.title ?? ''))
		expect(
			titles.some((t) => t.includes(`${RUN_PREFIX} Gewone`)),
			'the filtered list holds the case with that requester',
		).toBe(true)
		expect(
			titles.some((t) => t.includes(`${RUN_PREFIX} Bedrijfsaanvrager`)),
			'and not the case with a different requester',
		).toBe(false)

		// The sidebar offers the filter because the field is facetable.
		await page.goto(CASES_URL)
		await expect(page.getByRole('table')).toBeVisible({ timeout: 30_000 })
		await page
			.getByRole('button', { name: /Open sidebar|Filters|Zijbalk/ })
			.first()
			.click()
		await expect(
			page.getByText(/^(Requester|Aanvrager)$/).first(),
			'the sidebar names the requester filter',
		).toBeVisible({ timeout: 15_000 })
	})

	// @e2e openspec/specs/initiator-display/spec.md
	test('a protected BSN is masked, and a reveal is one logged read', async ({
		page,
	}) => {
		const reads: string[] = []
		page.on('request', (request) => {
			const url = request.url()
			if (url.includes('brpPerson') && url.includes('_reason=bsn-reveal')) {
				reads.push(url)
			}
		})

		await page.goto(`${DASHBOARD_URL}cases/${protectedCaseId}`)
		const card = page.locator('[data-testid="initiator-section"]')
		await expect(card).toBeVisible({ timeout: 30_000 })

		await expect(
			card.locator('[data-testid="initiator-protected"]'),
			'a protected person is marked as such',
		).toBeVisible({ timeout: 20_000 })
		await expect(
			card.locator('[data-testid="initiator-source-link"]'),
		).toHaveText(`•••••${PROTECTED.bsn.slice(-4)}`)
		expect(reads, 'nothing is revealed before you ask').toEqual([])

		await card.locator('[data-testid="initiator-reveal"]').click()

		await expect(
			card.locator('[data-testid="initiator-source-link"]'),
		).toHaveText(PROTECTED.bsn, { timeout: 20_000 })
		expect(reads.length, `reads carrying the reason: ${reads.join(' | ')}`).toBe(
			1,
		)
	})

	// @e2e openspec/specs/initiator-display/spec.md
	test('an unprotected person is not masked', async ({ page }) => {
		await page.goto(`${DASHBOARD_URL}cases/${plainCaseId}`)
		const card = page.locator('[data-testid="initiator-section"]')
		await expect(card).toBeVisible({ timeout: 30_000 })

		// The address proves the source row resolved, so an absent marker is
		// an answer rather than a pending request.
		await expect(card.locator('[data-testid="initiator-address"]')).toBeVisible({
			timeout: 20_000,
		})
		await expect(
			card.locator('[data-testid="initiator-source-link"]'),
		).toHaveText(PLAIN.bsn)
		await expect(
			card.locator('[data-testid="initiator-protected"]'),
		).toHaveCount(0)
		await expect(card.locator('[data-testid="initiator-reveal"]')).toHaveCount(0)
	})
})
