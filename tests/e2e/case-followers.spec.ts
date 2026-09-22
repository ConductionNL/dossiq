/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Following a case you do not own.
 *
 * A handler owns a case. A teamleider who wants to hear how a sensitive one
 * goes had no way to say so, so they asked to be copied in by hand. Following
 * subscribes them to the case's own notifications, puts it under Followed on
 * Cases, and names them under Followers on the People tab. The subscription is
 * OpenRegister's (`object-watchers`); dossiq declares and renders it.
 *
 * 🔴 THIS IS ONE USER, BECAUSE A BROWSER IS ONE USER. The spec's scenarios are
 * written about two people, "you" and Anna, and the half that needs two is the
 * RBAC heal: a follower who loses read on the case is dropped from the list at
 * the next dispatch. That is asserted in openregister by
 * `tests/Unit/Service/Notification/NotificationRecipientResolverWatchersTest.php`
 * and `tests/Unit/Service/Interaction/WatcherServiceTest.php`, named here so
 * nobody has to guess whether it is covered. What is driven below is the
 * signed-in user's own subscription: taken from the case page, narrowing the
 * Cases list, named on the People tab, and dropped again.
 *
 * 🔴 THE DECLARATION IS ASSERTED AGAINST THE LIVE SCHEMA, NOT THE FILE.
 * `x-openregister-notifications` is folded into the schema's `configuration`
 * on import, and an annotation that never reached the instance leaves the
 * Follow button working, the list correct and the notification silent. Reading
 * the file would report green on exactly that. So the schema is read back from
 * OpenRegister.
 *
 * THE ROWS ARE ADDRESSED BY THEIR RUN PREFIX, NEVER BY POSITION OR COUNT. The
 * Cases index is a shared list on a shared instance and another session's
 * fixtures land in it while this one runs.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	ensureCaseType,
	executeTransition,
	getAvailableTransitions,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD, trackDossiqErrors } from './helpers/nav.ts'

const APP_URL = `/apps/${REGISTER}/`
const OR = '/index.php/apps/openregister/api'
const API_BASE = `${OR}/objects/${REGISTER}/case`

/** The subscription endpoint of one case, which OpenRegister owns. */
function watchUrl(caseId: string): string {
	return `${API_BASE}/${caseId}/watch`
}

/** The audience endpoint of one case. */
function watchersUrl(caseId: string): string {
	return `${API_BASE}/${caseId}/watchers`
}

let api: APIRequestContext
let token: string
let caseTypeId = ''

/** The cases this spec seeded, by the key the tests know them under. */
const cases: Record<string, string> = {}

/**
 * Follow a case as the signed-in user.
 *
 * @param caseId The case uuid.
 */
async function follow(caseId: string): Promise<void> {
	const res = await api.put(watchUrl(caseId), {
		headers: { requesttoken: token, 'Content-Type': 'application/json' },
	})
	expect(res.ok(), `following ${caseId} answered ${res.status()}`).toBeTruthy()
}

/**
 * Stop following a case.
 *
 * @param caseId The case uuid.
 */
async function unfollow(caseId: string): Promise<void> {
	const res = await api.delete(watchUrl(caseId), {
		headers: { requesttoken: token },
	})
	expect(res.ok(), `unfollowing ${caseId} answered ${res.status()}`).toBeTruthy()
}

/**
 * The cases the Followed lens answers with, as the signed-in user.
 */
async function followedIds(): Promise<string[]> {
	const res = await api.get(`${API_BASE}?_watching=true&_limit=100`, {
		headers: { requesttoken: token },
	})
	expect(res.ok(), `the _watching lens answered ${res.status()}`).toBeTruthy()
	const body = await res.json()

	return (body.results ?? body.data ?? []).map((c: any) => objectId(c))
}

/**
 * The account names OpenRegister lists as following one case.
 *
 * @param caseId The case uuid.
 */
async function followerUids(caseId: string): Promise<string[]> {
	const res = await api.get(watchersUrl(caseId), {
		headers: { requesttoken: token },
	})
	expect(
		res.ok(),
		`listing the followers of ${caseId} answered ${res.status()}`,
	).toBeTruthy()
	const body = await res.json()

	return (body.results ?? []).map((row: any) => String(row.userId ?? ''))
}

/**
 * The notifications standing for the signed-in user right now.
 */
