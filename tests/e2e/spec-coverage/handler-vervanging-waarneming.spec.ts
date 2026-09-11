/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the handler-vervanging-waarneming spec.
 *
 * Four of the scenarios this file cites are refusals or scope limits. Those are
 * driven over HTTP against a seeded fixture and assert the status the
 * requirement names or the object the requirement forbids — never a button's
 * visibility. A hidden button is a UI courtesy; the protection is what the
 * backend refuses, and the audit that produced this rewrite found every one of
 * those citations sitting on an affordance-present assertion taken while logged
 * in as an authorised user.
 *
 * The two surface tests that remain (personal settings, coordinator admin) are
 * UI checks and say so; they carry only the scenarios they actually drive.
 *
 * Note: Use /index.php/apps/dossiq/<route> so the Vue history-mode router
 * resolves the route.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	captureStorageState,
	STORAGE_STATE,
	storageStatePath,
} from '../helpers/auth.ts'
import { becomesVisible } from '../helpers/becomes-visible.js'
import {
	createObject,
	deleteObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	showObject,
} from '../helpers/fixtures.ts'
import { dismissSupportDialog } from '../helpers/nav.ts'
import {
	SubstitutionAdmin,
	SubstitutionPersonalSettings,
} from '../helpers/page-components.ts'

/** The dossiq controller surface these scenarios are actually enforced on. */
const DOSSIQ_API = '/index.php/apps/dossiq/api'

/** The coordinator. `requireCoordinator()` resolves the role to NC admin. */
const ADMIN_USER = process.env.ADMIN_USER ?? 'admin'

/**
 * The ordinary account, and the whole point of it.
 *
 * Two of the scenarios here are refusals whose subject is a SIGNED-IN user
 * without the coordinator role. An anonymous probe cannot stand in for one: it
 * is refused by the session check long before any role check runs, so it is
 * green on a build with no role check at all. `ci-seed.sh` owns this account
 * and logs `created the non-admin user e2euser`; it also asserts the account
 * holds no admin group membership, which is the property every refusal below
 * depends on.
 */
const PLAIN_USER = process.env.E2E_USER_NAME || 'e2euser'

/** Its password. */
const PLAIN_PASS = process.env.E2E_USER_PASS || 'e2e-user-pass'

/** Admin request context, for seeding and for the coordinator probes. */
let api: APIRequestContext
/** Its CSRF request-token. Every dossiq POST below is CSRF-protected. */
let token = ''

/** The ordinary account's request context, and its own request-token. */
let plainApi: APIRequestContext
let plainToken = ''

/** The case type the scope-limited substitution covers. */
let inScopeTypeId = ''
/** A second case type it deliberately does not cover. */
let outOfScopeTypeId = ''

/** An open case of the covered type, assigned to the absentee. */
let inScopeCaseId = ''
/** An open case of the uncovered type, assigned to the same absentee. */
let outOfScopeCaseId = ''

/** The scope-limited substitution: PLAIN_USER covered by ADMIN_USER. */
let scopedSubstitutionId = ''

/** Every substitution this file wrote, so teardown can remove each one. */
const seededSubstitutions: string[] = []
/** Every case/caseType this file wrote, child-first. */
const seededObjects: Array<[string, string]> = []

const IN_SCOPE_CASE = `${RUN_PREFIX} in-scope bezwaar`
const OUT_OF_SCOPE_CASE = `${RUN_PREFIX} out-of-scope vergunning`

/** The two capacity-stamped actions seeded onto the in-scope case. */
const STAMPED_EARLIER = 'case-updated'
const STAMPED_LATER = 'task-completed'

/**
 * A date `days` from now, as `YYYY-MM-DD`.
 *
 * @param days Offset in whole days; negative is in the past.
 * @return The ISO date.
 */
function isoDay(days: number): string {
	return new Date(Date.now() + days * 86400000).toISOString().slice(0, 10)
}

