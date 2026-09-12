/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Parties tab, the Add party action, and the team a case or a task
 * belongs to.
 *
 * The `role` schema and its rows existed long before this change; what did not
 * exist was any surface that read them for ONE case. `RolesTab.vue` lists every
 * role in the register from admin settings, so a passing admin test says
 * nothing about the case page. This spec is what tells a working
 * `case = @objectId` filter apart from the two things that look identical on
 * screen: a widget whose query fails, and a case that genuinely has no parties.
 * Both render the same empty state, so the seeded rows are asserted by text
 * this spec wrote, the empty case additionally asserts that its query came back
 * under 400, and a saved role is read back over the API rather than inferred
 * from the page.
 *
 * Locale: nothing forces the language of the E2E instance, so tab and button
 * names are matched in either locale the app ships, the way the sibling
 * case-detail specs do. Everything else is matched on an id, a `data-testid`,
 * or on data this spec seeded. `Team` is the one English word asserted bare: it
 * is listed in tests/l10n/language-neutral-keys.json because it is the same
 * word in Dutch.
 */

import type { APIRequestContext, Page } from '@playwright/test'

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
	updateObject,
} from './helpers/fixtures.ts'
import {
	clickHeaderAction,
	dismissSupportDialog,
	openHeaderActionsMenu,
	PAGE_LOAD,
} from './helpers/nav.ts'

/** The fields the Add party form asks a handler to fill. */
const FORM_FIELDS = [
	'name',
	'roleType',
	'participant',
	'delegate',
	'delegateUntil',
	'description',
]

const HANDLER_ROLE_NAME = `${RUN_PREFIX} handler role`
const ADVISOR_ROLE_NAME = `${RUN_PREFIX} advisor role`
const TYPED_ROLE_NAME = `${RUN_PREFIX} typed role`

const HANDLER_PARTICIPANT = `${RUN_PREFIX}-handler-person`
const ADVISOR_PARTICIPANT = `${RUN_PREFIX}-advisor-person`
const OTHER_PARTICIPANT = `${RUN_PREFIX}-other-case-person`
const TYPED_PARTICIPANT = `${RUN_PREFIX}-typed-person`
const DELEGATE_PARTICIPANT = `${RUN_PREFIX}-stand-in`

const DELEGATE_FROM = '2026-05-01T00:00:00+00:00'
const DELEGATE_UNTIL = '2026-07-31T00:00:00+00:00'

/**
 * A value written to `competentAuthority` (a plain facetable string) on the
 * two cases the Mine chip test seeds, so the index can be narrowed to exactly
 * those two through a `?key=value` deep link. Without it the chip would have
 * to be judged against a demo-seeded case list that changes per instance.
 */
const MINE_MARKER = `${RUN_PREFIX}-mine`

/**
 * The same trick for the Team facet test, for a second reason. OpenRegister
 * caches a facet response per query for an hour (`FacetHandler`). Since
 * openregister#3560 an object write invalidates it, but an instance on an
 * OpenRegister without that fix serves the unfiltered Cases index a Team facet
 * computed before this run assigned anything, measured on a dev rig. A query
 * no earlier request has made is computed fresh on either.
 */
const TEAM_MARKER = `${RUN_PREFIX}-team`

/** The user the E2E session is signed in as, read from the instance. */
let currentUser = ''

let api: APIRequestContext
let token: string
let caseTypeId = ''
let handlerTypeId = ''
let advisorTypeId = ''
let teamId = ''
let teamName = ''

/** The case the Parties tab is read on: a handler and an advisor. */
let partiesCaseId = ''
/** A second case with a party of its own, which must never show up. */
let otherCaseId = ''
/** A case with no roles at all. */
let emptyCaseId = ''
/** A case the Add party form writes to, kept apart so the row is unambiguous. */
let formCaseId = ''
/** A case that gets a team. */
let teamCaseId = ''