async function notificationSubjects(): Promise<string[]> {
	const res = await api.get(
		'/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
		{ headers: { requesttoken: token, 'OCS-APIRequest': 'true' } },
	)
	if (!res.ok()) {
		return []
	}

	const body = await res.json().catch(() => ({}))
	const rows = body?.ocs?.data ?? []

	return rows.map((row: any) =>
		[row.subject, row.subjectRich, row.message].filter(Boolean).join(' '),
	)
}

test.describe('Following a case', () => {
	test.setTimeout(240_000)

	test.beforeAll(async ({ playwright, baseURL }) => {
		api = await playwright.request.newContext({ baseURL })
		token = await getRequestToken(api)

		const caseType = await ensureCaseType(api, token)
		caseTypeId = caseType.id

		for (const key of ['fromThePage', 'lens', 'listed', 'dropped']) {
			const seeded = await seedCase(api, token, {
				title: `${RUN_PREFIX} follow ${key}`,
				caseType: caseTypeId,
			})
			cases[key] = objectId(seeded)
		}

		// Nothing is followed at the start. A subscription survives a run and
		// this instance is shared, so a spec that assumed a clean slate would
		// be asserting about another session's fixtures.
		for (const id of Object.values(cases)) {
			await unfollow(id)
		}
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/case-followers/specs/case-management/spec.md#scenario-follow-a-colleagues-case
	//
	// BREAKS IF: the strip stops reading `@self.watching`, or the PUT stops
	// landing. The reload is the point: an optimistic flip that never reached
	// OpenRegister looks identical to one that did until the page is read back.
	// @e2e case-access-control::follow-survives-a-reload
	test('the Follow button on the case page takes the subscription, and it survives a reload', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)

		await page.goto(`${APP_URL}cases/${cases.fromThePage}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const toggle = page.getByTestId('case-follow-toggle')
		await expect(toggle).toBeVisible({ timeout: 30_000 })
		await expect(toggle).toHaveAttribute('aria-pressed', 'false')

		await toggle.click()
		await expect(toggle).toHaveAttribute('aria-pressed', 'true')

		await page.reload(PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.getByTestId('case-follow-toggle')).toHaveAttribute(
			'aria-pressed',
			'true',
		)

		// And OpenRegister agrees, which the button alone cannot say.
		expect(await followerUids(cases.fromThePage)).not.toEqual([])

		expect(errors).toEqual([])
	})

	// @e2e openspec/changes/case-followers/specs/case-management/spec.md#scenario-follow-a-colleagues-case
	//
	// BREAKS IF: `_watching` stops being resolved inside the query, or starts
	// being read as "no restriction" when the caller follows nothing. The
	// negative half is what catches the second: a lens that answered the whole
	// register would contain the unfollowed case too.
	test('the Followed lens holds the cases you follow and nothing else', async () => {
		await follow(cases.lens)

		const ids = await followedIds()

		expect(ids).toContain(cases.lens)
		expect(ids).not.toContain(cases.dropped)
	})

	// @e2e openspec/changes/case-followers/specs/case-management/spec.md#scenario-follow-a-colleagues-case
	//
	// BREAKS IF: the Followers section stops rendering, or the panel starts
	// drawing a refusal as an empty list. The admin driving this run may update
	// the case, so a refusal here is a real failure and not a permission the
	// fixture forgot.
	// @e2e case-access-control::the-people-tab-names-who-follows-the-case
	test('the People tab names who follows the case', async ({ page }) => {
		await follow(cases.listed)

		const errors = trackDossiqErrors(page)

		await page.goto(`${APP_URL}cases/${cases.listed}`, PAGE_LOAD)
		await dismissSupportDialog(page)
		await expect(page.locator('.cn-detail-page')).toBeVisible({
			timeout: 30_000,
		})

		const strip = page.locator('.cn-tabs-widget')
		await strip.getByRole('tab', { name: 'People', exact: true }).click()

		const panel = page.locator('[data-testid="case-followers"]')
		await expect(panel).toBeVisible({ timeout: 20_000 })
		await expect(
			panel.locator('[data-testid="case-followers-person"]'),
		).not.toHaveCount(0)

		// Nobody's subscription is removable from here: taking one off needs
		// `manage`, and a button that 403s on press teaches nobody anything.
		await expect(panel.locator('button')).toHaveCount(0)

		expect(errors).toEqual([])
	})

	// @e2e openspec/changes/case-followers/specs/case-management/spec.md#scenario-unfollow
	//
	// BREAKS IF: the DELETE stops landing, which is the failure the two verbs
	// exist to prevent: a strip that PUT in both directions reports success and
	// leaves a subscription nobody can take off.
	test('Unfollow takes the case back out of the lens', async ({ page }) => {
		await follow(cases.dropped)
		expect(await followedIds()).toContain(cases.dropped)

		const errors = trackDossiqErrors(page)

		await page.goto(`${APP_URL}cases/${cases.dropped}`, PAGE_LOAD)
		await dismissSupportDialog(page)

		const toggle = page.getByTestId('case-follow-toggle')
		await expect(toggle).toBeVisible({ timeout: 30_000 })
		await expect(toggle).toHaveAttribute('aria-pressed', 'true')

		await toggle.click()
		await expect(toggle).toHaveAttribute('aria-pressed', 'false')

		await expect
			.poll(async () => await followedIds())
			.not.toContain(cases.dropped)
		expect(await followerUids(cases.dropped)).toEqual([])

		expect(errors).toEqual([])
	})

	// @e2e openspec/changes/case-followers/specs/case-management/spec.md#scenario-a-follower-hears
	//
	// BREAKS IF: the annotation never reaches the live schema. That is the
	// silent one: OpenRegister folds `x-openregister-notifications` into the
	// schema's configuration on import and drops what it does not recognise, so
	// a rule that stayed in the file leaves the Follow button working, the lens
	// correct and the notification silent. Reading the file would report green
	// on exactly that, which is why the schema is read back off the instance.
	test('the case schema on this instance addresses its followers', async () => {
		const res = await api.get(`${OR}/schemas/case`, {
			headers: { requesttoken: token },
		})
		expect(
			res.ok(),
			`reading the case schema answered ${res.status()}`,
		).toBeTruthy()

		const schema = await res.json()
		const rules = schema?.configuration?.['x-openregister-notifications'] ?? {}
		const rule = rules.caseMovedForItsFollowers

		expect(rule, 'the live case schema addresses no followers').toBeTruthy()
		// `transition` and not `updated`: the status engine's own event, where
		// `updated` fires on every save and a follower would hear about every
		// field edit until they stopped reading.
		expect(rule.trigger?.type).toBe('transition')
		expect(rule.enabled).toBe(true)
		// `{"watchers": true}` carries no `kind`, and the validator refuses
		// anything but boolean true: `"yes"` is how a rule quietly addresses
		// nobody.
		expect(rule.recipients).toEqual([{ watchers: true }])

		// A control: a rule that was already on this schema is still there, so
		// the assertions above cannot pass on an instance that simply answers
		// every key.
		expect(rules.caseAssigned?.trigger?.type).toBe('created')
	})

	// @e2e openspec/changes/case-followers/specs/case-management/spec.md#scenario-a-follower-hears
	//
	// BREAKS IF: the fan-out stops reaching the watchers. The control is the
	// unfollowed case moving through the SAME transition in the same run: a
	// notification that named every case would satisfy the first assertion on
	// its own.
	test('a follower is told when the case moves, and a non-follower is not', async () => {
		const machine = await seedStateMachine(api, token)

		const watched = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} follow moved`,
				caseType: machine.caseTypeId,
			}),
		)
		const ignored = objectId(
			await seedCase(api, token, {
				title: `${RUN_PREFIX} follow unmoved`,
				caseType: machine.caseTypeId,
			}),
		)

		await follow(watched)
		await unfollow(ignored)

		for (const caseId of [watched, ignored]) {
			await updateObject(api, token, 'case', caseId, {
				status: machine.statusReceived,
			})

			const available = await getAvailableTransitions(api, token, caseId)
			const transitions =
				available.body?.transitions ?? available.body?.results ?? []
			expect(
				transitions.length,
				`no transition is available on ${caseId}`,
			).toBeGreaterThan(0)

			const moved = await executeTransition(
				api,
				token,
				caseId,
				transitions[0].id ?? transitions[0].transitionId,
			)
			expect(
				moved.status,
				`moving ${caseId} answered ${moved.status}`,
			).toBeLessThan(400)
		}

		const watchedTitle = (await showObject(api, 'case', watched)).title
		const ignoredTitle = (await showObject(api, 'case', ignored)).title

		// Polled rather than read once: the dispatcher runs inline on this
		// instance but may be deferred to `AnnotationNotificationDispatchJob`
		// on another, and a single read would then be about the timing rather
		// than about the fan-out.
		await expect
			.poll(async () => (await notificationSubjects()).join('\n'), {
				timeout: 60_000,
			})
			.toContain(watchedTitle)

		expect((await notificationSubjects()).join('\n')).not.toContain(ignoredTitle)
	})
})
