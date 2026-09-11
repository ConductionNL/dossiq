/**
 * The case half of the waiting relationship: a case shows its own flow run
 * and where it stands.
 *
 * WHAT THIS ASSERTS. Four things a caseworker sees on the case detail page,
 * none of them internal: the runs widget is there; a case that has a run
 * lists THAT run and nobody else's; clicking the run opens that run on the
 * flow page; a case that never ran says so in its own words, distinct from
 * "nothing running right now". The widget itself is nextcloud-vue's
 * CnFlowRunsWidget in subject mode, covered there; what belongs to dossiq is
 * the placement, the subject binding, the row route and the copy, so that is
 * what these tests pin.
 *
 * THE RUN IS SEEDED, NOT FOUND. The run-bearing test used to skip whenever
 * the instance held no run with a subject, and CI never holds one: the shipped
 * flow arrives DISABLED by spec (2.2). A skip that fires on every CI run is a
 * test that has never run. So the spec authors a flow of its own, publishes
 * it and runs it on two cases it seeds, posting the run the way the case's
 * "Start a sub-process" dialog does (src/dialogs/CaseStartFlowDialog.vue).
 *
 * SCOPING IS PROVED BY COUNT, NOT BY NAME. A row shows the flow's name, and
 * both seeded runs are of the same flow, so the two cases' runs are
 * indistinguishable by text. What does distinguish them is the number: the
 * instance holds two runs of that flow, and the case page must list one. A
 * widget that ignored its subject would list both.
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT FIXTURE. Where a seeded case is missing,
 * or OpenRegister refuses a seeding call, the spec fails naming it rather
 * than skipping: a skip cannot tell "not seeded" from "the seeder is broken".
 *
 * @e2e pending proof rig: written against the widget's DOM contract and the
 * OpenRegister endpoints it reads; not yet run against an instance serving
 * this bundle (the local instance mounts another checkout as dossiq).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from './helpers/auth.ts'
import {
	adoptableCaseTypes,
	getRequestToken,
	listObjects,
	objectId,
	purgeObject,
	RUN_PREFIX,
	seedCase,
} from './helpers/fixtures.ts'
import { navToRoute } from './helpers/nav.ts'

/** The seeded case that is INCOMPLETE, and should carry the applicant loop's run. */
const INCOMPLETE_CASE = 'Schuur Molenweg 3'

/** OpenRegister's API root, which owns flows and their runs. */
const OR_API = '/index.php/apps/openregister/api'

/** The widget's root, as CnFlowRunsWidget renders it. */
const WIDGET = '.cn-flow-runs-widget'

/** One run row, live or finished. */
const ROW = '.cn-flow-runs-widget__row'

/** The widget title as the manifest declares it, in either locale the instance may run. */
const TITLE = /Flow runs|Flow-uitvoeringen/

/** The never-ran line, in either locale. */
const NEVER_RAN = /No flows have run yet|Er is nog geen flow uitgevoerd/

/** The rows the widget shows at most, mirroring the manifest's `content.limit`. */
const LIMIT = 5

/**
 * Every run on the instance the caller may read, with its subject.
 *
 * Goes through the org-wide list because the question is which cases have
 * runs AT ALL, before any one case is opened.
 */
async function allRuns(
	api: APIRequestContext,
): Promise<Array<Record<string, unknown>>> {
	const response = await api.get(
		'/index.php/apps/openregister/api/flow-runs?limit=50',
	)
	expect(response.ok(), 'The flow-runs surface must answer.').toBeTruthy()
	const body = await response.json()
	return (body?.results ?? []) as Array<Record<string, unknown>>
}

/**
 * The runs the subject-scoped endpoints return for one case: what the
 * widget is specified to render, live and finished.
 */
async function runsForSubject(
	api: APIRequestContext,
	subject: string,
): Promise<number> {
	let total = 0
	for (const surface of ['active', 'completed']) {
		const response = await api.get(
			`/index.php/apps/openregister/api/flow-runs/${surface}?subject=${encodeURIComponent(subject)}&limit=50`,
		)
		expect(
			response.ok(),
			`GET /api/flow-runs/${surface}?subject= must answer: the widget reads it.`,
		).toBeTruthy()
		const body = await response.json()
		total += ((body?.results ?? []) as unknown[]).length
	}
	return total
}