/**
 * Seed one role through the object API.
 *
 * @param onCase       The case the role is on.
 * @param name         The display name (carries RUN_PREFIX so teardown finds it).
 * @param roleTypeId   The role type row.
 * @param participant  The participant.
 * @param delegation   Optional delegate plus window.
 */
async function seedRole(
	onCase: string,
	name: string,
	roleTypeId: string,
	participant: string,
	delegation?: { delegate: string; from: string; until: string },
): Promise<string> {
	const created = await createObject(api, token, 'role', {
		name,
		roleType: roleTypeId,
		case: onCase,
		participant,
		...(delegation === undefined
			? {}
			: {
					delegate: delegation.delegate,
					delegateFrom: delegation.from,
					delegateUntil: delegation.until,
				}),
	})
	return objectId(created)
}

/**
 * Open a case and switch to its Parties tab.
 *
 * The tab panels are LAZY: the widget does not mount, and therefore does not
 * query, until its tab is opened. Without the click every assertion below
 * would time out on an unmounted panel and read as a broken filter.
 *
 * @param page The Playwright page.
 * @param id   The case id to open.
 */
async function openPartiesTab(page: Page, id: string) {
	await page.goto(`/apps/${REGISTER}/cases/${id}`, PAGE_LOAD)
	await dismissSupportDialog(page)
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
	return await openCasePanel(page, 'parties')
}

/**
 * Open an index route with a query string, under either URL shape.
 *
 * `helpers/nav.ts#navToRoute` probes `/apps/dossiq` and `/index.php/apps/dossiq`
 * because only one of them is inside the router base on a given instance, and
 * the wrong one silently lands on the Dashboard. This does the same probe and
 * keeps the query string, which CnIndexPage reads as a fetch filter
 * (`resolveQueryFilters`).
 *
 * @param page  The Playwright page.
 * @param route The in-app route, e.g. `/cases`.
 * @param query The filter query params.
 */
async function openIndex(
	page: Page,
	route: string,
	query: Record<string, string>,
): Promise<void> {
	const qs = new URLSearchParams(query).toString()
	for (const base of [`/apps/${REGISTER}`, `/index.php/apps/${REGISTER}`]) {
		// `domcontentloaded`, NOT the default `load`. Nextcloud's notification
		// poll keeps the network busy, so the load event waits for something
		// that does not settle on a loaded rig: every test in this file died
		// here at load average 50 while the page itself rendered. The SPA
		// mounts after DOM ready and the list assertion below proves the mount.
		await page.goto(`${base}${route}?${qs}`, {
			...PAGE_LOAD,
			waitUntil: 'domcontentloaded',
		})
		await dismissSupportDialog(page)
		if (new URL(page.url()).pathname.endsWith(route)) {
			await expect(
				page.locator('[data-testid="cn-object-list"]').first(),
			).toBeVisible({ timeout: 30_000 })
			return
		}
	}
	throw new Error(`neither URL shape resolved the ${route} route`)
}

/** The rows of the index table. */
function indexRows(page: Page) {
	return page.locator('[data-testid="cn-object-row"]')
}

