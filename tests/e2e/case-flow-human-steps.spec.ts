import type { APIRequestContext, Page } from '@playwright/test'

/**
 * The case flow, end to end: a case that pauses for a person and moves when
 * they answer.
 *
 * WHAT THIS ASSERTS, AND WHY IT ASSERTS THAT. The flow's whole purpose is that
 * somebody outside the system can see where their case is. So the assertions
 * are on the STATUS a person reads and the TASK a person is given — not on run
 * rows, resume slots or step sequences. A test that checked the internals would
 * pass on a flow that advanced perfectly while showing the applicant nothing,
 * which is the exact failure this feature exists to prevent.
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT FIXTURE. Every check below is preceded by a
 * check that the thing it needs actually exists. A skip cannot tell "not seeded"
 * from "the seeder is broken", and reports the second as a pass — so where the
 * fixture is missing these fail with a message naming what was absent, rather
 * than going green over an empty instance.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from './helpers/auth.ts'
import { getRequestToken, RUN_PREFIX } from './helpers/fixtures.ts'
import {
	createFlow,
	OR_API,
	publishFlow,
	removeFlow,
	runFlow,
} from './helpers/flows.ts'

/** The case type the flow ships against. */
const CASE_TYPE = 'Omgevingsvergunning kleine bouwactiviteit'

/** The seeded case that is COMPLETE, and should reach handling. */
const COMPLETE_CASE = 'Dakkapel Kerkstraat 12'

/** The seeded case that is INCOMPLETE, and should produce an applicant task. */
const INCOMPLETE_CASE = 'Schuur Molenweg 3'

/**
 * Read the flow the app ships, straight from the flow store.
 *
 * Goes through the API rather than the editor because the question here is
 * whether the DECLARATION imported — a flow that failed to import leaves the
 * editor looking merely empty, which is indistinguishable from an instance
 * nobody has set up.
 */
async function shippedFlow(page: Page): Promise<Record<string, unknown> | null> {
	const response = await page.request.get(
		'/index.php/apps/openregister/api/flows?limit=200',
	)
	if (!response.ok()) {
		return null
	}

	const body = await response.json()
	const flows = (body?.results ?? body ?? []) as Array<Record<string, unknown>>

	return (
		flows.find((flow) => String(flow.name ?? '') === 'Case behandeling') ?? null
	)
}