/** Open one case's detail page and wait for its runs widget to settle. */
async function openCase(page: Page, uuid: string) {
	await navToRoute(page, `/cases/${uuid}`)
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
	const widget = page.locator(WIDGET)
	await expect(
		widget,
		'The flow runs widget must render on the case detail page.',
	).toBeVisible({
		timeout: 15000,
	})
	// The loading spinner is the only state that is not an answer.
	await expect(widget.locator('.cn-flow-runs-widget__loading')).toHaveCount(0, {
		timeout: 15000,
	})
	return widget
}

/**
 * The headers a CSRF-protected OpenRegister write needs. The flow routes are
 * plain app routes, so the request token is what lets a POST or DELETE in.
 */
function writeHeaders(token: string): Record<string, string> {
	return {
		requesttoken: token,
		'OCS-APIRequest': 'true',
		'Content-Type': 'application/json',
	}
}

/**
 * One seeding POST to OpenRegister, failing with the server's own answer so a
 * refused seed names its reason instead of surfacing later as an empty widget.
 */
async function orPost(
	api: APIRequestContext,
	token: string,
	path: string,
	data: Record<string, unknown> = {},
): Promise<Record<string, unknown>> {
	const response = await api.post(`${OR_API}${path}`, {
		headers: writeHeaders(token),
		data,
	})
	expect(
		response.ok(),
		`POST ${path} -> ${response.status()} ${await response.text()}`,
	).toBeTruthy()
	return (await response.json()) as Record<string, unknown>
}

