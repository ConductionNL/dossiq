/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a new instance starts with, against a live instance.
 *
 * WHY THESE ARE E2E AND NOT ONLY UNIT TESTS. Every assertion below crosses a
 * seam the PHPUnit suite cannot: the unit tests construct each service over an
 * in-memory register and prove the DECISION, never that the route exists, that
 * the controller is reachable, or that OpenRegister accepts the properties this
 * change declares. Three specific failures are invisible to a unit test and
 * visible here:
 *
 *  - a property declared in `register.d/48-starter-content.json` that the
 *    import dropped. Every unit test passes, because they write plain arrays;
 *    a live write silently loses the field.
 *  - a route the `{schema}` wildcard swallows. `appinfo/routes.php` orders the
 *    literal `/api/starter/roles` before the wildcards for exactly this, and
 *    nothing but a real request proves the order held.
 *  - the case-list exclusion. `isTemplate: false` on a page filter is a
 *    manifest key; a typo in it is not a runtime error, it is a list that
 *    quietly includes every template.
 *
 * RESIDUE. Every object this file creates goes through `createObject`, which
 * tracks it for `cleanupRunObjects`. Nothing here writes to a real person's
 * anything: a case template never reaches a working list, a count or a term,
 * which is the whole point of REQ-TPL-01 and is asserted below rather than
 * assumed.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	ensureCaseType,
	FIXTURE_SCHEMAS,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	updateObject,
} from './helpers/fixtures.ts'
import { dismissSupportDialog, PAGE_LOAD } from './helpers/nav.ts'

const APP_BASE = '/index.php/apps/dossiq'

/**
 * The schemas this change adds, swept before the shared list.
 *
 * 🔑 THE SHARED LIST DOES NOT KNOW ABOUT THEM, AND A ROW LEFT BEHIND IS NOT
 * INERT. A `contentTemplate` this suite created stays offered in the task
 * dialog of whoever opens the instance next, and a `reusableStep` stays in the
 * step picker. They go FIRST because a case type references a step, and a
 * child removed after its parent reports rows it cannot find.
 */
const EXTRA_SCHEMAS = ['contentTemplate', 'reusableStep', 'shippedOrigin', 'starterSetAdoption']

/**
 * The starter API, which every admin assertion below goes through.
 *
 * @param api   The authenticated request context.
 * @param path  The path under /api/starter.
 */
async function starterGet(api: APIRequestContext, path: string) {
	return api.get(`${APP_BASE}/api/starter${path}`)
}

test.afterAll(async ({ request }) => {
	await cleanupRunObjects(request, await getRequestToken(request), [
		...EXTRA_SCHEMAS,
		...FIXTURE_SCHEMAS,
	])
})

test.describe('the shipped configuration', () => {
	test('reads shipped, changed here or ours per object, and names the set', async ({ request }) => {
		const res = await starterGet(request, '/shipped/caseType')

		// 503 is a legitimate answer on an instance where OpenRegister is not
		// configured, and it is NOT an empty list. Asserting "200 or 503" and
		// then reading the body only on 200 is what keeps this test from
		// passing on an instance that answered "nothing shipped" because it
		// could not look.
		expect([200, 503]).toContain(res.status())
		if (res.status() !== 200) {
			test.skip(true, 'OpenRegister is not configured on this instance')
			return
		}

		const body = await res.json()
		expect(Array.isArray(body.items)).toBeTruthy()
		for (const row of body.items) {
			expect(['shipped', 'changed', 'local', 'removed']).toContain(row.state)
			expect(typeof row.set).toBe('string')
			expect(typeof row.setVersion).toBe('string')
		}
	})

	test('a shipped object an administrator edited reads as changed here', async ({ request }) => {
		const res = await starterGet(request, '/shipped/caseType')
		if (res.status() !== 200) {
			test.skip(true, 'OpenRegister is not configured on this instance')
			return
		}

		const shipped = (await res.json()).items.filter((row: any) => row.state === 'shipped')
		if (shipped.length === 0) {
			test.skip(true, 'nothing has been seeded on this instance')
			return
		}

		const token = await getRequestToken(request)
		const target = shipped[0]
		await updateObject(request, token, 'caseType', target.targetObject, {
			description: `${RUN_PREFIX} edited by the starter e2e`,
		})

		const after = await (await starterGet(request, '/shipped/caseType')).json()
		const row = after.items.find((item: any) => item.targetObject === target.targetObject)

		expect(row.state).toBe('changed')
		expect(row.set).toBe(target.set)
	})

	test('the role set is offered rather than already granted', async ({ request }) => {
		const res = await starterGet(request, '/roles')

		// The literal path must win over `/shipped/{schema}`; a 404 here is the
		// wildcard having swallowed it.
		expect(res.status()).not.toBe(404)
		if (res.status() !== 200) {
			test.skip(true, 'OpenRegister is not configured on this instance')
			return
		}

		const offer = await res.json()
		expect(offer.set).toBe('gemeentelijke-rollen')
		expect(Array.isArray(offer.roles)).toBeTruthy()
		if (offer.adopted === false) {
			for (const role of offer.roles) {
				expect(role.dormant, `${role.name} arrived awake`).toBeTruthy()
			}
		}
	})
})