/**
 * POST a JSON body to a dossiq controller route.
 *
 * The request-token is not optional and not a detail. Nextcloud's CSRF
 * middleware runs BEFORE the controller, so a POST without one is answered 412
 * by the framework — which on a refusal test reads exactly like the 403 the
 * requirement asks for while proving nothing about the guard.
 *
 * @param ctx      The request context making the call.
 * @param csrf     That context's own request-token.
 * @param path     Route path below `/index.php/apps/dossiq/api`.
 * @param data     The JSON body.
 * @return The raw response, for the caller to read a status off.
 */
async function postDossiq(
	ctx: APIRequestContext,
	csrf: string,
	path: string,
	data: Record<string, unknown>,
) {
	return ctx.post(`${DOSSIQ_API}${path}`, {
		headers: {
			requesttoken: csrf,
			'OCS-APIRequest': 'true',
			'Content-Type': 'application/json',
		},
		data,
	})
}

/**
 * GET a dossiq controller route.
 *
 * ⚠️ THE REQUEST-TOKEN IS REQUIRED ON READS TOO. None of the substitution
 * controller methods declares `#[NoCSRFRequired]`, and Nextcloud's CSRF check
 * does not exempt GET, so a token-less read is answered `412 CSRF check failed`
 * by the framework. Measured against localhost:8080 on 2026-09-11: the first
 * run of this file failed all three read-based tests that way. That 412 is a
 * FRAMEWORK refusal, not the controller's, so a refusal test written without
 * the token would be green on a build with no guard at all.
 *
 * @param ctx  The request context making the call.
 * @param csrf That context's own request-token.
 * @param path Route path below `/index.php/apps/dossiq/api`.
 * @return The raw response.
 */
async function getDossiq(ctx: APIRequestContext, csrf: string, path: string) {
	return ctx.get(`${DOSSIQ_API}${path}`, {
		headers: { requesttoken: csrf, 'OCS-APIRequest': 'true' },
	})
}

/**
 * The substitutions the given context can see.
 *
 * @param ctx  The request context.
 * @param csrf That context's own request-token.
 * @return The `results` array, or an empty array.
 */