test.describe('Case detail — flow runs widget', () => {
	test('the case detail page renders the runs widget under its title', async ({
		page,
	}) => {
		await navToRoute(page, '/cases')

		const body = page.locator('body')
		await expect(body).not.toContainText('Internal Server Error')
		await expect(
			body,
			`The seeded case "${INCOMPLETE_CASE}" is missing, so there is no case to open.`,
		).toContainText(INCOMPLETE_CASE, { timeout: 15000 })

		await page.getByText(INCOMPLETE_CASE).first().click()

		await expect(page.locator(WIDGET)).toBeVisible({ timeout: 15000 })
		// The card heading is the manifest title run through the app's
		// catalogue: English or Dutch, never a raw key and never absent.
		await expect(page.getByRole('heading', { name: TITLE })).toBeVisible()
	})

	test.describe('with a run seeded on a case', () => {
		let api: APIRequestContext
		let token = ''

		/** What beforeAll seeds, and afterAll removes. */
		const seeded = {
			flowId: '',
			flowName: `${RUN_PREFIX} Flow runs widget`,
			version: 0,
			caseWithRun: '',
			otherCase: '',
			run: '',
			otherRun: '',
		}

		/**
		 * Run the seeded flow with one case as its subject.
		 *
		 * The subject is `{uuid, register, schema}` because those are the three
		 * keys OpenRegister's FlowRunRow stores, and the widget filters on the
		 * first; CaseStartFlowDialog posts exactly this. `sync: true` finishes
		 * the run inside the request, so it is in the case's history before the
		 * page opens and no background worker can move it between the widget's
		 * live and finished lists mid-test.
		 */
		async function runOnCase(caseId: string): Promise<string> {
			const run = await orPost(api, token, `/flows/${seeded.flowId}/run`, {
				subject: { uuid: caseId, register: 'dossiq', schema: 'case' },
				sync: true,
			})
			expect(
				run.subjectUuid,
				'The run must carry the case as its subject.',
			).toBe(caseId)
			// The version is what the flow page pins its canvas to. A run that
			// carries none opens on the flow's current graph with no banner, which
			// is the very screen the second test exists to rule out.
			expect(
				Number(run.flowVersion),
				'The run must be pinned to the version published above.',
			).toBe(seeded.version)
			const uuid = String(run.uuid ?? '')
			expect(
				uuid,
				'The run endpoint must answer with the run it made.',
			).not.toBe('')
			return uuid
		}

		test.beforeAll(async ({ baseURL }) => {
			// Two cases, a flow, a publish and two synchronous runs: more than
			// the default hook budget on a loaded runner.
			test.setTimeout(120_000)
			api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
			token = await getRequestToken(api)

			// REUSE a published case type, for the reason case-task-pane.spec.ts
			// gives: `case` is archival, so a type seeded here and removed in
			// teardown could leave these cases pointing at a type that is gone.
			const caseTypes = await adoptableCaseTypes(api)
			expect(
				caseTypes.length,
				'The instance must ship a PUBLISHED case type to seed a case against.',
			).toBeGreaterThan(0)
			const caseType = objectId(caseTypes[0])

			// Two cases, so there is another case's run for the widget to wrongly
			// include. Both carry RUN_PREFIX, so a teardown that never ran is
			// swept by global-setup before the next suite.
			const [withRun, other] = await Promise.all([
				seedCase(api, token, {
					title: `${RUN_PREFIX} Flow runs with a run`,
					caseType,
				}),
				seedCase(api, token, {
					title: `${RUN_PREFIX} Flow runs other case`,
					caseType,
				}),
			])
			seeded.caseWithRun = objectId(withRun)
			seeded.otherCase = objectId(other)
			expect(seeded.caseWithRun, 'The seeded case must carry an id.').not.toBe(
				'',
			)
			expect(seeded.otherCase, 'The second case must carry an id.').not.toBe(
				'',
			)

			// A flow this spec OWNS, rather than the shipped one enabled for the
			// occasion: enabling that one would start it on every case any other
			// worker creates while this file runs. The smallest graph the engine
			// accepts, a manual start into an end: a node with no way out is
			// refused at publish and at run time (FlowDeadEnd).
			const flow = await orPost(api, token, '/flows', {
				name: seeded.flowName,
				description:
					'Throwaway flow seeded by case-detail-flow-runs.spec.ts.',
				app: 'dossiq',
				// Off, and it can stay off. `enabled` only decides whether the
				// flow's TRIGGERS are subscribed; a run asked for by hand does not
				// consult it, so this flow can never start on its own.
				enabled: false,
				nodes: [
					{
						id: 'start',
						type: 'openregister.trigger-manual',
						config: {},
						position: { x: 0, y: 0 },
					},
					{
						id: 'end',
						type: 'openregister.end',
						config: {},
						position: { x: 0, y: 160 },
					},
				],
				edges: [{ id: 'start-end', from: 'start', to: 'end' }],
			})
			seeded.flowId = String(flow.uuid ?? '')
			expect(seeded.flowId, 'The created flow must carry a uuid.').not.toBe('')

			// PUBLISHED, although the run endpoint would walk a draft. A draft
			// test run is recorded with no version, and without one the flow page
			// has no graph to pin, so the banner the second test reads would
			// never appear however well the click worked.
			const published = await orPost(
				api,
				token,
				`/flows/${seeded.flowId}/publish`,
			)
			seeded.version = Number(published.version ?? 0)
			expect(
				seeded.version,
				'Publishing must answer with the version it made.',
			).toBeGreaterThan(0)

			seeded.run = await runOnCase(seeded.caseWithRun)
			seeded.otherRun = await runOnCase(seeded.otherCase)
		})

		test.afterAll(async () => {
			if (!api) return
			test.setTimeout(120_000)
			const leaks: string[] = []

			try {
				// The flow FIRST, because its runs go with it. OpenRegister
				// publishes no delete for a run: DELETE /api/flows/{id} is the
				// only route that removes one, by sweeping the flow's runs, steps,
				// state and versions (FlowService::delete). That sweep swallows
				// its own failure and still answers 200, so the runs are read
				// back rather than the status trusted.
				if (seeded.flowId !== '') {
					await api
						.delete(`${OR_API}/flows/${seeded.flowId}`, {
							headers: writeHeaders(token),
						})
						.catch(() => undefined)
					const flowLeft = await api
						.get(`${OR_API}/flows/${seeded.flowId}`)
						.catch(() => null)
					if (flowLeft === null || flowLeft.status() !== 404) {
						leaks.push(`flow ${seeded.flowId}`)
					}
					for (const run of [seeded.run, seeded.otherRun]) {
						if (run === '') continue
						const runLeft = await api
							.get(`${OR_API}/flow-runs/${run}`)
							.catch(() => null)
						if (runLeft === null || runLeft.status() !== 404) {
							leaks.push(`flow run ${run}`)
						}
					}
				}

				// Then the cases. `case` is archival, so purgeObject falls through
				// to the occ purge, and throws when occ cannot be reached at all.
				for (const id of [seeded.caseWithRun, seeded.otherCase]) {
					if (id === '') continue
					if ((await purgeObject(api, token, 'case', id)) === false) {
						leaks.push(`case ${id}`)
					}
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

		test("a case with a run lists exactly its own runs, and not another case's", async ({
			page,
		}) => {
			const expected = Math.min(
				await runsForSubject(page.request, seeded.caseWithRun),
				LIMIT,
			)
			expect(
				expected,
				'the subject-scoped reads must see the run beforeAll started',
			).toBeGreaterThan(0)

			// The other half of the proof: the instance really does hold another
			// case's run of this flow. Without it, listing one row would prove
			// nothing about scoping.
			const response = await page.request.get(
				`${OR_API}/flow-runs?flowId=${seeded.flowId}&limit=50`,
			)
			expect(response.ok(), 'The flow-runs surface must answer.').toBeTruthy()
			const ofThisFlow = ((await response.json())?.results ?? []) as unknown[]
			expect(
				ofThisFlow.length,
				'Both seeded runs must be on the instance, one per case.',
			).toBe(2)

			const widget = await openCase(page, seeded.caseWithRun)

			await expect(widget.locator(ROW)).toHaveCount(expected, {
				timeout: 15000,
			})
			// The never-ran line and a listed run are mutually exclusive by design.
			await expect(widget).not.toContainText(NEVER_RAN)
			// Two runs of this flow exist and this case holds one: a widget that
			// ignored its subject would list the flow twice.
			await expect(
				widget.locator(ROW, { hasText: seeded.flowName }),
			).toHaveCount(1)
		})

		test('clicking a run opens that run on the flow page, on the graph it ran', async ({
			page,
		}) => {
			// The user's report: a click on a row here opened the flow screen
			// with no run selected, and before that on an empty canvas. The row
			// has to carry the RUN, not only its flow.
			const widget = await openCase(page, seeded.caseWithRun)
			const row = widget.locator(ROW, { hasText: seeded.flowName })
			await expect(
				row,
				'The seeded run must be listed to be clicked.',
			).toHaveCount(1, { timeout: 15000 })

			await row.click()

			// The flow id alone is the old bug: it opens the flow, not the run.
			await expect(
				page,
				'A row click must open the flow page carrying the clicked run as ?run=.',
			).toHaveURL(
				(url) =>
					url.pathname.endsWith(`/flows/${seeded.flowId}`)
					&& url.searchParams.get('run') === seeded.run,
			)

			// The run is inspected: the sidebar shows the run, not the flow.
			const sidebar = page.getByTestId('run-detail-sidebar')
			await expect(
				sidebar,
				'The flow page must open with the run inspected.',
			).toBeVisible({ timeout: 15000 })

			// And the canvas holds the graph the run executed. The banner only
			// appears once the page has read the run's version and loaded that
			// version's graph, so it is the evidence of the pin. Save being
			// disabled is not: a published flow locks the canvas either way.
			const banner = page.getByTestId('flow-message-viewing-version')
			await expect(
				banner,
				'The canvas must say it shows the version this run used.',
			).toBeVisible({ timeout: 15000 })
			await expect(banner).toContainText(String(seeded.version))
			// Not an empty canvas: both steps of the pinned graph are drawn.
			await expect(page.locator('.cn-flow-detail__node')).toHaveCount(2)

			// The way back closes the run, and the banner goes with it: it
			// belonged to the run, not to the flow.
			await page.getByTestId('run-back').click()
			await expect(banner).toHaveCount(0)
			await expect(sidebar).toHaveCount(0)
		})
	})

	test('a case with no run says so, in its own words', async ({ page }) => {
		const runs = await allRuns(page.request)
		const withRuns = new Set(runs.map((run) => String(run.subjectUuid ?? '')))

		const cases = await listObjects(page.request, 'case')
		expect(
			cases.length,
			'No cases on this instance: nothing to open.',
		).toBeGreaterThan(0)

		const quiet = cases.find((c) => !withRuns.has(objectId(c)))
		expect(
			quiet,
			'Every case on this instance carries a run; the never-ran state has nothing to render on.',
		).toBeTruthy()

		const widget = await openCase(page, objectId(quiet))

		await expect(widget.locator('.cn-flow-runs-widget__empty')).toContainText(
			NEVER_RAN,
		)
		await expect(widget.locator(ROW)).toHaveCount(0)
		// Distinct from "nothing running now": that line only belongs to a
		// case whose history is non-empty.
		await expect(widget).not.toContainText(
			/No flow is running for this case|Er loopt op dit moment geen flow/,
		)
	})
})