test.describe('Case detail — the Parties tab', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// `OCS-APIRequest` is not optional: without it Nextcloud's CSRF guard
		// answers a plain OCS GET with 412, which reads as "no session" rather
		// than as a missing header.
		const whoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
		currentUser = String((await whoami.json())?.ocs?.data?.id ?? '')
		expect(currentUser, 'the session must resolve to a user id').not.toBe('')

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

		const [handlerType, advisorType, team] = await Promise.all([
			createObject(api, token, 'roleType', {
				name: `${RUN_PREFIX} Handler`,
				description: 'Throwaway role type seeded by case-parties.spec.',
				caseType: caseTypeId,
				genericRole: 'handler',
			}),
			createObject(api, token, 'roleType', {
				name: `${RUN_PREFIX} Advisor`,
				description: 'Throwaway role type seeded by case-parties.spec.',
				caseType: caseTypeId,
				genericRole: 'advisor',
			}),
			createObject(api, token, 'organisatieRol', {
				roleName: `${RUN_PREFIX} Team Permits`,
				roleType: 'ambtelijk',
				department: 'Ruimte',
				team: 'Permits',
			}),
		])
		handlerTypeId = objectId(handlerType)
		advisorTypeId = objectId(advisorType)
		teamId = objectId(team)
		teamName = String(team.roleName)

		const seeded = await Promise.all([
			seedCase(api, token, {
				title: `${RUN_PREFIX} Parties`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Parties other`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Parties empty`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Parties form`,
				caseType: caseTypeId,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Parties team`,
				caseType: caseTypeId,
				assignee: currentUser,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Parties mine`,
				caseType: caseTypeId,
				assignee: currentUser,
				competentAuthority: MINE_MARKER,
			}),
			seedCase(api, token, {
				title: `${RUN_PREFIX} Parties colleague`,
				caseType: caseTypeId,
				assignee: `${RUN_PREFIX}-colleague`,
				competentAuthority: MINE_MARKER,
			}),
		])
		// The last two (mine / colleague) are found by title on the index, so
		// only the ids the tests navigate to are kept.
		;[partiesCaseId, otherCaseId, emptyCaseId, formCaseId, teamCaseId] = seeded
			.slice(0, 5)
			.map(objectId)

		await seedRole(
			partiesCaseId,
			HANDLER_ROLE_NAME,
			handlerTypeId,
			HANDLER_PARTICIPANT,
			{
				delegate: DELEGATE_PARTICIPANT,
				from: DELEGATE_FROM,
				until: DELEGATE_UNTIL,
			},
		)
		await seedRole(
			partiesCaseId,
			ADVISOR_ROLE_NAME,
			advisorTypeId,
			ADVISOR_PARTICIPANT,
		)
		await seedRole(
			otherCaseId,
			`${RUN_PREFIX} other case role`,
			handlerTypeId,
			OTHER_PARTICIPANT,
		)
	})

	test.afterAll(async () => {
		if (!api) return
		// Roles, role types and the team. The cases are archival and
		// cannot be removed by a user; they carry the family prefix, so
		// global-setup's residue sweep takes them before the next run rather
		// than this teardown failing on a 403 it was never going to win.
		await cleanupRunObjects(api, token, ['role', 'roleType', 'organisatieRol'])
		await api.dispose()
	})

	// @e2e openspec/specs/roles-decisions/spec.md#parties-visible-on-the-case
	// @e2e roles-decisions::parties-visible-on-the-case
	test('the tab lists this case parties with their delegation, and not another case one', async ({
		page,
	}) => {
		const widget = await openPartiesTab(page, partiesCaseId)

		const rows = widget.locator('tbody tr')
		await expect(rows).toHaveCount(2, { timeout: 20_000 })

		// The delegated party: participant, delegate and the end of the
		// delegation window all read off one row. The role-type cell renders
		// the reference's uuid until nextcloud-vue renders label fields for
		// $ref columns (triage #8), so the row is identified by its
		// participant, which is data this spec wrote.
		const handler = rows.filter({ hasText: HANDLER_PARTICIPANT })
		await expect(handler).toHaveCount(1)
		await expect(handler).toContainText(DELEGATE_PARTICIPANT)
		// The YEAR of the delegation end, which proves the cell is bound
		// without pinning a locale's date format.
		await expect(handler).toContainText(/2026/)

		// The party with no delegate: present, and not carrying the other
		// row's delegate.
		const advisor = rows.filter({ hasText: ADVISOR_PARTICIPANT })
		await expect(advisor).toHaveCount(1)
		await expect(advisor).not.toContainText(DELEGATE_PARTICIPANT)

		// The other case's party exists and is filtered out. Without the
		// filter this widget would list every role on the instance, which on a
		// demo-seeded install still looks plausible.
		await expect(widget.getByText(OTHER_PARTICIPANT)).toHaveCount(0)
	})

	// No citation, on purpose. This test used to carry
	// `roles-decisions::parties-visible-on-the-case` twice, and it never opens
	// the tab or reads a row, so it stayed green on a Parties list that showed
	// nothing (e2e-citation-integrity, audit group 3). The scenario is proven
	// by the test above, which opens the tab and reads both parties with their
	// delegation. What this one guards, which tabs the strip carries, is not a
	// clause of that scenario, so the citation came off rather than being
	// copied onto a second test that proves the same rows.
	test('the Parties tab sits in the strip and the retired Contacts tab does not', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${partiesCaseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })

		// Parties is a section of the People tab now, not a tab.
		await expect(
			strip.getByRole('tab', { name: 'People', exact: true }),
		).toBeVisible({ timeout: 15_000 })
		// The tab the Parties tab replaced: the Nextcloud contacts integration
		// leaf, which showed the address book and never the people on this
		// case.
		await expect(
			strip.getByRole('tab', {
				name: /^(Contacts|Contacten|Connected contacts)$/,
			}),
		).toHaveCount(0)
	})

	// @e2e openspec/specs/roles-decisions/spec.md#a-case-without-parties-says-so
	// @e2e roles-decisions::a-case-without-parties-says-so
	test('a case without parties shows the empty state and the Add party action', async ({
		page,
	}) => {
		// A widget whose query fails renders an empty state too, so the
		// REQUEST is asserted beside the text. Without it this test passes on
		// a 404 and the tab looks correct while showing nothing it should.
		const statuses: number[] = []
		page.on('response', (r) => {
			if (r.url().includes('/objects/dossiq/role')) statuses.push(r.status())
		})

		const widget = await openPartiesTab(page, emptyCaseId)

		await expect(widget).toContainText(
			/No parties on this case yet|Nog geen betrokkenen bij deze zaak/,
			{ timeout: 20_000 },
		)
		await expect
			.poll(() => statuses.length, { timeout: 20_000 })
			.toBeGreaterThan(0)
		expect(
			statuses.every((s) => s < 400),
			`role queries: ${statuses.join(',')}`,
		).toBe(true)

		// The way out of the empty state is on the page, not behind it —
		// in the header's Actions menu, which has to be opened before the
		// entry can be seen at all.
		await openHeaderActionsMenu(page)
		await expect(page.getByTestId('cn-action-add-party')).toBeVisible({
			timeout: 15_000,
		})
	})

	// No citation, on purpose. `add-a-party-with-the-case-prefilled` has two
	// THENs and both are about the SAVED row: it shows up in the list, and it
	// references the case. This test saves nothing, so it could not fail on
	// either (e2e-citation-integrity, audit group 3). The test below saves a
	// party and reads the stored `case` back, and that is where the scenario
	// is proven. This one guards the field list REQ-ROLE-008 names, which no
	// scenario states.
	test('the Add party form asks for the party fields and never for the case', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${formCaseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-add-party')

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
		// `case` is seeded through the action's props: the handler opened the
		// form FROM the case, so being asked which case it is about would be
		// the form forgetting.
		await expect(dialog.locator('[data-cn-field="case"]')).toHaveCount(0)
	})

	// @e2e openspec/specs/roles-decisions/spec.md#add-a-party-with-the-case-prefilled
	// @e2e roles-decisions::add-a-party-with-the-case-prefilled
	test('a party added from the case carries that case and shows up in the tab', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${formCaseId}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await clickHeaderAction(page, 'cn-action-add-party')

		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		await dialog
			.locator('[data-cn-field="name"]')
			.getByRole('textbox')
			.fill(TYPED_ROLE_NAME)
		await dialog
			.locator('[data-cn-field="participant"]')
			.getByRole('textbox')
			.fill(TYPED_PARTICIPANT)

		// The role-type picker is a reference select over `roleType`. The
		// option is this spec's own seeded row, so the choice does not depend
		// on the instance's demo data or on its language.
		await dialog
			.locator('[data-cn-field="roleType"]')
			.getByRole('combobox')
			.click()
		await page
			.getByRole('option')
			.filter({ hasText: `${RUN_PREFIX} Advisor` })
			.click()

		await dialog
			.getByRole('button', { name: /^(Create|Save|Aanmaken|Opslaan)$/ })
			.click()

		// The SAVED OBJECT, not the prefilled field. `props` seeding the case
		// into the form is the interim while nextcloud-vue cannot pass a
		// list's filter into its create form (task 2.2), so what is asserted
		// here is the outcome that has to hold either way.
		let saved: any
		await expect(async () => {
			const rows = await listObjects(api, 'role', { _limit: '200' })
			saved = rows.find((r) => String(r.name ?? '') === TYPED_ROLE_NAME)
			expect(saved, 'the role should have been created').toBeTruthy()
		}).toPass({ timeout: 30_000 })

		const stored = await showObject(api, 'role', objectId(saved))
		expect(String(stored.case)).toBe(formCaseId)
		expect(String(stored.participant)).toBe(TYPED_PARTICIPANT)
		expect(String(stored.roleType)).toBe(advisorTypeId)

		const widget = await openPartiesTab(page, formCaseId)
		await expect(
			widget.locator('tbody tr').filter({ hasText: TYPED_PARTICIPANT }),
		).toHaveCount(1, { timeout: 20_000 })
	})

	// The team half of the change. Nested inside the same describe on purpose:
	// `beforeAll` runs per describe, so a sibling block would start with no API
	// context and no seeded rows at all.
	test.describe('the team on a case and on a task', () => {
		// @e2e openspec/specs/role-routing-via-or-rbac/spec.md#assign-a-case-to-a-team
		// @e2e role-routing-via-or-rbac::assign-a-case-to-a-team
		//
		// MUTATION CHECK, NOT YET RUN (the permission is pending), so this
		// citation is unverified. Each line names the break and the assertion
		// that must redden; restore after.
		//   src/manifest.json `case-core` include: drop "assignedGroup"
		//     -> "the Data panel offers a Team field"
		//   src/manifest.json Cases Team column: key "assignedGroup.roleName" -> "assignedGroup.x"
		//     -> "the Team column must show the team picked on the case page, by name"
		test('a team picked on the case page is stored and shows in the Team column', async ({
			page,
		}) => {
			// The scenario's WHEN, done the way a handler does it: on the case
			// page, not over the API. This test used to assert only that the word
			// Team appeared somewhere in the Data panel, which a label with a
			// broken editor, or an editor that saves nothing, both satisfy.
			await page.goto(`/apps/${REGISTER}/cases/${teamCaseId}`, {
				...PAGE_LOAD,
				waitUntil: 'domcontentloaded',
			})
			await dismissSupportDialog(page)
			// `case-core` is the strip's `Data` tab, not a laid-out widget, so
			// it carries no `aria-label`, the same trap `openPartiesTab` above
			// documents for `case-roles`.
			const core = await openCasePanel(page, 'data')

			// `Team` is the same word in both languages (it is in
			// tests/l10n/language-neutral-keys.json), so it is safe to match
			// bare.
			const cell = core.locator('.cn-object-data-widget__cell').filter({
				has: page.locator('.cn-object-data-widget__label', {
					hasText: /^Team$/,
				}),
			})
			await expect(cell, 'the Data panel offers a Team field').toHaveCount(1, {
				timeout: 15_000,
			})
			await cell.locator('.cn-object-data-widget__value').click()
			const editor = core.locator('.cn-object-data-widget__editor')
			await expect(editor).toBeVisible({ timeout: 15_000 })

			await editor.getByRole('combobox').first().click()
			// The options are the `organisatieRol` rows. They are matched on the
			// team's name OR its uuid: `organisatieRol` declares no name field,
			// so OpenRegister names each row by its uuid and the picker lists
			// uuids (reported with this change). The scenario's THENs are about
			// what is STORED and what the index shows, and those are asserted
			// below either way.
			await page
				.getByRole('option')
				.filter({ hasText: new RegExp(`${teamName}|${teamId}`) })
				.first()
				.click({ timeout: 30_000 })
			// Choosing the option IS the save: a relation field commits on
			// selection and closes its editor, so there is no confirm button to
			// press. Whether it saved is read off the stored case below, which
			// is also what fails if a later widget version starts to need one.

			// The stored reference, not the rendered label.
			await expect
				.poll(
					async () => {
						const stored = await showObject(api, 'case', teamCaseId)
						const group = stored.assignedGroup
						return String(
							typeof group === 'object' && group !== null
								? objectId(group)
								: (group ?? ''),
						)
					},
					{
						message:
							'the team picked on the case page must be stored on the case',
						timeout: 30_000,
					},
				)
				.toBe(teamId)
			expect(
				String((await showObject(api, 'case', teamCaseId)).assignee),
				'picking a team must not clear the personal assignee',
			).toBe(currentUser)

			// THEN: the Cases index shows the team's NAME in the Team column.
			const stored = await showObject(api, 'case', teamCaseId)
			await openIndex(page, '/cases', {
				identifier: String(stored.identifier),
			})
			const row = indexRows(page).filter({
				hasText: `${RUN_PREFIX} Parties team`,
			})
			await expect(row).toHaveCount(1, { timeout: 30_000 })
			await expect(
				row,
				'the Team column must show the team picked on the case page, by name',
			).toContainText(teamName, { timeout: 20_000 })
		})

		// @e2e openspec/specs/role-routing-via-or-rbac/spec.md#assign-a-case-to-a-team
		// @e2e role-routing-via-or-rbac::assign-a-case-to-a-team
		test('a case with a team shows it in the Team column and keeps its assignee', async ({
			page,
		}) => {
			await updateObject(api, token, 'case', teamCaseId, {
				assignedGroup: teamId,
			})

			const stored = await showObject(api, 'case', teamCaseId)
			expect(
				String(stored.assignee),
				'assigning a team must not clear the personal assignee',
			).toBe(currentUser)

			await openIndex(page, '/cases', {
				identifier: String(stored.identifier),
			})

			const row = indexRows(page).filter({
				hasText: `${RUN_PREFIX} Parties team`,
			})
			await expect(row).toHaveCount(1, { timeout: 30_000 })
			// The NAME, not the uuid: the column reads `assignedGroup.roleName`
			// off the reference OpenRegister expanded through `extend`. A bare
			// $ref column renders the raw uuid, which looks like data.
			await expect(row).toContainText(teamName, { timeout: 20_000 })
			await expect(row).toContainText(currentUser)
			await expect(
				page.getByRole('columnheader', { name: 'Team' }),
			).toBeVisible()
		})

		// 🔴 NO CITATION, AND THE MEASUREMENT IS WHY. This carried
		// `role-routing-via-or-rbac#assign-a-case-to-a-team`, whose second
		// THEN is "the Team facet SHALL list Team Permits with a count of
		// one". That is a claim about what a reader is SHOWN, and nothing on
		// this page shows it.
		//
		// Measured on the shared instance 2026-09-12, signed in as admin on
		// the Cases page with the sidebar open: the Team filter opens and its
		// dropdown holds exactly one entry, "No results". Case type behaves
		// identically, so it is the binding and not the data. CnIndexPage
		// passes `:facet-data="resolvedSidebar.facets || {}"`, which is the
		// MANIFEST's sidebar config; the live facets the store just parsed
		// never reach `getFilterOptions`. (The `isSelfFetchMode` branch that
		// DOES read `list.facets` feeds `folderSidebarFacetValues`, the folder
		// pane, not the filter list.) nextcloud-vue#1110 fixes it upstream and
		// is not in 2.48.2, the newest published version and the one this app
		// pins.
		//
		// So the citation comes down rather than claiming a rendered list
		// nobody can see. The scenario keeps its own spec-side pointer at this
		// file, and its first THEN, the Team column, is proven by the two
		// tests above.
		//
		// (That sentence deliberately does not spell the directive out. The
		// audit extractor matches the token anywhere in a comment, prose
		// included, and a line break after it made the target parse as `//`,
		// so this paragraph was being counted as a broken citation. Prose
		// about citations should not look like one.)
		//
		// The test stays, and proves what IS true: OpenRegister computes the
		// facet, counts one for this team over two marked cases, and that
		// count matches what filtering on the team actually returns. Re-cite
		// it when the sidebar is fed its live facets.
		//
		// STILL NOT RUN (the permission is pending): the server-side mutation
		// for the facet itself. Break, then the assertion that must redden:
		//   lib/Settings/dossiq_register.json `case.assignedGroup.facetable: false`,
		//   imported with `version` pinned on both the break and the restore
		//     -> "the Team facet must list the team with a count of one"
		test('the Team facet lists the team with a count of one', async ({
			page,
		}) => {
			// Self-contained rather than relying on the test above having run:
			// a retry re-runs `beforeAll`, which seeds a fresh case and team.
			//
			// TWO cases carry the marker and only one carries the team, so a
			// count of one is the facet counting cases per team, not the list
			// being one row long.
			await updateObject(api, token, 'case', teamCaseId, {
				assignedGroup: teamId,
				competentAuthority: TEAM_MARKER,
			})
			await updateObject(api, token, 'case', emptyCaseId, {
				competentAuthority: TEAM_MARKER,
			})

			// The facet as OpenRegister computed it for the page. This test
			// used to assert only that a label reading Team was attached to a
			// collapsed sidebar, which survives a facet that lists nothing.
			const buckets: any[] = []
			page.on('response', async (r) => {
				if (!r.url().includes(`/objects/${REGISTER}/case?`)) return
				if (!r.url().includes('_facets')) return
				try {
					const body = await r.json()
					const facet = body?.facets?.assignedGroup
					buckets.push(...(facet?.data?.buckets ?? facet?.buckets ?? []))
				} catch {
					// A body that is not JSON carries no facet.
				}
			})

			await openIndex(page, '/cases', { competentAuthority: TEAM_MARKER })
			await expect(
				indexRows(page),
				'both marked cases are listed, so the facet is counted over two',
			).toHaveCount(2, { timeout: 30_000 })

			// OpenRegister answers `results`, and nextcloud-vue's store
			// normalises that to `count`; the listener sees whichever shape the
			// page received.
			await expect
				.poll(
					() => {
						const mine = buckets.find(
							(b) => String(b.key ?? b.value ?? '') === teamId,
						)
						return mine === undefined
							? null
							: Number(mine.results ?? mine.count)
					},
					{
						message:
							'the Team facet must list the team with a count of one',
						timeout: 30_000,
					},
				)
				.toBe(1)

			// 🔴 AND THE COUNT HAS TO MEAN SOMETHING. A bucket saying 1 is a
			// number in a payload until the filter it stands for is applied:
			// the scenario's claim is that picking Team Permits gets you the
			// one case that has it. So the facet is USED as a filter, over the
			// same two marked cases, and the list that comes back has to be
			// exactly the one the bucket counted.
			await openIndex(page, '/cases', {
				competentAuthority: TEAM_MARKER,
				assignedGroup: teamId,
			})
			const withTeam = indexRows(page).filter({
				hasText: `${RUN_PREFIX} Parties team`,
			})
			const withoutTeam = indexRows(page).filter({
				hasText: `${RUN_PREFIX} Parties empty`,
			})
			await expect(
				withTeam,
				'filtering on the team returns the case that carries it',
			).toHaveCount(1, { timeout: 30_000 })
			await expect(
				withoutTeam,
				'and not the other marked case, so the count of one is a count',
			).toHaveCount(0)

			// 🔴 AND THE SIDEBAR SHOWS NONE OF IT. The rendered Team filter is
			// asserted NOWHERE here because it lists nothing to assert:
			// `CnIndexPage` passes `:facet-data="resolvedSidebar.facets || {}"`,
			// which is the MANIFEST's sidebar config and never the live facets
			// the store just parsed, so `getFilterOptions` falls through to
			// `filter.options` and every filter in the sidebar renders "No
			// results" — Team, Case type, Status and the rest alike. Measured
			// on this instance with the bucket above present in the response,
			// and re-checked 2026-09-12: nextcloud-vue#1110 fixes it upstream
			// but the newest published version is 2.48.2, which this app pins
			// and which still binds `resolvedSidebar.facets`. Two more
			// presentation defects sit behind it: `organisatieRol` declares no
			// name field, so OpenRegister labels the bucket with a shortened
			// uuid rather than Team Permits, and the Team cell on the case
			// page renders that uuid too.
			//
			// So the scenario's "lists Team Permits" half is still NOT proven
			// on screen, and cannot be until dossiq takes a nextcloud-vue that
			// feeds the sidebar its live facets. What is proven is that the
			// facet exists, counts one, and that the one it counts is the one
			// case the filter returns.
		})

		// 🔴 REMOVED 2026-09-11: `a task with a team shows it on its row and
		// keeps its assignee` (remove-casetask 5.1).
		//
		// Its subject no longer exists. The test wrote `caseTask.assigneeGroup`
		// and read a Team column off the Tasks index. dossiq#2408 retyped that
		// index to OpenRegister's task engine (`entitySource: "tasks"`), and
		// the engine has no `assigneeGroup` to write or to read: it models a
		// pool as candidate group LISTS (`candidateGroups`), so there is no
		// single group a column could bind to and the source ships no Team
		// column. The scenario's other half — assigning a team to a CASE and
		// seeing it in the Cases Team column — is untouched and still covered
		// by the two tests above.
		//
		// This leaves `role-routing-via-or-rbac::assign-a-task-to-a-team` with
		// no browser coverage. Rewriting it against the engine would be a new
		// claim about candidate pools rather than this one restored, and the
		// spec has to decide what team routing MEANS on the engine first.

		// @e2e openspec/specs/role-routing-via-or-rbac/spec.md#mine-shows-only-my-cases
		// @e2e role-routing-via-or-rbac::mine-shows-only-my-cases
		test('the Mine chip narrows the list to my own cases', async ({ page }) => {
			// Two cases, one mine and one a colleague's, both carrying the same
			// marker so the list under test is exactly these two whatever else the
			// instance holds.
			await openIndex(page, '/cases', { competentAuthority: MINE_MARKER })

			const mine = indexRows(page).filter({
				hasText: `${RUN_PREFIX} Parties mine`,
			})
			const colleague = indexRows(page).filter({
				hasText: `${RUN_PREFIX} Parties colleague`,
			})

			// The index opens on the All chip: both cases are listed. A Mine chip
			// that shipped without an All chip beside it would leave the index
			// filtered to the signed-in handler from the first paint, which is the
			// regression this half catches.
			await expect(mine).toHaveCount(1, { timeout: 30_000 })
			await expect(colleague).toHaveCount(1, { timeout: 30_000 })

			const chips = page.locator('.cn-quick-filter-bar')
			await expect(chips).toBeVisible({ timeout: 20_000 })
			await chips.getByRole('tab', { name: /Mine|Van mij/ }).click()

			await expect(colleague).toHaveCount(0, { timeout: 30_000 })
			await expect(mine).toHaveCount(1)
		})
	})
})
