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
import { dismissSupportDialog } from './helpers/nav.ts'

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
/** A task on that case, which gets a team of its own. */
let teamTaskId = ''

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
	await page.goto(`/apps/${REGISTER}/cases/${id}`)
	await dismissSupportDialog(page)
	await expect(page.locator('.cn-detail-page')).toBeVisible({ timeout: 30_000 })

	const strip = page.locator('.cn-tabs-widget')
	await expect(strip).toBeVisible({ timeout: 30_000 })
	await strip.getByRole('tab', { name: /Parties|Betrokkenen/ }).click()

	// The open panel INSIDE the tabs widget, not a widget id: CnDetailPage sets
	// `aria-label` to the manifest widget id only on the top-level widgets it
	// lays out, so a widget rendered as a tab child carries no such label and
	// `[aria-label="case-roles"]` matches nothing (#1905, which cost the
	// sibling Communication spec four of its five tests against a tab that
	// rendered correctly). Scope to the strip as well: the sidebar's own panels
	// also carry `role="tabpanel"` and hide with `aria-hidden` rather than
	// `hidden`, so an unscoped query is ambiguous.
	const widget = strip.locator('[role="tabpanel"]:not([hidden])')
	await expect(widget).toBeVisible({ timeout: 20_000 })
	return widget
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
		await page.goto(`${base}${route}?${qs}`)
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

		const whoami = await api.get('/ocs/v2.php/cloud/user?format=json')
		expect(whoami.ok(), `whoami -> ${whoami.status()}`).toBeTruthy()
		currentUser = String((await whoami.json())?.ocs?.data?.id ?? '')
		expect(currentUser, 'the session must resolve to a user id').not.toBe('')

		// REUSE a seeded case type. The `case` schema is archival, so a case
		// cannot be deleted by a user; creating a case type here and deleting
		// it in teardown would leave every case pointing at a type that is
		// gone, which reddens unrelated specs.
		const caseTypes = await listObjects(api, 'caseType')
		expect(
			caseTypes.length,
			'the instance must ship at least one case type',
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

		const task = await createObject(api, token, 'caseTask', {
			title: `${RUN_PREFIX} Parties task`,
			case: teamCaseId,
			assignee: currentUser,
			status: 'available',
		})
		teamTaskId = objectId(task)
	})

	test.afterAll(async () => {
		if (!api) return
		// Roles, role types, the task and the team. The cases are archival and
		// cannot be removed by a user; they carry the family prefix, so
		// global-setup's residue sweep takes them before the next run rather
		// than this teardown failing on a 403 it was never going to win.
		await cleanupRunObjects(api, token, [
			'role',
			'caseTask',
			'roleType',
			'organisatieRol',
		])
		await api.dispose()
	})

	// @e2e openspec/changes/parties-on-the-case/specs/roles-decisions/spec.md#parties-visible-on-the-case
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

	// @e2e openspec/changes/parties-on-the-case/specs/roles-decisions/spec.md#parties-visible-on-the-case
	// @e2e roles-decisions::parties-visible-on-the-case
	test('the Parties tab sits in the strip and the retired Contacts tab does not', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${partiesCaseId}`)
		await dismissSupportDialog(page)
		const strip = page.locator('.cn-tabs-widget')
		await expect(strip).toBeVisible({ timeout: 30_000 })

		await expect(
			strip.getByRole('tab', { name: /Parties|Betrokkenen/ }),
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

	// @e2e openspec/changes/parties-on-the-case/specs/roles-decisions/spec.md#a-case-without-parties-says-so
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

		// The way out of the empty state is on the page, not behind it.
		await expect(
			page.getByRole('button', { name: /Add party|Betrokkene toevoegen/ }),
		).toBeVisible({ timeout: 15_000 })
	})

	// @e2e openspec/changes/parties-on-the-case/specs/roles-decisions/spec.md#add-a-party-with-the-case-prefilled
	// @e2e roles-decisions::add-a-party-with-the-case-prefilled
	test('the Add party form asks for the party fields and never for the case', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${formCaseId}`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await page
			.getByRole('button', { name: /Add party|Betrokkene toevoegen/ })
			.click()

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

	// @e2e openspec/changes/parties-on-the-case/specs/roles-decisions/spec.md#add-a-party-with-the-case-prefilled
	// @e2e roles-decisions::add-a-party-with-the-case-prefilled
	test('a party added from the case carries that case and shows up in the tab', async ({
		page,
	}) => {
		await page.goto(`/apps/${REGISTER}/cases/${formCaseId}`)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		await page
			.getByRole('button', { name: /Add party|Betrokkene toevoegen/ })
			.click()

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
		// @e2e openspec/changes/parties-on-the-case/specs/role-routing-via-or-rbac/spec.md#assign-a-case-to-a-team
		// @e2e role-routing-via-or-rbac::assign-a-case-to-a-team
		test('the case page offers a Team field beside the assignee', async ({
			page,
		}) => {
			await page.goto(`/apps/${REGISTER}/cases/${teamCaseId}`)
			await dismissSupportDialog(page)
			const core = page.locator('[aria-label="case-core"]')
			await expect(core).toBeVisible({ timeout: 30_000 })

			// `Team` is the same word in both languages (it is in
			// tests/l10n/language-neutral-keys.json), so this is safe to assert
			// bare. The field is what makes the assignment possible at all: the
			// property can exist on the schema and still be invisible, because
			// `case-core` names the fields it renders.
			await expect(core).toContainText('Team', { timeout: 15_000 })
		})

		// @e2e openspec/changes/parties-on-the-case/specs/role-routing-via-or-rbac/spec.md#assign-a-case-to-a-team
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

		// @e2e openspec/changes/parties-on-the-case/specs/role-routing-via-or-rbac/spec.md#assign-a-case-to-a-team
		// @e2e role-routing-via-or-rbac::assign-a-case-to-a-team
		test('the Cases sidebar offers a Team facet', async ({ page }) => {
			await openIndex(page, '/cases', { competentAuthority: MINE_MARKER })

			// The index sidebar starts COLLAPSED on this route, so the facet is in
			// the DOM but hidden; assert it is wired up rather than requiring the
			// sidebar to be open, the way the sibling pages.spec does for search.
			//
			// The facet's OPTIONS are the stored values, which for a reference
			// property are uuids until the platform renders label fields for them
			// (the same interim the role-type column carries). What this asserts
			// is that the property is offered as a facet at all — the half that a
			// missing `facetable: true` silently removes.
			await expect(
				page
					.locator('.cn-index-sidebar__filter-label')
					.filter({ hasText: /^Team$/ })
					.first(),
			).toBeAttached({ timeout: 20_000 })
		})

		// @e2e openspec/changes/parties-on-the-case/specs/role-routing-via-or-rbac/spec.md#assign-a-task-to-a-team
		// @e2e role-routing-via-or-rbac::assign-a-task-to-a-team
		test('a task with a team shows it on its row and keeps its assignee', async ({
			page,
		}) => {
			await updateObject(api, token, 'caseTask', teamTaskId, {
				assigneeGroup: teamId,
			})

			const stored = await showObject(api, 'caseTask', teamTaskId)
			expect(
				String(stored.assignee),
				'assigning a team must not clear the personal assignee',
			).toBe(currentUser)

			await openIndex(page, '/tasks', { case: teamCaseId })

			const row = indexRows(page).filter({
				hasText: `${RUN_PREFIX} Parties task`,
			})
			await expect(row).toHaveCount(1, { timeout: 30_000 })
			await expect(row).toContainText(teamName, { timeout: 20_000 })
			await expect(row).toContainText(currentUser)
		})

		// @e2e openspec/changes/parties-on-the-case/specs/role-routing-via-or-rbac/spec.md#mine-shows-only-my-cases
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