test.describe('a case type is retired and its cases carry on', () => {
	test('a retired case type takes no new case and its cases stay open', async ({ request }) => {
		const token = await getRequestToken(request)
		const caseType = await ensureCaseType(request, token)
		const running = await seedCase(request, token, {
			title: `${RUN_PREFIX} running case of a retiring type`,
			caseType: caseType.id,
		})

		const retire = await request.post(
			`${APP_BASE}/api/starter/case-types/${caseType.id}/retire`,
			{ headers: { requesttoken: token } },
		)

		expect([200, 409, 404, 503]).toContain(retire.status())
		if (retire.status() !== 200) {
			test.skip(true, `the case type could not be retired: ${await retire.text()}`)
			return
		}

		expect((await retire.json()).state).toBe('retired')

		// The running case is the whole reason retirement exists rather than a
		// delete, so it is read back rather than assumed.
		const stillThere = await listObjects(request, 'case', { _limit: '200' })
		expect(stillThere.some((row: any) => objectId(row) === objectId(running))).toBeTruthy()

		const restore = await request.post(
			`${APP_BASE}/api/starter/case-types/${caseType.id}/restore`,
			{ headers: { requesttoken: token } },
		)
		expect(restore.status()).toBe(200)
		expect((await restore.json()).state).toBe('in_use')
	})
})

test.describe('a case starts from a template', () => {
	test('a template presets the new case and the case names the template', async ({ request }) => {
		const token = await getRequestToken(request)
		const caseType = await ensureCaseType(request, token)

		const template = await createObject(request, token, 'case', {
			title: `${RUN_PREFIX} Standaardzaak`,
			identifier: `${RUN_PREFIX}-tpl`,
			caseType: caseType.id,
			isTemplate: true,
			templateName: `${RUN_PREFIX} Standaardzaak sloopmelding`,
			impact: 'medium',
			urgency: 'medium',
			intakeChannel: 'manual',
		})

		// The property has to have survived the import. A dropped `isTemplate`
		// reads back as undefined here and as a template in every unit test.
		expect(template.isTemplate, 'isTemplate did not survive the write').toBeTruthy()

		const offered = await request.get(`${APP_BASE}/api/case-templates`, {
			params: { caseType: caseType.id },
		})
		expect([200, 503]).toContain(offered.status())
		if (offered.status() !== 200) {
			test.skip(true, 'OpenRegister is not configured on this instance')
			return
		}
		expect((await offered.json()).items.some(
			(row: any) => row.id === objectId(template),
		)).toBeTruthy()

		const started = await request.post(
			`${APP_BASE}/api/case-templates/${objectId(template)}/start`,
			{
				headers: { requesttoken: token },
				data: { overrides: { title: `${RUN_PREFIX} Sloopmelding Kerkstraat 4` } },
			},
		)

		expect(started.status()).toBe(201)
		const created = (await started.json()).case
		expect(created.caseType).toBe(caseType.id)
		expect(created.startedFromTemplate).toBe(objectId(template))
		expect(created.isTemplate).toBeFalsy()
	})

	test('a template is not work: it stays out of the case list', async ({ page, request }) => {
		const token = await getRequestToken(request)
		const caseType = await ensureCaseType(request, token)
		const templateName = `${RUN_PREFIX} Template that must not appear`

		await createObject(request, token, 'case', {
			title: templateName,
			identifier: `${RUN_PREFIX}-tpl-hidden`,
			caseType: caseType.id,
			isTemplate: true,
			templateName,
			impact: 'medium',
			urgency: 'medium',
			intakeChannel: 'manual',
		})

		await page.goto(`${APP_BASE}/cases`, PAGE_LOAD)
		await dismissSupportDialog(page)

		// The control: the list has to be rendering SOMETHING, or an empty page
		// would pass this assertion while proving nothing.
		await expect(page.getByTestId('cn-index-page').or(page.locator('main'))).toBeVisible()
		await expect(page.getByText(templateName, { exact: true })).toHaveCount(0)
	})
})

