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
	// WHY THESE TESTS USED TO SKIP ON EVERY RUN, diagnosed by #2480 and kept here
	// because the reason was not what the skip said. The old guards waited for a
	// heading matching /Substitution|Vervanging|Waarneming/ and stood down when
	// none appeared. None ever appears: `SubstitutionSettings.vue` renders no
	// heading of its own, and the only heading on this page comes from Nextcloud's
	// settings framework, which prints `PersonalSection::getName()`, the string
	// "Dossiq". So the guard could never pass, on any instance, in any language,
	// and the skip reason's note about Dutch locators answered a question nobody
	// had asked. The tests below assert the actions the page offers instead.

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

	/**
	 * Deliberately carries no `@e2e` anchor any more.
	 *
	 * It used to cite `#handler-registers-their-own-substitution`, whose THEN
	 * is a stored object: `absentee = jan`, `substitute = marieke`,
	 * `status = active`, `createdBy = jan`. A mount point and a button prove
	 * none of those four. Every one of them can be wrong — or the write can be
	 * dropped entirely — while this page renders exactly as it does now, and
	 * the affordance-present assertion is the shape the audit that produced
	 * this file's rewrite found under most of its citations.
	 *
	 * The registration itself is asserted in the test below, on the stored row.
	 * This stays as what it is: the personal-settings section mounts and offers
	 * the action that opens the form.
	 */
	test('substitution renders under personal settings with a register action', async ({
		page,
	}) => {
		await page.goto(`/index.php${SubstitutionPersonalSettings}`, {
			timeout: 60_000,
		})
		await dismissSupportDialog(page)
		// The mount point the template declares. Asserting it first separates
		// "the section did not load" from "the section loaded without the
		// button", which is the distinction the old guard erased.
		await expect(
			page.locator('#dossiq-personal-settings'),
			'the personal-settings mount point must be on the page',
		).toBeAttached({ timeout: 30_000 })
		await expect(
			page
				.getByRole('button', {
					name: /Register substitution|Waarneming registreren/i,
				})
				.first(),
			'the Vue app mounted and rendered its register action',
		).toBeVisible({ timeout: 30_000 })
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
	 * THE REGISTRATION, READ BACK OFF THE STORED ROW.
	 *
	 * The scenario's THEN is four fields on an object: `absentee = jan`,
	 * `substitute = marieke`, `status = active`, `createdBy = jan`. So this
	 * submits one and reads all four back, and it submits as the ORDINARY
	 * account rather than the coordinator, because "handler registers their
	 * own" is a claim about a principal who holds no role: the coordinator can
	 * register for anybody, so a coordinator-driven registration would be green
	 * on a build that had lost the self-registration path entirely.
	 *
	 * `createdBy` is the field worth naming. `SubstitutionService::create()`
	 * defaults it to the absentee and overwrites that with its `$createdBy`
	 * argument whenever one is passed, and the controller always passes the
	 * acting uid. Here those two are the same user, which is exactly the point:
	 * the row must record the handler, not the session that happened to have a
	 * role. The coordinator case below is the other side of that fork.
	 *
	 * THE PERIOD IS IN THE FUTURE ON PURPOSE. A `scope: all` substitution whose
	 * period contains today would route every one of the absentee's cases to
	 * the substitute, and the scope-limited test further down asserts that the
	 * out-of-scope case is withheld. A fixture that quietly widened another
	 * test's scope would make that one fail for a reason that is not its
	 * subject.
	 *
	 * ✅ MUTATION-CHECKED 2026-09-12, on a private disposable instance rather
	 * than the shared one. Both clauses now have a break their own assertion
	 * catches:
	 *
	 *   `createdBy`  SubstitutionService::create(), `$createdByValue =
	 *                $createdBy` -> `= $substitute`
	 *                red: Expected "e2euser", Received "admin"
	 *   `status`     the same `$row`, `'status' => 'active'` -> `'ended'`
	 *                red: "a registration is stored active, which is what makes
	 *                it route work", Expected "active", Received "ended"
	 *
	 * 🔴 AND THE TWO MUTATIONS THIS COMMENT USED TO PRESCRIBE BOTH LEAVE IT
	 * GREEN, which is why the prescriptions are gone rather than corrected in
	 * place. It said to delete the `if ($createdBy !== '')` branch and to drop
	 * `'status' => 'active'`. Run:
	 *
	 *   - deleting the branch: 2 passed. This test registers for ITSELF, so
	 *     absentee and registrar are the same account and `$createdByValue`
	 *     lands on an identical value either way.
	 *   - dropping the status key: 2 passed. The substitution schema in
	 *     `lib/Settings/register.d/62-handler-vervanging.json` declares
	 *     `"default": "active"`, so OpenRegister refills the field the service
	 *     stopped sending.
	 *
	 * Neither reason is visible from the line being mutated: one lives in this
	 * file's fixture, the other in a schema in another directory. A predicted
	 * outcome written while reading `create()` could not have been better than
	 * a guess. So when a mutation has not been run, name the POINT and stop;
	 * anything further reads as analysis and closes the question for the next
	 * person.
	 */
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#handler-registers-their-own-substitution
	test('a handler registering their own substitution stores the pair, an active status and themselves as creator', async () => {
		const marker = `${RUN_PREFIX} own registration`
		const res = await postDossiq(plainApi, plainToken, '/substitutions', {
			absentee: PLAIN_USER,
			substitute: ADMIN_USER,
			startDate: isoDay(30),
			endDate: isoDay(37),
			scope: 'all',
			reason: 'verlof',
			comment: marker,
		})
		expect(
			res.status(),
			`a handler must be able to register their own substitution; body was ${await res.text()}`,
		).toBe(201)

		const id = objectId(await res.json())
		expect(
			id,
			'the created substitution must have an id, or the assertions below address nothing',
		).not.toBe('')
		seededSubstitutions.push(id)

		// THE STORED ROW, not the response body. A controller that answered 201
		// with a well-formed echo and wrote something else — or wrote nothing —
		// would satisfy a body-only assertion.
		const stored = await showObject(api, 'substitution', id)
		expect(String(stored?.absentee ?? ''), 'absentee = the handler').toBe(
			PLAIN_USER,
		)
		expect(String(stored?.substitute ?? ''), 'substitute = the waarnemer').toBe(
			ADMIN_USER,
		)
		expect(
			String(stored?.status ?? ''),
			'a registration is stored active, which is what makes it route work',
		).toBe('active')
		expect(
			String(stored?.createdBy ?? ''),
			'createdBy = the handler who registered it, not the coordinator path',
		).toBe(PLAIN_USER)
	})

	/**
	 * ON BEHALF OF SOMEBODY ELSE, WHICH IS THE HALF THAT NEEDS THE ROLE.
	 *
	 * This citation used to sit on a page test that asserted the coordinator
	 * console offers "Bulk reassign" and "Preview affected work". Those two
	 * buttons belong to a different requirement — bulk reassignment — and the
	 * whole body sat behind a `becomesVisible` guard that skipped the test when
	 * the heading did not appear, so the citation was credited by a run in
	 * which nothing was asserted at all.
	 *
	 * What the scenario actually claims is that a coordinator may register for
	 * a handler who is not themselves, and that the row then records the
	 * COORDINATOR as creator. Three identities keep that unambiguous: the
	 * absentee is the ordinary account, the substitute is a third id that is
	 * neither party, and the creator is the coordinator. A build that stamped
	 * `createdBy` from the absentee (the service's default) or from the
	 * substitute would fail on the same line.
	 *
	 * The refusal is asserted first and with the principal the requirement
	 * names. Without it, "a coordinator may register for another" would be
	 * green on a build where anybody may.
	 *
	 * ✅ MUTATION-CHECKED 2026-09-12, all three clauses, on a private
	 * disposable instance:
	 *
	 *   the refusal  SubstitutionController::create(), delete the
	 *                `if ($absentee !== $actorId && ...isCoordinator(...)
	 *                === false)` guard
	 *                red: "registering on behalf of another handler is
	 *                coordinator-only", Expected 403, Received 201 — and the
	 *                body shows `e2euser`, an account in no groups, creating a
	 *                row with absentee "admin". That is the bypass itself, not
	 *                a status code.
	 *   `createdBy`  SubstitutionService::create(), two different breaks, and
	 *                they land on different values here, which is what makes
	 *                the assertion discriminating rather than merely equal:
	 *                deleting the `if ($createdBy !== '')` branch gives the
	 *                ABSENTEE (Received "e2euser"), while
	 *                `= $substitute` gives the SUBSTITUTE
	 *                (Received "e2euser-waarnemer").
	 *   `status`     `'status' => 'active'` -> `'ended'`,
	 *                red: Expected "active", Received "ended". Dropping the
	 *                key instead leaves this green: the schema declares
	 *                `"default": "active"` and OpenRegister refills it.
	 *
	 * The handler test above stays GREEN under the guard deletion, and
	 * correctly: it registers for itself, so `$absentee !== $actorId` is false
	 * and the branch never decides anything there. That is why the refusal is
	 * asserted here and with the principal the requirement names.
	 */
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#coordinator-registers-a-substitution-on-behalf-of-an-absent-handler
	test('a coordinator registers for an absent handler and the row names the coordinator as creator, where an ordinary user is refused', async () => {
		const deputy = `${PLAIN_USER}-waarnemer`
		const refusedMarker = `${RUN_PREFIX} on-behalf refusal probe`

		// The ordinary account, registering for somebody who is not itself.
		const refused = await postDossiq(plainApi, plainToken, '/substitutions', {
			absentee: ADMIN_USER,
			substitute: deputy,
			startDate: isoDay(60),
			endDate: isoDay(67),
			scope: 'all',
			reason: 'ziekte',
			comment: refusedMarker,
		})
		expect(
			refused.status(),
			`registering on behalf of another handler is coordinator-only; body was ${await refused.text()}`,
		).toBe(403)

		// AND it wrote nothing. A 403 returned after the row was stored would
		// satisfy the status line and leave the protection unenforced.
		const afterRefusal = await listSubstitutions(api, token)
		expect(
			afterRefusal.filter(
				(row) => String(row?.comment ?? '') === refusedMarker,
			),
			'the refused submission must not have left a substitution behind',
		).toHaveLength(0)

		// The coordinator, same shape, same period.
		const marker = `${RUN_PREFIX} on-behalf registration`
		const res = await postDossiq(api, token, '/substitutions', {
			absentee: PLAIN_USER,
			substitute: deputy,
			startDate: isoDay(60),
			endDate: isoDay(67),
			scope: 'all',
			reason: 'ziekte',
			comment: marker,
		})
		expect(
			res.status(),
			`a coordinator must be able to register for an absent handler; body was ${await res.text()}`,
		).toBe(201)

		const id = objectId(await res.json())
		expect(id).not.toBe('')
		seededSubstitutions.push(id)

		const stored = await showObject(api, 'substitution', id)
		expect(
			String(stored?.absentee ?? ''),
			'the absentee is the handler being covered, not the coordinator',
		).toBe(PLAIN_USER)
		expect(String(stored?.substitute ?? '')).toBe(deputy)
		expect(
			String(stored?.createdBy ?? ''),
			'createdBy = the coordinator who registered it, and neither of the two '
				+ 'parties named on the row',
		).toBe(ADMIN_USER)
		expect(String(stored?.status ?? '')).toBe('active')
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

	/**
	 * Deliberately carries no `@e2e` anchor, and the toggle branch is gone.
	 *
	 * This used to cite `#waarnemer-sees-substituted-work-in-my-work` and to
	 * click a `substituted-toggle` inside an `if` that stood down when the
	 * toggle was absent. It is absent on every build: `getByTestId(
	 * 'substituted-toggle')` matched nothing in `src/` at all, because the My
	 * Work substitution integration has no call site. `fetchSubstitutedWork()`
	 * is never called, every helper in `src/utils/substitutionHelpers.js` is
	 * imported by nothing, and neither `MyWorkCards.vue` nor `MyWorkWidget.vue`
	 * mentions substitution. So the `if` could only ever take its empty branch,
	 * and the two unconditional assertions left — a button and the absence of a
	 * 500 — are satisfied by a build that routes no substituted work whatsoever.
	 *
	 * The scenario now carries a reason-bearing `@e2e exclude` naming that gap.
	 * What survives here is what the body actually did: a My Work page-load
	 * regression check, kept because the route is worth guarding and honest
	 * because it claims nothing else.
	 */
	test('My Work renders without a server error', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq/my-work')
		await dismissSupportDialog(page)
		// The My Work route renders no page heading (measured on a CI runner
		// 2026-08-04) — assert the view by a control it does render.
		await expect(page.getByRole('button', { name: 'Urgency' })).toBeVisible({
			timeout: 15000,
		})
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
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

	/**
	 * Deliberately carries no `@e2e` anchor any more.
	 *
	 * It used to cite `#coordinator-registers-a-substitution-on-behalf-of-an-absent-handler`,
	 * which is about a registration and its `createdBy`. Bulk reassign and its
	 * preview belong to a different requirement, and this body also sits behind
	 * a `becomesVisible` guard whose else branch is `test.skip` — so the
	 * scenario could be credited by a run that asserted nothing. The
	 * registration is asserted on the stored row further up.
	 *
	 * The two buttons are still worth a check, so the test stays. Both halves
	 * of the requirement they belong to — that the preview is coordinator-only,
	 * and that it lists the departing handler's open work while mutating
	 * nothing — are asserted over HTTP by the two tests that cite them.
	 *
	 * 🔴 AND THE `becomesVisible` GUARD IS GONE, WITH ITS `test.skip` ELSE.
	 * Withdrawing the citation stopped this body crediting a scenario, but it
	 * left a test that still could not fail: on a build where the coordinator
	 * console never rendered, the run reported SKIPPED, which is a colour
	 * nobody reads as a defect. The skip reason argued the point itself —
	 * "NOT a deploy gap, SubstitutionAdminView is registered in
	 * src/registry.js" — so the branch existed to tolerate exactly the failure
	 * it said could not happen. The heading is required now, and a console that
	 * does not appear fails here naming it.
	 */
	test('coordinator admin exposes a bulk-reassign action with a mandatory preview', async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/dossiq${SubstitutionAdmin}`)
		await dismissSupportDialog(page)
		await expect(
			page.getByRole('heading', { name: /Substitutions & reassignment/ }).first(),
			'the coordinator substitution console must render, and a build where it '
				+ 'does not is the defect this test exists to report',
		).toBeVisible({ timeout: 30_000 })
		const reassign = page.getByRole('button', { name: /Bulk reassign/ }).first()
		await expect(reassign).toBeVisible()
		await reassign.click()
		// The preview button gates execute — the modal must show it. What the
		// preview RETURNS, and that it mutates nothing, is asserted over HTTP
		// above; this is the affordance only.
		await expect(
			page.getByRole('button', { name: /Preview affected work/ }).first(),
		).toBeVisible({ timeout: 8000 })
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
	 *
	 * 🔴 THE `becomesVisible` GUARD IS GONE HERE TOO, and this one was the
	 * worse of the pair: a page-load regression check whose whole body sat
	 * behind "if the page loaded" has nothing left to report. The 500 it looks
	 * for is one of the ways the heading fails to appear, so the guard stood
	 * down in precisely the case the test exists for.
	 */
	test('coordinator admin renders without a server error', async ({ page }) => {
		await page.goto(`/index.php/apps/dossiq${SubstitutionAdmin}`)
		await dismissSupportDialog(page)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		await expect(
			page.getByRole('heading', { name: /Substitutions & reassignment/ }).first(),
			'the coordinator console must render its own heading, not merely avoid a 500',
		).toBeVisible({ timeout: 30_000 })
	})
})