test.describe('Case flow — human steps', () => {
	test('the shipped flow imports, and imports INERT', async ({ page }) => {
		const response = await page.request.get(
			'/index.php/apps/openregister/api/flows?limit=200',
		)
		expect(response.ok(), 'The flow store must answer.').toBeTruthy()

		const body = await response.json()
		const flows = (body?.results ?? body ?? []) as Array<Record<string, unknown>>
		const copies = flows.filter(
			(f) => String(f.name ?? '') === 'Case behandeling',
		)

		expect(
			copies.length,
			'The case flow was not found in the flow store. It is declared as '
				+ 'x-openregister-flows on the case schema, so its absence means the register '
				+ 'import did not run or the declaration was rejected.',
		).toBeGreaterThan(0)

		// This instance has imported the register on every app upgrade, so a
		// re-import that CREATED instead of UPDATED would show here as a
		// second copy. Exactly one is the proof of idempotence.
		expect(
			copies.length,
			'Re-importing the register must update the shipped flow, not create another copy.',
		).toBe(1)

		const flow = copies[0]

		// Shipping a flow is not an operator volunteering to run it as
		// themselves. It must arrive disabled and unowned.
		expect(
			flow?.enabled,
			'A shipped flow must arrive disabled until somebody adopts it.',
		).toBeFalsy()
		expect(
			flow?.owner ?? null,
			'A shipped flow must arrive with no owner.',
		).toBeFalsy()
	})

	test('the flow carries every step the case needs, and a way out of the loop', async ({
		page,
	}) => {
		const flow = await shippedFlow(page)
		test.skip(flow === null, 'flow not imported — covered by the test above')

		const nodes = (flow?.nodes ?? []) as Array<Record<string, unknown>>
		const edges = (flow?.edges ?? []) as Array<Record<string, unknown>>

		const types = nodes.map((n) => String(n.type ?? ''))

		// The human steps are the point of the change.
		expect(
			types.filter((t) => t === 'dossiq.askPerson').length,
		).toBeGreaterThanOrEqual(2)
		expect(types.filter((t) => t === 'dossiq.requestDecision').length).toBe(3)

		// Status is moved by its own steps, so the applicant's view is driven by
		// the flow rather than by a side effect of something else.
		expect(
			types.filter((t) => t === 'dossiq.setStatus').length,
		).toBeGreaterThanOrEqual(5)

		// Exactly one unconditional EXIT on the completeness check: the
		// declared way out. Without it an unanswered case runs until the
		// engine's ceiling and is reported as a broken flow.
		//
		// Asserted on the node's exits[], because that is the ONLY place the
		// engine reads conditions (FlowTokenRouter matches edges by fromExit).
		// This spec used to assert conditions on the edges themselves —
		// encoding the exact wrong shape that routed a COMPLETE case to
		// "Wacht op aanvulling", since an edge-level condition is silently
		// invisible to the router.
		const check = nodes.find((n) => String(n.id ?? '') === 'check-complete')
		expect(check, 'The completeness check node must exist.').toBeTruthy()
		const exits = (check?.exits ?? []) as Array<Record<string, unknown>>
		const unconditional = exits.filter(
			(e) => e.condition === undefined || e.condition === null,
		)
		expect(unconditional).toHaveLength(1)

		// And the edge leaving through that exit is the stalled route.
		const elseEdge = edges.find(
			(e) =>
				String(e.from ?? '') === 'check-complete'
				&& String(e.fromExit ?? '') === String(unconditional[0].id ?? ''),
		)
		expect(String(elseEdge?.to ?? '')).toBe('status-gestrand')

		// No edge anywhere in the flow may carry a condition of its own: the
		// engine never reads it, so it would be a branch that does not exist.
		expect(
			edges.filter((e) => e.condition !== undefined && e.condition !== null),
		).toHaveLength(0)
	})

	test('the case type ships every status the flow moves to', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq/case-types')

		const body = page.locator('body')
		await expect(body).not.toContainText('Internal Server Error')

		// The case type must be there for any of the rest to mean anything.
		await expect(
			body,
			`The seeded case type "${CASE_TYPE}" is missing, so every status the flow `
				+ 'names would fail to resolve and the case would never move.',
		).toContainText(CASE_TYPE, { timeout: 15000 })
	})

	test('an incomplete case is asked for more, and says so to the applicant', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/dossiq/cases')

		const body = page.locator('body')
		await expect(body).not.toContainText('Internal Server Error')
		await expect(
			body,
			`The seeded case "${INCOMPLETE_CASE}" is missing — it is the one that exercises `
				+ 'the applicant loop, so without it this journey proves nothing.',
		).toContainText(INCOMPLETE_CASE, { timeout: 15000 })

		await page.getByText(INCOMPLETE_CASE).first().click()

		// What the applicant reads. Either it is still at intake, or the flow
		// has already asked them for more — both are correct depending on
		// whether the run has been advanced, and neither is an internal detail.
		await expect(page.locator('body')).toContainText(
			/Ontvangen|Wacht op aanvulling/,
			{ timeout: 15000 },
		)
	})

	test('a deep link to a case survives a hard reload, under both URL forms', async ({
		page,
	}) => {
		// 🔴 FOUR PAGE LOADS, AND THE DEFAULT BUDGET HOLDS ABOUT TWO.
		//
		// The index, the click into the case, and then the same case hard
		// loaded under both URL spellings. On CI four workers share one
		// `php -S`, where a load costs 13 to 23 seconds, so the 60 second
		// default cannot fit this test and it timed out on `development`
		// (run 34578124024) while passing everywhere quieter. `test.slow()`
		// triples the budget, which is the honest size of this journey rather
		// than a retry until it fits.
		test.slow()

		// The RELOAD of a deep link is the test. Sidebar navigation stays
		// inside the loaded SPA and never re-derives the router base, so it
		// worked even while every hard load of `/apps/dossiq/cases/<id>`
		// answered 200 from the server and was rewritten client-side to
		// `/apps/dossiq/` — the catch-all redirect, fired because the base
		// came from generateUrl() while the page was served under the other
		// URL form. Reach a case the supported way first, then hard-load the
		// URL the browser ended up on.
		// Named budgets on every navigation below. Without one, a slow load
		// spends the whole test budget and Playwright reports a bare
		// "Test timeout exceeded" that names neither the URL nor the step.
		await page.goto('/index.php/apps/dossiq/cases', { timeout: 45000 })
		await expect(
			page.locator('body'),
			`The seeded case "${INCOMPLETE_CASE}" is missing.`,
		).toContainText(INCOMPLETE_CASE, { timeout: 15000 })
		await page.getByText(INCOMPLETE_CASE).first().click()
		await expect(page).toHaveURL(/\/cases\/[^/?#]+$/, { timeout: 15000 })

		const deepLink = new URL(page.url())

		// Form 1: exactly the URL the browser shows. A hard load must land on
		// the case, not the dashboard.
		await page.goto(deepLink.pathname, { timeout: 45000 })
		await expect(page.locator('body')).toContainText(INCOMPLETE_CASE, {
			timeout: 15000,
		})
		expect(
			new URL(page.url()).pathname,
			'The deep link must survive: a rewrite to the app root is the catch-all eating it.',
		).toMatch(/\/cases\/[^/?#]+$/)

		// Form 2: the SAME resource under the other server-accepted spelling
		// (with the front-controller prefix stripped or added). The server
		// answers 200 for both; the SPA must render the case for both.
		const altPath = deepLink.pathname.includes('/index.php/')
			? deepLink.pathname.replace('/index.php', '')
			: deepLink.pathname.replace('/apps/dossiq', '/index.php/apps/dossiq')
		await page.goto(altPath, { timeout: 45000 })
		await expect(page.locator('body')).toContainText(INCOMPLETE_CASE, {
			timeout: 15000,
		})
		expect(new URL(page.url()).pathname).toMatch(/\/cases\/[^/?#]+$/)
	})

	test('a complete case is not asked for anything', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq/cases')

		const body = page.locator('body')
		await expect(
			body,
			`The seeded case "${COMPLETE_CASE}" is missing.`,
		).toContainText(COMPLETE_CASE, { timeout: 15000 })

		await page.getByText(COMPLETE_CASE).first().click()

		// It must never land in the applicant-wait status: it was complete.
		await expect(page.locator('body')).not.toContainText('Wacht op aanvulling')
	})

	test.describe('with a run seeded to read back', () => {
		// THE RUN IS SEEDED, NOT FOUND. This test used to read the newest run
		// on the instance and skip when there was none, and CI never holds
		// one: the shipped flow arrives DISABLED by spec (2.2). So it skipped
		// on every CI run. It also read that run in two requests, list then
		// objects, so another worker's teardown could delete the run in
		// between. Now it runs a flow of its own and reads that run by uuid,
		// the same way case-detail-flow-runs.spec.ts seeds its runs.
		let api: APIRequestContext
		let token = ''

		/** What beforeAll seeds, and afterAll removes. */
		const seeded = { flowId: '', run: '' }

		test.beforeAll(async ({ baseURL }) => {
			// A flow, a publish and a synchronous run: more than the default
			// hook budget on a loaded runner.
			test.setTimeout(120_000)
			api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
			token = await getRequestToken(api)

			// No case: the objects read needs a run, and a run needs no
			// subject. Leaving it subjectless also keeps it out of every
			// case-scoped run read another spec makes.
			seeded.flowId = await createFlow(
				api,
				token,
				`${RUN_PREFIX} Flow run objects`,
				'Throwaway flow seeded by case-flow-human-steps.spec.ts.',
			)
			await publishFlow(api, token, seeded.flowId)

			const run = await runFlow(api, token, seeded.flowId)
			seeded.run = String(run.uuid ?? '')
			expect(
				seeded.run,
				'The run endpoint must answer with the run it made.',
			).not.toBe('')
		})

		test.afterAll(async () => {
			if (!api) return
			test.setTimeout(120_000)
			let leaks: string[] = []

			try {
				if (seeded.flowId !== '') {
					leaks = await removeFlow(api, token, seeded.flowId, [seeded.run])
				}
			} finally {
				await api.dispose()
			}

			if (leaks.length > 0) {
				throw new Error(
					'e2e teardown left seeded rows behind, so the next run on this '
						+ `instance starts dirty: ${leaks.join(', ')}`,
				)
			}
		})

		test('a run reports the objects it touched, grouped by node', async ({
			page,
		}) => {
			// The traceability half. Asserted through the API because it is an
			// API: the panel that renders it lives in nextcloud-vue and is
			// covered there.
			const touched = await page.request.get(
				`${OR_API}/flow-runs/${seeded.run}/objects`,
			)

			expect(
				touched.ok(),
				'GET /api/flow-runs/{uuid}/objects must answer for a run the caller can read.',
			).toBeTruthy()

			const payload = await touched.json()
			expect(payload).toHaveProperty('run', seeded.run)
			expect(payload).toHaveProperty('flowId', seeded.flowId)
			// What must never happen is the key being absent.
			expect(payload).toHaveProperty('nodes')
			expect(Array.isArray(payload.nodes)).toBeTruthy()
			// A manual start into an end writes nothing, so the honest answer
			// is an empty list. That is also the scoping proof: a read that
			// ignored the run would hand back what other runs wrote.
			expect(
				payload.nodes,
				"A run that wrote nothing must report no objects, and none of another run's.",
			).toEqual([])
		})
	})

	test('the objects endpoint does not answer for a run that does not exist', async ({
		page,
	}) => {
		// The refusal must not distinguish "no such run" from "not yours", or
		// the endpoint becomes a probe for which runs exist.
		const response = await page.request.get(
			'/index.php/apps/openregister/api/flow-runs/00000000-0000-0000-0000-000000000000/objects',
		)

		expect(response.status()).toBe(404)
	})
})