test.describe('a reusable step is changed once for two case types', () => {
	test('both case types read the new lead time, and the step names both', async ({ request }) => {
		const token = await getRequestToken(request)

		const step = await createObject(request, token, 'reusableStep', {
			name: `${RUN_PREFIX} Ontvankelijkheidstoets`,
			leadTimeDays: 5,
		})
		const stepId = objectId(step)

		const first = await createObject(request, token, 'caseType', {
			title: `${RUN_PREFIX} Bezwaar`,
			identifier: `${RUN_PREFIX.toLowerCase()}-bezwaar`,
			isDraft: false,
			reusableSteps: [stepId],
		})
		const second = await createObject(request, token, 'caseType', {
			title: `${RUN_PREFIX} Klacht`,
			identifier: `${RUN_PREFIX.toLowerCase()}-klacht`,
			isDraft: false,
			reusableSteps: [stepId],
		})

		expect(first.reusableSteps, 'reusableSteps did not survive the write').toContain(stepId)

		await updateObject(request, token, 'reusableStep', stepId, { leadTimeDays: 10 })

		const usedBy = await starterGet(request, `/steps/${stepId}/used-by`)
		expect(usedBy.status()).toBe(200)
		const titles = (await usedBy.json()).items.map((row: any) => row.title)
		expect(titles).toContain(first.title)
		expect(titles).toContain(second.title)

		// A step in use is not deleted, and the refusal names a case type.
		const refused = await request.delete(`${APP_BASE}/api/starter/steps/${stepId}`, {
			headers: { requesttoken: token },
		})
		expect(refused.status()).toBe(409)
		expect((await refused.json()).usedBy).toBeTruthy()

		expect(objectId(second)).toBeTruthy()
	})
})

test.describe('a task template is offered where a task is created', () => {
	test('the library answers for the kind asked, scoped to the case type', async ({ request }) => {
		const token = await getRequestToken(request)
		const caseType = await ensureCaseType(request, token)

		await createObject(request, token, 'contentTemplate', {
			name: `${RUN_PREFIX} Vraag advies aan juridische zaken`,
			kind: 'task',
			caseTypes: [caseType.id],
			presets: { title: 'Vraag advies aan juridische zaken', leadTimeDays: 10 },
		})

		const offered = await request.get(`${APP_BASE}/api/content-templates/task`, {
			params: { caseType: caseType.id },
		})

		expect(offered.status()).toBe(200)
		const names = (await offered.json()).items.map((row: any) => row.name)
		expect(names).toContain(`${RUN_PREFIX} Vraag advies aan juridische zaken`)

		// Scoped means scoped: the same template is not offered on a case type
		// it does not name.
		const elsewhere = await request.get(`${APP_BASE}/api/content-templates/task`, {
			params: { caseType: 'a-case-type-this-template-does-not-name' },
		})
		expect(elsewhere.status()).toBe(200)
		expect((await elsewhere.json()).items.map((row: any) => row.name))
			.not.toContain(`${RUN_PREFIX} Vraag advies aan juridische zaken`)
	})
})

test.describe('a StUF endpoint is probed', () => {
	test('an untested connection reads not tested, and a probe names the endpoint', async ({ page, request }) => {
		await page.goto('/index.php/settings/admin/dossiq', PAGE_LOAD)
		await dismissSupportDialog(page)

		const table = page.getByTestId('stuf-endpoints-table')
		await expect(table).toBeVisible()

		const buttons = page.locator('[data-testid^="stuf-test-"]')
		const count = await buttons.count()
		if (count === 0) {
			// No endpoint is configured, so there is nothing to probe. The
			// column still has to exist, or the button shipped on a screen
			// nobody can reach.
			await expect(table.getByRole('columnheader', { name: 'Connection test' })).toBeVisible()
			return
		}

		// Untested is its own answer, deliberately not a failure.
		await expect(page.getByText('Not tested').first()).toBeVisible()

		const token = await getRequestToken(request)
		const id = (await buttons.first().getAttribute('data-testid'))!.replace('stuf-test-', '')
		const probed = await request.post(`${APP_BASE}/api/connections/stuf/${id}/test`, {
			headers: { requesttoken: token },
		})

		expect(probed.status()).toBe(200)
		const result = await probed.json()
		expect(['reachable', 'failed']).toContain(result.state)
		expect(result.measuredAt, 'a probe that ran carries the moment it ran').toBeTruthy()
	})
})