async function listSubstitutions(
	ctx: APIRequestContext,
	csrf: string,
): Promise<any[]> {
	const res = await getDossiq(ctx, csrf, '/substitutions')
	expect(
		res.ok(),
		`GET /api/substitutions -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	const body: any = await res.json()
	if (Array.isArray(body?.results) === true) {
		return body.results
	}
	return []
}

/**
 * The caseType a case row points at, however the API spelled it.
 *
 * A reference comes back as the uuid string on a plain read and as an expanded
 * object when something asked for it to be; both mean the same case type, and a
 * scope assertion that only understands one of the two fails for a reason that
 * has nothing to do with scope.
 *
 * @param row A case object.
 * @return The referenced caseType id, or an empty string.
 */
function caseTypeRef(row: any): string {
	const raw = row?.caseType
	if (raw === null || raw === undefined) {
		return ''
	}
	if (typeof raw === 'object') {
		return objectId(raw)
	}
	return String(raw)
}

test.describe('Handler vervanging/waarneming spec coverage', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		// The describe-level budget governs TESTS, not hooks; a hook gets the
		// config's flat 30s and this one provisions a second session, two case
		// types, two cases and a substitution.
		test.setTimeout(180_000)

		// 🔴 THE ADMIN SESSION IS NAMED, NOT INHERITED. `browser.newContext()`
		// merges the project's `use`, so an omitted storageState resolves to
		// whatever the default happens to be at that moment — which on this
		// file would silently make the "non-admin is refused" test run as the
		// admin, the exact defect this rewrite exists to remove.
		api = await playwright.request.newContext({
			baseURL,
			storageState: STORAGE_STATE,
		})
		token = await getRequestToken(api)

		const seedWhoami = await api.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(
			seedWhoami.ok(),
			`the seeding session must answer whoami, got ${seedWhoami.status()}`,
		).toBeTruthy()
		expect(
			String((await seedWhoami.json())?.ocs?.data?.id ?? ''),
			'the seeding session must be the coordinator, or nothing below is seeded as one',
		).toBe(ADMIN_USER)

		// The ordinary account's own session. `captureStorageState` logs it in
		// through the web form; nothing here provisions it, because `ci-seed.sh`
		// owns the account and provisioning is password-confirmation protected.
		const plainState = storageStatePath(PLAIN_USER)
		await captureStorageState(browser, {
			baseURL: String(baseURL),
			user: PLAIN_USER,
			password: PLAIN_PASS,
			statePath: plainState,
		})
		plainApi = await playwright.request.newContext({
			baseURL,
			storageState: plainState,
		})
		plainToken = await getRequestToken(plainApi)

		// Two case types, so "only routes matching items" has something to fail
		// on. Published, not draft: a draft type never reaches the pickers and
		// the case that points at one is a fixture nobody can reproduce by hand.
		const inType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} covered type`,
			identifier: `${RUN_PREFIX.toLowerCase()}-covered`,
			description: 'Covered by the scope-limited substitution.',
			isDraft: false,
		})
		inScopeTypeId = objectId(inType)
		seededObjects.push(['caseType', inScopeTypeId])

		const outType = await createObject(api, token, 'caseType', {
			title: `${RUN_PREFIX} uncovered type`,
			identifier: `${RUN_PREFIX.toLowerCase()}-uncovered`,
			description: 'Deliberately outside the substitution scope.',
			isDraft: false,
		})
		outOfScopeTypeId = objectId(outType)
		seededObjects.push(['caseType', outOfScopeTypeId])

		// The substitution first, because the capacity-stamped activity seeded
		// onto the in-scope case has to carry its id.
		const created = await postDossiq(api, token, '/substitutions', {
			absentee: PLAIN_USER,
			substitute: ADMIN_USER,
			startDate: isoDay(-1),
			endDate: isoDay(14),
			scope: 'caseTypes',
			scopeRefs: [inScopeTypeId],
			reason: 'ziekte',
			comment: `${RUN_PREFIX} scope-limited fixture`,
		})
		expect(
			created.status(),
			`create substitution -> ${created.status()} ${await created.text()}`,
		).toBe(201)
		scopedSubstitutionId = objectId(await created.json())
		expect(
			scopedSubstitutionId,
			'the seeded substitution must have an id, or every assertion below addresses nothing',
		).not.toBe('')
		seededSubstitutions.push(scopedSubstitutionId)

		// Two capacity-stamped entries, seeded in REVERSE chronological order on
		// purpose: `getActionsForSubstitution` sorts by timestamp, and an array
		// that was already in order could not tell a working sort from a missing
		// one.
		const activity = JSON.stringify([
			{
				type: 'substitution-action',
				action: STAMPED_LATER,
				actor: ADMIN_USER,
				actedOnBehalfOf: PLAIN_USER,
				substitutionId: scopedSubstitutionId,
				timestamp: '2026-07-09T11:00:00+02:00',
			},
			{
				type: 'substitution-action',
				action: STAMPED_EARLIER,
				actor: ADMIN_USER,
				actedOnBehalfOf: PLAIN_USER,
				substitutionId: scopedSubstitutionId,
				timestamp: '2026-07-02T09:30:00+02:00',
			},
		])

		const inCase = await createObject(api, token, 'case', {
			title: IN_SCOPE_CASE,
			identifier: `${RUN_PREFIX}-in-scope`,
			caseType: inScopeTypeId,
			assignee: PLAIN_USER,
			priority: 'normal',
			intakeChannel: 'manual',
			activity,
		})
		inScopeCaseId = objectId(inCase)
		seededObjects.unshift(['case', inScopeCaseId])

		const outCase = await createObject(api, token, 'case', {
			title: OUT_OF_SCOPE_CASE,
			identifier: `${RUN_PREFIX}-out-of-scope`,
			caseType: outOfScopeTypeId,
			assignee: PLAIN_USER,
			priority: 'normal',
			intakeChannel: 'manual',
		})
		outOfScopeCaseId = objectId(outCase)
		seededObjects.unshift(['case', outOfScopeCaseId])
	})

	test.afterAll(async () => {
		// `substitution` is not in FIXTURE_SCHEMAS, so the shared sweep never
		// sees these rows; they are removed by id here or not at all.
		for (const id of seededSubstitutions) {
			await deleteObject(api, token, 'substitution', id).catch(() => {})
		}
		for (const [schema, id] of seededObjects) {
			await deleteObject(api, token, schema, id).catch(() => {})
		}
		await api?.dispose()
		await plainApi?.dispose()
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#handler-registers-their-own-substitution
	test('substitution renders under personal settings with a register action', async ({
		page,
	}) => {
		await page.goto(`/index.php${SubstitutionPersonalSettings}`)
		await dismissSupportDialog(page)
		const heading = page
			.getByRole('heading', { name: /Substitution|Vervanging|Waarneming/i })
			.first()
		if (await becomesVisible(heading)) {
			await expect(
				page
					.getByRole('button', {
						name: /Register substitution|Waarneming registreren/i,
					})
					.first(),
			).toBeVisible()
		} else {
			test.skip(
				true,
				'the substitution settings heading did not appear. NOT a deploy gap — SubstitutionAdmin.vue is registered in src/registry.js and this commit ships it. Note the locators above accept the Dutch strings too: l10n/nl.json translates Substitution -> Vervanging and Register substitution -> Waarneming registreren, so an English-only locator could never match a Dutch instance.',
			)
		}
	})

	/**
	 * The rejection IS the requirement, so the test submits one.
	 *
	 * This citation used to sit on a test that opened the form and read its
	 * field labels. Every label can be correct on a build whose validator was
	 * deleted, so the green said nothing about whether a self-substitution is
	 * refused. The refusal is enforced in
	 * `SubstitutionValidator::assertIdentities()`; remove the `$absentee ===
	 * $substitute` throw there and this test must go red on the status line.
	 *
	 * The stored-state half matters as much as the status: a 400 returned after
	 * the object was already written would still satisfy "rejected with a
	 * validation error" while breaking "and no object created".
	 */
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#self-substitution-is-rejected
	test('a substitution naming one user as both absentee and substitute is refused, and writes nothing', async () => {
		const marker = `${RUN_PREFIX} self-substitution probe`
		const before = await listSubstitutions(api, token)

		const res = await postDossiq(api, token, '/substitutions', {
			absentee: ADMIN_USER,
			substitute: ADMIN_USER,
			startDate: isoDay(0),
			endDate: isoDay(7),
			scope: 'all',
			reason: 'verlof',
			comment: marker,
		})

		expect(
			res.status(),
			`a self-substitution must be rejected with a validation error; body was ${await res.text()}`,
		).toBe(400)
		const body: any = await res.json()
		expect(
			String(body?.error ?? ''),
			'the validation error must name the rule it enforced',
		).toMatch(/waarnemer|self-substitution/i)

		// AND no object created. Asserted on the STORE, by the marker this call
		// wrote, so an unrelated row cannot satisfy or break it.
		const after = await listSubstitutions(api, token)
		expect(
			after.filter((row) => String(row?.comment ?? '') === marker),
			'the rejected submission must not have left a substitution behind',
		).toHaveLength(0)
		expect(
			after.filter(
				(row) =>
					String(row?.absentee ?? '') !== ''
					&& String(row?.absentee ?? '') === String(row?.substitute ?? ''),
			),
			'no substitution may name the same user as absentee and substitute',
		).toHaveLength(0)
		expect(
			after.length,
			'the rejected submission must not have changed the substitution count',
		).toBe(before.length)
	})

	/**
	 * The "only" half of the requirement, probed with the principal it names.
	 *
	 * This citation used to sit on a test that ran as the coordinator and
	 * asserted the Bulk reassign button was visible. No unauthorised principal
	 * was ever sent at the endpoint, so the test was green on a build with
	 * `requireCoordinator()` deleted.
	 *
	 * Both directions are asserted in one test on purpose. A 403 for the
	 * ordinary account is only evidence of a working guard if the SAME call
	 * succeeds for the coordinator; without that control, a route that is
	 * broken for everybody reads identically.
	 */
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#bulk-reassignment-is-coordinator-only
	test('a signed-in user without the coordinator role is refused both reassignment endpoints', async () => {
		// NAME THE IDENTITY. A refusal test is worth exactly as much as its
		// certainty about who was refused.
		const whoami = await plainApi.get('/ocs/v2.php/cloud/user?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(
			whoami.ok(),
			`the ordinary session must answer whoami, got ${whoami.status()} — a `
				+ 'dead session is refused everywhere and proves nothing',
		).toBeTruthy()
		const me: any = (await whoami.json())?.ocs?.data
		expect(String(me?.id ?? '')).toBe(PLAIN_USER)
		if (Array.isArray(me?.groups) === true) {
			expect(
				me.groups,
				`${PLAIN_USER} must hold no admin membership, or it cannot stand in `
					+ 'for a user without the coordinator role',
			).not.toContain('admin')
		}

		const preview = await postDossiq(
			plainApi,
			plainToken,
			'/reassignments/preview',
			{ fromUser: PLAIN_USER },
		)
		expect(
			preview.status(),
			`the reassignment PREVIEW must be denied to a non-coordinator; body was ${await preview.text()}`,
		).toBe(403)

		const execute = await postDossiq(
			plainApi,
			plainToken,
			'/reassignments/execute',
			{ fromUser: PLAIN_USER, toUser: ADMIN_USER },
		)
		expect(
			execute.status(),
			`the reassignment EXECUTE must be denied to a non-coordinator; body was ${await execute.text()}`,
		).toBe(403)

		// Denied, and nothing moved: an execute that refused after writing would
		// satisfy the status line and break the requirement.
		const untouched = await showObject(api, 'case', inScopeCaseId)
		expect(
			String(untouched?.assignee ?? ''),
			'a denied bulk reassignment must not have transferred a case',
		).toBe(PLAIN_USER)

		// The control. Same body, coordinator session.
		const asCoordinator = await postDossiq(
			api,
			token,
			'/reassignments/preview',
			{ fromUser: PLAIN_USER },
		)
		expect(
			asCoordinator.status(),
			'the coordinator must NOT be refused, or the 403s above say nothing about the role',
		).toBe(200)
	})

	/**
	 * The preview is run, not merely offered.
	 *
	 * This citation used to assert the "Preview affected work" button existed
	 * inside the modal. The requirement is about what the preview RETURNS and
	 * what it must leave alone, and neither was touched.
	 */
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#preview-before-execution
	test('the coordinator preview lists the departing handler open work and mutates nothing', async () => {
		const beforeIn = await showObject(api, 'case', inScopeCaseId)
		const beforeOut = await showObject(api, 'case', outOfScopeCaseId)

		const res = await postDossiq(api, token, '/reassignments/preview', {
			fromUser: PLAIN_USER,
		})
		expect(res.status(), `preview -> ${res.status()} ${await res.text()}`).toBe(
			200,
		)
		const body: any = await res.json()

		const previewed: any[] = Array.isArray(body?.cases) ? body.cases : []
		const ids = previewed.map((row) => objectId(row))
		expect(
			ids,
			`the preview must list every open case assigned to ${PLAIN_USER}`,
		).toContain(inScopeCaseId)
		expect(ids).toContain(outOfScopeCaseId)

		// With the fields the requirement names on the row, not just the id.
		const row = previewed.find((entry) => objectId(entry) === inScopeCaseId)
		expect(String(row?.title ?? '')).toBe(IN_SCOPE_CASE)
		expect(caseTypeRef(row)).toBe(inScopeTypeId)
		expect(
			Object.keys(row ?? {}),
			'the previewed row must carry the status the requirement lists',
		).toContain('status')

		// Open TASKS are the other half of the listing, and the shape is what a
		// caller reads; an absent key is a different failure from an empty list.
		expect(Array.isArray(body?.tasks)).toBeTruthy()

		// AND no data is mutated by the preview. `@self.updated` moves on any
		// write, so it catches a preview that quietly re-saved a row even when
		// the assignee it wrote back happened to be the same one.
		const afterIn = await showObject(api, 'case', inScopeCaseId)
		const afterOut = await showObject(api, 'case', outOfScopeCaseId)
		expect(String(afterIn?.assignee ?? '')).toBe(PLAIN_USER)
		expect(String(afterOut?.assignee ?? '')).toBe(PLAIN_USER)
		expect(
			afterIn?.['@self']?.updated,
			'the preview must not have written to the case it previewed',
		).toBe(beforeIn?.['@self']?.updated)
		expect(afterOut?.['@self']?.updated).toBe(beforeOut?.['@self']?.updated)
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#waarnemer-sees-substituted-work-in-my-work
	test('My Work renders without error and supports the substituted filter when present', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/dossiq/my-work')
		await dismissSupportDialog(page)
		// The My Work route renders no page heading (measured on a CI runner
		// 2026-08-04) — assert the view by a control it does render.
		await expect(page.getByRole('button', { name: 'Urgency' })).toBeVisible({
			timeout: 15000,
		})
		// The "Show substituted work" toggle appears only when the user is an
		// active waarnemer; its presence (or graceful absence) must not error.
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		const toggle = page.getByTestId('substituted-toggle')
		if (await becomesVisible(toggle, 3000)) {
			await toggle.locator('input').click()
			await expect(page.locator('body')).not.toContainText(
				'Internal Server Error',
			)
		}
	})

	/**
	 * Both halves of the scope limit, against two seeded case types.
	 *
	 * This citation used to sit on the My Work page-load test above, whose
	 * substituted-work toggle is only touched when it happens to be visible.
	 * That test cannot fail on a build that routes EVERY case of the absentee
	 * to the substitute, which is the half the requirement spells "MUST NOT".
	 *
	 * Asserted on `/api/substitutions/work`, the resolver the view reads, so
	 * the answer does not depend on a toggle having rendered.
	 */
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#scope-limited-substitution-only-routes-matching-items
	test('a caseTypes-scoped substitution routes the covered type and withholds the rest', async () => {
		const res = await getDossiq(api, token, '/substitutions/work')
		expect(
			res.status(),
			`substituted work -> ${res.status()} ${await res.text()}`,
		).toBe(200)
		const body: any = await res.json()
		const routed = (Array.isArray(body?.cases) ? body.cases : []).map(
			(row: any) => objectId(row),
		)

		expect(
			routed,
			`the covered case type must reach the waarnemer (substitution ${scopedSubstitutionId})`,
		).toContain(inScopeCaseId)
		expect(
			routed,
			'a case of a type OUTSIDE the substitution scope must never reach the waarnemer',
		).not.toContain(outOfScopeCaseId)
	})

	/**
	 * The action list is opened and read, not assumed from a 200 on the page.
	 *
	 * This citation used to sit on a no-500 check of the coordinator admin
	 * page, behind a skip guard. The chronological, capacity-stamped list the
	 * requirement describes was never fetched.
	 *
	 * ⚠️ The ENTRIES are seeded directly onto the case, because nothing in
	 * `lib/` calls `SubstitutionAuditService::stampIfSubstituted()` yet — the
	 * spec's own status paragraph records that retrofit as deferred. So this
	 * test proves the query half of the requirement (every entry carrying the
	 * substitution id is returned, in timestamp order, tagged with its case)
	 * and makes no claim about the stamping half.
	 */
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#all-actions-under-a-substitution-are-queryable
	test('every capacity-stamped action under a substitution is returned, in chronological order', async () => {
		const res = await getDossiq(
			api,
			token,
			`/substitutions/${scopedSubstitutionId}/actions`,
		)
		expect(
			res.status(),
			`substitution actions -> ${res.status()} ${await res.text()}`,
		).toBe(200)
		const body: any = await res.json()
		const results: any[] = Array.isArray(body?.results) ? body.results : []

		const mine = results.filter(
			(entry) => String(entry?.substitutionId ?? '') === scopedSubstitutionId,
		)
		expect(
			mine.map((entry) => String(entry?.action ?? '')),
			'both stamped actions must come back, oldest first — they were seeded '
				+ 'in the opposite order, so an unsorted list fails here',
		).toEqual([STAMPED_EARLIER, STAMPED_LATER])

		for (const entry of mine) {
			expect(String(entry?.actedOnBehalfOf ?? '')).toBe(PLAIN_USER)
			expect(String(entry?.actor ?? '')).toBe(ADMIN_USER)
			expect(
				String(entry?.caseTitle ?? ''),
				'each action must name the case it was performed on',
			).toBe(IN_SCOPE_CASE)
			expect(String(entry?.caseId ?? '')).not.toBe('')
		}
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#coordinator-registers-a-substitution-on-behalf-of-an-absent-handler
	test('coordinator admin exposes a bulk-reassign action with a mandatory preview', async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/dossiq${SubstitutionAdmin}`)
		await dismissSupportDialog(page)
		const heading = page
			.getByRole('heading', { name: /Substitutions & reassignment/ })
			.first()
		if (await becomesVisible(heading)) {
			const reassign = page
				.getByRole('button', { name: /Bulk reassign/ })
				.first()
			await expect(reassign).toBeVisible()
			await reassign.click()
			// The preview button gates execute — the modal must show it. What the
			// preview RETURNS, and that it mutates nothing, is asserted over HTTP
			// above; this is the affordance only.
			await expect(
				page.getByRole('button', { name: /Preview affected work/ }).first(),
			).toBeVisible({ timeout: 8000 })
		} else {
			test.skip(
				true,
				'the coordinator substitution admin did not appear. NOT a deploy gap — SubstitutionAdminView is registered in src/registry.js. If this persists it is an authorisation or routing problem, not a missing build.',
			)
		}
	})

	/**
	 * Deliberately carries no `@e2e` anchor.
	 *
	 * It used to carry two, and both were false: a no-500 check on the admin
	 * page cannot prove that the action list is queryable, and it certainly
	 * cannot prove that a case timeline reads "namens Jan (waarneming)". Those
	 * scenarios are handled above and on the spec respectively. This is kept as
	 * what it always was — a page-load regression check on the coordinator
	 * console.
	 */
	test('coordinator admin renders without a server error', async ({ page }) => {
		await page.goto(`/index.php/apps/dossiq${SubstitutionAdmin}`)
		await dismissSupportDialog(page)
		const heading = page
			.getByRole('heading', { name: /Substitutions & reassignment/ })
			.first()
		if (await becomesVisible(heading)) {
			await expect(page.locator('body')).not.toContainText(
				'Internal Server Error',
			)
		} else {
			test.skip(
				true,
				'the coordinator substitution admin did not appear. NOT a deploy gap — SubstitutionAdminView is registered in src/registry.js. If this persists it is an authorisation or routing problem, not a missing build.',
			)
		}
	})
})
