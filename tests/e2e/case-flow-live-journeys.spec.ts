import type { APIRequestContext, Page } from '@playwright/test'

/**
 * The case flow, LIVE: the journeys of tasks 7.1 (second half) and 7.2 of
 * `case-flow-human-steps`, walked by the SHIPPED `Case behandeling` flow on
 * cases this spec files.
 *
 * WHAT IT ASSERTS. What a person sees at every waiting point: the status the
 * applicant reads, the task they are given, the document on the closed case.
 * Run rows are read only to find WHICH run to follow and to prove the
 * traceability read (7.3).
 *
 * HOW A RUN STARTS, AND WHY THIS FILE NO LONGER SKIPS ON CI. The shipped flow
 * arrives DISABLED, by spec (2.2), and must stay that way on any instance more
 * than one thing uses: enabling it subscribes its `object.created` trigger, so
 * every case ANY spec creates would start a run, and CI runs four spec files
 * at once. This file used to skip all nine tests whenever the flow was
 * disabled. CI never enables it, so the file never ran there, while it stood
 * as the `@e2e` anchor for two scenarios.
 *
 * The journey does not need the flow enabled. `enabled` decides only whether
 * the flow's triggers are subscribed; a run asked for by hand does not consult
 * it. So where the flow is disabled, the spec starts the shipped flow on its
 * own case through `POST /api/flows/{uuid}/run` with the case as subject, the
 * call CaseStartFlowDialog makes, and no other case is touched. The run enters
 * at the trigger node exactly as a trigger-started run does, and the rest of
 * the graph is the shipped one. Where an operator HAS adopted the flow
 * (enabled, with an owner), the trigger starts the run instead and the spec
 * follows it, as it always did.
 *
 * It runs the shipped flow rather than a copy of it, which is where it parts
 * from case-detail-flow-runs.spec.ts: that spec pins a widget and any flow
 * will do, this one pins what THIS flow does, and a copy would prove only the
 * copy. The price, stated rather than discovered: OpenRegister publishes no
 * delete for a run, so the runs this file starts stay in the shipped flow's
 * history after the teardown purges their cases.
 *
 * HOW THE WORKER IS DRIVEN. A run is QUEUED, and moves only on a pass of
 * openregister's FlowRunWorker, which is also what picks a run back up after
 * a completed task wakes it. A test cannot wait for cron, so it performs the
 * passes itself, through `helpers/occ.ts#occFlowWorkerPass`, which reaches
 * `occ` the way the teardown purge does (on CI, `php occ` from the server
 * root). FLOW_WORKER_CMD overrides that with a shell command of its own, for
 * a rig the occ resolution does not cover, for example:
 *
 *   FLOW_WORKER_CMD='docker exec -u www-data dossiq-proof-nextcloud-1 \
 *     php occ background-job:execute <FlowRunWorker job id> --force-execute'
 *
 * WHAT CAN BE GENUINELY ABSENT: decidiq. It is an optional peer, and without it
 * `dossiq.requestDecision` fails closed, which is its specified behaviour. The
 * three journeys that conclude a decision skip naming that where decidiq is
 * not installed; CI installs it (additional-apps in code-quality.yml), so
 * there they run. The supplement loop, the complete case and the
 * traceability read run on every instance.
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT PRECONDITION. A missing flow, a missing
 * case type or a worker pass that cannot run each fails with a message naming
 * what was missing. Skipping would report "not set up" as a pass.
 *
 * The tests run in SERIAL order: a journey that cannot start its run has
 * nothing to complete, so the later steps are reported as skipped rather than
 * as a second failure with a misleading message.
 *
 * Screenshots of each waiting point land in PROOF_SCREENS_DIR (default
 * `test-results/proof-screens`).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
import { expect, request, test } from '@playwright/test'
import { execSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'
import {
	getRequestToken,
	invokeFlowTask,
	listFlowTasks,
	purgeObject,
	RUN_PREFIX,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'
import { occFlowWorkerPass } from './helpers/occ.ts'

// 180s a test, the budget the root config's `live-journeys` project always
// gave this file. The CI config's 60s default does not fit a test that runs
// two occ bootstraps and then loads two pages on a loaded runner.
test.describe.configure({ mode: 'serial', timeout: 180_000 })

const FLOW_NAME = 'Case behandeling'
const CASE_TYPE = 'Omgevingsvergunning kleine bouwactiviteit'
const SCREENS =
	process.env.PROOF_SCREENS_DIR
	?? path.join(__dirname, '..', '..', 'test-results', 'proof-screens')
const WORKER_CMD = process.env.FLOW_WORKER_CMD ?? ''
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'
const OR = '/index.php/apps/openregister/api'

/**
 * The case header's status badge, the one place the CURRENT status is shown.
 *
 * A status name cannot be asserted on `body`: the steps widget lists every
 * status of the case type, so "In behandeling" is on the page whatever the case
 * is in, and "Wacht op aanvulling" is never absent from it.
 */
const STATUS_BADGE = '[data-testid="case-header-status"]'

/** Copy the applicant supplies when asked to complete their case. */
const SUPPLIED_DESCRIPTION =
	'Aanvulling: bouwtekening, constructieberekening en situatieschets zijn nu bijgevoegd.'

/**
 * Why the three decision journeys stand down without decidiq.
 *
 * decidiq is an optional peer of dossiq, not a dependency, so an instance can
 * lack it. `dossiq.requestDecision` then FAILS CLOSED
 * (ContractDecisionDelegationService throws "the decision app is not
 * installed"), so the run stops on its first decision and there is nothing in
 * decidiq to conclude. That is a real absence the app does not control, not a
 * state the spec could seed.
 */
const DECIDIQ_ABSENT =
	'decidiq is not installed, so dossiq.requestDecision fails closed and raises no '
	+ 'decision to conclude. Install decidiq beside dossiq to run these journeys '
	+ '(CI does, through additional-apps in .github/workflows/code-quality.yml).'

type Json = Record<string, any>

/**
 * The API view. Basic auth as the admin operator, the same way ci-seed.sh
 * talks to the instance: it passes RBAC with a real identity and needs no
 * CSRF token because `OCS-APIRequest` short-circuits the check.
 */
async function apiContext(): Promise<APIRequestContext> {
	return request.newContext({
		baseURL: BASE_URL,
		httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
}

async function getJson(api: APIRequestContext, url: string): Promise<Json> {
	const res = await api.get(url)
	expect(res.ok(), `GET ${url} answered ${res.status()}`).toBeTruthy()
	return (await res.json()) as Json
}

async function results(api: APIRequestContext, url: string): Promise<Json[]> {
	const body = await getJson(api, url)
	return (body.results ?? body ?? []) as Json[]
}

async function shippedFlow(api: APIRequestContext): Promise<Json | null> {
	const flows = await results(api, `${OR}/flows?limit=200`)
	return flows.find((f) => String(f.name ?? '') === FLOW_NAME) ?? null
}

/**
 * Whether a Nextcloud app is enabled, read from the provisioning API.
 *
 * Asked of Nextcloud rather than inferred from a 404 on the app's register:
 * a register can be missing for reasons that have nothing to do with the app
 * being installed, and a skip has to name the real absence.
 *
 * @param api   The API view (admin: the app list is admin-only).
 * @param appId The app id as `appinfo/info.xml` declares it.
 */
async function appEnabled(api: APIRequestContext, appId: string): Promise<boolean> {
	const res = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')
	expect(
		res.ok(),
		`The enabled-apps list answered ${res.status()}, so whether ${appId} is installed is unknown.`,
	).toBeTruthy()
	const apps = ((await res.json())?.ocs?.data?.apps ?? []) as string[]
	return apps.includes(appId)
}

async function caseTypeId(api: APIRequestContext): Promise<string> {
	const types = await results(api, `${OR}/objects/dossiq/caseType?_limit=100`)
	const type = types.find((t) => String(t.title ?? t.name ?? '') === CASE_TYPE)
	expect(
		type,
		`The seeded case type "${CASE_TYPE}" is missing; the flow has nothing to run against.`,
	).toBeTruthy()
	return String(type!.id)
}

/** Status uuid → name, within the case type. The flow moves BY NAME. */
async function statusNames(
	api: APIRequestContext,
	caseType: string,
): Promise<Map<string, string>> {
	const statuses = await results(
		api,
		`${OR}/objects/dossiq/statusType?_limit=100&caseType=${caseType}`,
	)
	return new Map(
		statuses.map((s) => [String(s.id), String(s.title ?? s.name ?? '')]),
	)
}

async function createCase(api: APIRequestContext, body: Json): Promise<Json> {
	const res = await api.post(`${OR}/objects/dossiq/case`, { data: body })
	expect(
		res.status(),
		`Creating a case answered ${res.status()}: ${await res.text()}`,
	).toBe(201)
	return (await res.json()) as Json
}

/**
 * PUT a partial update onto an existing object, merged over its current body.
 *
 * OpenRegister's PUT is a full replace validated against the schema, so a
 * bare partial body 400s on every required property the patch does not carry
 * ("The required properties (title, caseType) are missing"). Measured live on
 * the proof rig 2026-09-01. Same pattern as `helpers/fixtures.ts#updateObject`.
 */
async function updateObject(
	api: APIRequestContext,
	schema: string,
	id: string,
	body: Json,
	register = 'dossiq',
): Promise<Json> {
	const current = await getJson(api, `${OR}/objects/${register}/${schema}/${id}`)
	const res = await api.put(`${OR}/objects/${register}/${schema}/${id}`, {
		data: { ...current, ...body },
	})
	expect(
		res.ok(),
		`Updating ${schema} ${id} answered ${res.status()}: ${await res.text()}`,
	).toBeTruthy()
	return (await res.json()) as Json
}

/**
 * Complete a task through the ENGINE's own verb.
 *
 * 🔴 IT USED TO PUT A `caseTask` OBJECT, AND BY THE TIME IT WAS CHANGED IT
 * COULD NOT HAVE WORKED. `AskPersonTaskStore::create()` writes the engine
 * (`EngineTaskGateway::mirrorImport`), not the register, so the object this
 * helper read and updated was never created: the read answered 404 and the
 * spec failed on the fixture rather than on the journey. The ADR-098
 * follow-up this helper's old note deferred to has LANDED — dossiq tasks are
 * openregister `Task` rows now, so `/api/flow-tasks/{uuid}/complete` is the
 * completion API, and it is the endpoint `TaskCompletionResumeListener`
 * listens behind (it takes `TaskTerminalEvent`, not `ObjectUpdatedEvent`).
 *
 * There is no available → active walk left to perform. The register schema's
 * CMMN lifecycle refused a one-step move to `completed`; the engine's
 * `complete` verb takes a non-terminal task straight there and refuses only a
 * task that is ALREADY terminal.
 *
 * The state is read back rather than inferred from the 200. A verb that
 * answered and changed nothing is the exact failure this migration keeps
 * producing, and here it would surface two worker passes later as a case
 * stuck in the wrong status.
 *
 * @param api   The API view.
 * @param token CSRF request-token for the write.
 * @param uuid  The ENGINE task uuid. Not a numeric id: no route accepts one.
 */
async function completeTask(
	api: APIRequestContext,
	token: string,
	uuid: string,
): Promise<void> {
	await invokeFlowTask(api, token, uuid, 'complete')

	const stored = await getJson(api, `${OR}/flow-tasks/${uuid}`)
	expect(
		String((stored?.results ?? stored)?.state ?? ''),
		`task ${uuid} answered the complete verb but did not store the state`,
	).toBe('completed')
}

async function runsForCase(api: APIRequestContext, caseId: string): Promise<Json[]> {
	const runs = await results(api, `${OR}/flow-runs?limit=100`)
	return runs.filter((r) => String(r.subjectUuid ?? '') === caseId)
}

/**
 * Every task standing on one case, read from the ENGINE.
 *
 * 🔴 IT USED TO LIST `/objects/dossiq/task`, WHICH IS NOT A SCHEMA THIS APP
 * SHIPS. The slug was `caseTask`, so the read 404'd; and even spelled right it
 * would have answered `[]`, because the flow's human step writes the engine
 * and stopped writing register objects. Both wrong spellings fail the same
 * silent way — an empty list reads as "the flow created no task", which is a
 * far more alarming thing than what happened.
 *
 * `scope=all` because the question is what work the CASE carries. The inbox
 * defaults to `assigned`, and the shipped flow assigns one of these steps to
 * the `behandelaars` group rather than to the reader, so the default would
 * answer `[]` for a case that has one.
 *
 * Three field names change with the table and every caller reads the new
 * ones: the id is `uuid`, `flowRun` is `runUuid` and `flowNode` is `nodeId`.
 * (`EngineTaskGateway::find()` translates them back into the register's
 * vocabulary for PHP callers; the HTTP rows here are untranslated.)
 *
 * @param api    The API view.
 * @param caseId The case.
 */
async function tasksForCase(
	api: APIRequestContext,
	caseId: string,
): Promise<Json[]> {
	return listFlowTasks(api, {
		objectUuid: caseId,
		scope: 'all',
		limit: '50',
	})
}

/**
 * One worker pass: what cron would do.
 *
 * Through FLOW_WORKER_CMD when the rig names one, otherwise through the same
 * `occ` the teardown purge uses. The exit code is checked, but it is not the
 * evidence: Nextcloud logs a job that throws and exits 0 anyway, which is why
 * every caller goes through `advanceRun()` and reads the run back.
 */
async function workerPass(): Promise<void> {
	if (WORKER_CMD !== '') {
		execSync(WORKER_CMD, { stdio: 'pipe', timeout: 120_000 })
		return
	}
	const pass = await occFlowWorkerPass()
	expect(
		pass.code,
		`The flow worker pass exited ${pass.code}: ${pass.output.trim().slice(0, 400)}`,
	).toBe(0)
}

/**
 * Whether the worker still owes this run a pass.
 *
 * Queued and running are the obvious two. A SUSPENDED run is owed one too
 * once its `resumeAt` has come: that is how a completed task or a concluded
 * decision wakes it (`FlowRunService::signal()` sets `resumeAt` to now and
 * leaves the rest to the worker). A run suspended with a `resumeAt` in the
 * future is parked on a person, which is an answer.
 *
 * @param run The run as `/api/flow-runs/{uuid}` serves it.
 */
function owedAPass(run: Json): boolean {
	const status = String(run.status ?? '')
	if (status === 'queued' || status === 'running') return true
	if (status !== 'suspended' || !run.resumeAt) return false
	return Date.parse(String(run.resumeAt)) <= Date.now()
}

/**
 * Walk one run until the worker owes it nothing: parked on a person, or done.
 *
 * One pass is what the journey describes and normally what it takes. The loop
 * exists because this spec is not the only thing that can hold the run: in
 * ajax cron mode any page load may start a FlowRunWorker pass, and a run that
 * pass has claimed reads `running` to ours. Asserting straight after one pass
 * would then fail on timing and name the wrong cause.
 *
 * @param api  The API view.
 * @param uuid The run to walk.
 */
async function advanceRun(api: APIRequestContext, uuid: string): Promise<void> {
	await workerPass()
	await expect
		.poll(
			async () => {
				const run = await getJson(api, `${OR}/flow-runs/${uuid}`)
				if (!owedAPass(run)) return 'settled'
				// A run another pass holds is left to that pass.
				if (String(run.status) !== 'running') await workerPass()
				return String(run.status)
			},
			{
				message: `The worker must settle run ${uuid} on a wait or an end.`,
				timeout: 90_000,
				intervals: [1_000, 2_000, 5_000],
			},
		)
		.toBe('settled')
}

/**
 * Where the run stands, in one line, for assertion messages. Reads the step
 * rows so a failure names the NODE that failed rather than "not suspended".
 */
async function describeRun(api: APIRequestContext, uuid: string): Promise<string> {
	const run = await getJson(api, `${OR}/flow-runs/${uuid}`)
	const log = (run.log ?? []) as Json[]
	const steps = log.map(
		(s) =>
			`${s.transition}:${s.status}${s.error ? `(${String(s.error).slice(0, 80)})` : ''}`,
	)
	return `run ${uuid} is ${run.status}${run.error ? ` (${run.error})` : ''}; steps: ${steps.join(' → ') || 'none'}`
}

async function shoot(page: Page, name: string): Promise<void> {
	fs.mkdirSync(SCREENS, { recursive: true })
	await page.screenshot({ path: path.join(SCREENS, name), fullPage: true })
}

/**
 * The cases list filtered to one exact title, via the deep-link filter
 * contract (non-underscore query keys become equality filters on the
 * fetch — CnIndexPage `resolveQueryFilters`).
 *
 * The unfiltered list sorts identifier-asc and pages at 20, so on any
 * rig carrying more than 20 cases a just-created case lands on the LAST
 * page and a bare `toContainText` against page one is pagination-blind:
 * it passes on an empty rig and fails on a lived-in one. Filtering pins
 * the assertion to the created case regardless of rig size.
 *
 * A hard load of `/cases` has been seen to land on the dashboard while
 * the SPA boots under load, and a sidebar fallback click would drop the
 * query — so the filtered deep link is retried until the router holds
 * the /cases route.
 */
async function openCasesListFilteredByTitle(
	page: Page,
	title: string,
): Promise<void> {
	const url = `/index.php/apps/dossiq/cases?title=${encodeURIComponent(title)}`
	for (let attempt = 0; attempt < 3; attempt++) {
		await page.goto(url, { ...PAGE_LOAD, waitUntil: 'domcontentloaded' })
		const nav = page
			.getByRole('link', { name: /^(All cases|Alle zaken)$/ })
			.first()
		await nav.waitFor({ state: 'visible', timeout: 20_000 })
		if (page.url().includes('/cases')) return
	}
	throw new Error('The SPA never settled on the filtered cases route.')
}

async function openCase(page: Page, caseId: string, title: string): Promise<void> {
	await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, {
		...PAGE_LOAD,
		waitUntil: 'domcontentloaded',
	})
	await expect(page.locator('body')).toContainText(title, { timeout: 20_000 })
}

test.describe('Case flow, live: the shipped flow walked on cases this spec files', () => {
	let api: APIRequestContext
	let caseType = ''
	let names = new Map<string, string>()
	/** The shipped flow's uuid, or empty when the register import did not store it. */
	let flowUuid = ''
	/**
	 * Whether an operator adopted the flow. When true its trigger starts the
	 * runs; when false this spec starts them by hand. See the header.
	 */
	let adopted = false
	/** Whether decidiq is enabled, which the three decision journeys need. */
	let decidiqInstalled = false
	/**
	 * CSRF request-token for the engine's verbs.
	 *
	 * The OpenRegister object writes in this spec need none — `OCS-APIRequest`
	 * on the context short-circuits the check — but the shared
	 * `invokeFlowTask` helper sends the standard write headers, and a spec
	 * that special-cased its way around them would be the fourth way this
	 * repo drives a task.
	 */
	let token = ''

	// Journey state, carried across the serial tests.
	let incompleteCase = ''
	let incompleteRun = ''
	let applicantTask = ''
	let completeCase = ''
	let completeRun = ''

	test.beforeAll(async () => {
		api = await apiContext()
		token = await getRequestToken(api)
		caseType = await caseTypeId(api)
		names = await statusNames(api, caseType)

		// NO SKIP HERE ANY MORE. It used to stand all nine tests down whenever
		// the flow was disabled, which is its correct state on every shared
		// instance, CI included; the header says how the journey runs without
		// enabling it. A MISSING flow is left for the first test to fail on,
		// naming it, because that means the register import did not run.
		const flow = await shippedFlow(api)
		flowUuid = String(flow?.uuid ?? '')
		adopted = flow !== null && flow.enabled === true
		decidiqInstalled = await appEnabled(api, 'decidiq')
	})

	test.afterAll(async () => {
		if (!api) return
		test.setTimeout(120_000)
		const leaks: string[] = []

		try {
			// The asks first. A task lives in the engine's own table and
			// outlives its case, so a journey that failed part-way would leave
			// one standing in somebody's inbox. `cancel` is the engine's only
			// removal verb; a task already completed answers it with a 409,
			// which is fine.
			for (const caseId of [incompleteCase, completeCase]) {
				if (caseId === '') continue
				const tasks = await tasksForCase(api, caseId).catch(() => [])
				for (const task of tasks) {
					await api
						.post(`${OR}/flow-tasks/${String(task.uuid)}/cancel`, {
							headers: { requesttoken: token },
							data: {},
						})
						.catch(() => undefined)
				}
			}

			// Then the cases. `case` is archival, so purgeObject falls through
			// to the occ purge. Their RUNS stay: see the header.
			for (const caseId of [incompleteCase, completeCase]) {
				if (caseId === '') continue
				if ((await purgeObject(api, token, 'case', caseId)) === false) {
					leaks.push(`case ${caseId}`)
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

	/**
	 * Start the case's run the way this instance starts one, and return it.
	 *
	 * Adopted: the case's creation already did, through the flow's
	 * `object.created` trigger, so this only finds that run. Not adopted: the
	 * spec asks for the run, with the case as its subject in the
	 * `{uuid, register, schema}` shape CaseStartFlowDialog posts. No `sync`:
	 * the run is QUEUED, as a trigger-started one is, so the first worker pass
	 * walks it from the trigger node in both cases.
	 *
	 * Either way the case must then carry EXACTLY ONE run, and it must be of
	 * the shipped flow. Where the spec started it, a second run would mean the
	 * disabled flow's trigger fired as well.
	 *
	 * @param caseId The case just filed.
	 * @return The run uuid.
	 */
	async function startRun(caseId: string): Promise<string> {
		if (!adopted) {
			const res = await api.post(`${OR}/flows/${flowUuid}/run`, {
				headers: { requesttoken: token },
				data: {
					subject: { uuid: caseId, register: 'dossiq', schema: 'case' },
				},
			})
			expect(
				res.status(),
				`Starting "${FLOW_NAME}" on case ${caseId} answered ${res.status()}: ${await res.text()}`,
			).toBe(201)
		}

		await expect
			.poll(async () => (await runsForCase(api, caseId)).length, {
				message: adopted
					? 'Creating a case must start its run (object.created trigger on dossiq/case).'
					: 'The case must carry exactly the run started on it.',
				timeout: 15_000,
			})
			.toBe(1)
		const [run] = await runsForCase(api, caseId)
		expect(
			String(run.flowId ?? ''),
			`The case's run must be of the shipped flow "${FLOW_NAME}".`,
		).toBe(flowUuid)
		return String(run.uuid)
	}

	test('Given: the shipped flow is in the flow store, and the browser session sees it as the API does', async ({
		page,
	}) => {
		const flow = await shippedFlow(api)
		expect(
			flow,
			`The flow "${FLOW_NAME}" is not in the flow store; the register import did not run.`,
		).toBeTruthy()

		// Only an ADOPTED flow is started by its trigger, and a trigger firing
		// with nobody present needs an owner to run as. A run asked for by hand
		// runs as the person who asked (FlowService::run), so a disabled,
		// ownerless flow is the correct shipped state and not a defect.
		if (adopted) {
			expect(
				String(flow!.owner ?? ''),
				'The flow is enabled but has no owner. openregister refuses to dispatch an '
					+ 'ownerless flow (Flow::canDispatch), so no case would ever start a run.',
			).not.toBe('')
		}

		// The same flow, read through the browser session a person has. The
		// two views must agree, or the editor shows an operator a flow that
		// is not the one running.
		const seen = await page.request.get(`${OR}/flows/${flow!.uuid}`)
		expect(
			seen.ok(),
			'The flow must be readable in the browser session.',
		).toBeTruthy()
		const inSession = (await seen.json()) as Json
		expect(
			inSession.enabled,
			'The browser session sees the flow as disabled while the API sees it enabled.',
		).toBe(flow!.enabled)
		expect(
			inSession.owner ?? null,
			'The browser session sees a different owner than the API.',
		).toBe(flow!.owner ?? null)
	})

	test('When an incomplete case is filed, exactly one run starts for it', async ({
		page,
	}) => {
		const created = await createCase(api, {
			title: `${RUN_PREFIX} Carport Molenweg 5`,
			caseType,
			intakeChannel: 'website',
			assignee: ADMIN_USER,
			// No description: the completeness check reads `description`.
		})
		incompleteCase = String(created.id)
		incompleteRun = await startRun(incompleteCase)

		await openCasesListFilteredByTitle(page, `${RUN_PREFIX} Carport Molenweg 5`)
		await expect(page.locator('body')).toContainText(
			`${RUN_PREFIX} Carport Molenweg 5`,
			{ timeout: 20_000 },
		)
		await shoot(page, '01-incomplete-case-filed.png')
	})

	test('Then one worker pass gives the handler a supplement task and the case says "Wacht op aanvulling"', async ({
		page,
	}) => {
		await advanceRun(api, incompleteRun)

		// What the applicant reads on the case, captured BEFORE the assertions so a
		// failing run still leaves the evidence of what a person saw.
		await openCase(page, incompleteCase, 'Carport Molenweg 5')
		await shoot(page, '02-applicant-waiting-case.png')

		const run = await getJson(api, `${OR}/flow-runs/${incompleteRun}`)
		expect(
			run.status,
			`After the worker pass the run must be suspended on the supplement ask. ${await describeRun(api, incompleteRun)}`,
		).toBe('suspended')

		const tasks = await tasksForCase(api, incompleteCase)
		expect(
			tasks,
			'The incomplete case must have exactly one supplement task.',
		).toHaveLength(1)
		const task = tasks[0]
		// The ENGINE's id is its uuid, and `/apps/dossiq/tasks/{id}` resolves
		// by uuid since dossiq#2411. A numeric primary key is one no route takes.
		applicantTask = String(task.uuid)
		expect(String(task.title)).toBe('Vraag de indiener om aanvulling')
		expect(String(task.runUuid ?? '')).toBe(incompleteRun)
		expect(String(task.nodeId ?? '')).toBe('ask-aanvulling')
		// The flow names `{{ case.assignee }}`; the task must carry the PERSON,
		// not the placeholder, or nobody is allowed to answer it.
		expect(String(task.assignee ?? '')).toBe(ADMIN_USER)

		const status = names.get(
			String(
				(await getJson(api, `${OR}/objects/dossiq/case/${incompleteCase}`))
					.status,
			),
		)
		expect(status).toBe('Wacht op aanvulling')
		await expect(page.locator(STATUS_BADGE)).toContainText('Wacht op aanvulling')

		await page.goto(`/index.php/apps/dossiq/tasks/${applicantTask}`, {
			...PAGE_LOAD,
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('body')).toContainText(
			'Vraag de indiener om aanvulling',
			{
				timeout: 20_000,
			},
		)
		// The task says WHICH case is waiting on it.
		await expect(page.locator('body')).toContainText('Carport Molenweg 5')
		await shoot(page, '03-applicant-task.png')
	})

	test('When the missing detail is supplied and the task completed, the case moves to "In behandeling"', async ({
		page,
	}) => {
		await updateObject(api, 'case', incompleteCase, {
			description: SUPPLIED_DESCRIPTION,
		})
		await completeTask(api, token, applicantTask)

		await advanceRun(api, incompleteRun)

		await openCase(page, incompleteCase, 'Carport Molenweg 5')
		await shoot(page, '04-case-after-applicant-answered.png')

		const status = names.get(
			String(
				(await getJson(api, `${OR}/objects/dossiq/case/${incompleteCase}`))
					.status,
			),
		)
		expect(
			status,
			`Completing the supplement task must resume the run at the step that asked and re-check completeness. ${await describeRun(api, incompleteRun)}`,
		).toBe('In behandeling')
		await expect(page.locator(STATUS_BADGE)).toContainText('In behandeling')

		// It is the SAME run that continues, on to the first decision.
		const run = await getJson(api, `${OR}/flow-runs/${incompleteRun}`)
		if (decidiqInstalled) {
			// It raised the decision and waits on it.
			expect(run.status, await describeRun(api, incompleteRun)).toBe(
				'suspended',
			)
			return
		}

		// Without decidiq the decision step FAILS CLOSED, which is its own
		// contract (DossiqRequestDecisionNode): the run stops there rather than
		// carrying on past a decision nobody made. Asserted rather than skipped,
		// because it still proves what this test is about, that the run the
		// answered ask woke is the one that walked on to the decision, and it
		// pins the fail-closed half on the one instance that can show it.
		expect(run.status, await describeRun(api, incompleteRun)).toBe('failed')
		const log = (run.log ?? []) as Json[]
		const stoppedAt = log.find(
			(s) =>
				String(s.transition) === 'decide-register-b'
				&& s.status === 'failed',
		)
		expect(
			String(stoppedAt?.error ?? ''),
			`The run must stop on the first decision, refused for want of decidiq. ${await describeRun(api, incompleteRun)}`,
		).toContain('decision_could_not_be_raised')
	})

	test('When a complete case is filed, it is not asked for anything and goes straight to handling', async ({
		page,
	}) => {
		const created = await createCase(api, {
			title: `${RUN_PREFIX} Dakkapel Kerkstraat 14`,
			description:
				'Compleet ingediend: bouwtekening, constructieberekening en situatieschets zijn bijgevoegd.',
			caseType,
			intakeChannel: 'website',
			assignee: ADMIN_USER,
		})
		completeCase = String(created.id)
		completeRun = await startRun(completeCase)

		await advanceRun(api, completeRun)

		await openCase(page, completeCase, 'Dakkapel Kerkstraat 14')
		await shoot(page, '05-complete-case-in-handling.png')

		const tasks = await tasksForCase(api, completeCase)
		expect(
			tasks.filter(
				(t) => String(t.title) === 'Vraag de indiener om aanvulling',
			),
			'A complete case must never be asked for more.',
		).toHaveLength(0)

		const status = names.get(
			String(
				(await getJson(api, `${OR}/objects/dossiq/case/${completeCase}`))
					.status,
			),
		)
		expect(
			status,
			`A complete case must pass the completeness check. ${await describeRun(api, completeRun)}`,
		).toBe('In behandeling')
		// Positive first: `not.toContainText` on a badge that failed to render
		// would pass, so the badge must be shown to hold the right status.
		await expect(page.locator(STATUS_BADGE)).toContainText('In behandeling')
		await expect(page.locator(STATUS_BADGE)).not.toContainText(
			'Wacht op aanvulling',
		)
	})

	test('Then two decisions are raised in decidiq, and concluding each moves the run on', async () => {
		test.skip(!decidiqInstalled, DECIDIQ_ABSENT)

		// decidiq keeps decisions as objects in its own register; a delegated
		// decision carries the case as externalReference.
		const decisions = await results(
			api,
			`${OR}/objects/decidiq/decision?_limit=50&externalReference=${completeCase}`,
		)
		expect(
			decisions.length,
			`The run must have raised a decision in decidiq for the case. ${await describeRun(api, completeRun)}`,
		).toBeGreaterThanOrEqual(1)

		for (const question of [
			'Toets de aanvraag aan register B',
			'Tweede inhoudelijke toets',
		]) {
			const open = (
				await results(
					api,
					`${OR}/objects/decidiq/decision?_limit=50&externalReference=${completeCase}`,
				)
			).filter(
				(d) =>
					!['decided', 'enacted', 'archived'].includes(
						String(d.lifecycle ?? d.status ?? ''),
					),
			)
			expect(
				open.length,
				`Expected an open decision for "${question}".`,
			).toBeGreaterThanOrEqual(1)
			const decision = open[0]

			// Record the outcome, then walk the lifecycle to `decided` through
			// decidiq's own transition endpoint: that is what emits the
			// DecisionConcludedEvent the run is waiting for.
			//
			// `text` is what the clerk types when recording the outcome, and it
			// is also load-bearing here for a reason worth naming: decidiq's
			// decision schema requires (title, text, decisionType), yet the
			// flow's delegate-decision step CREATES the decision without
			// `text` — the create path skips required-property validation that
			// every later PUT then enforces, so the stored object cannot be
			// updated at all until someone supplies it. Measured live on the
			// proof rig 2026-09-01 (decision 7f2dc8f4, schema 33).
			await updateObject(
				api,
				'decision',
				String(decision.id),
				{
					text: `Toets uitgevoerd: ${question}. Geen bezwaren.`,
					outcome: 'adopted',
					decisionDate: new Date().toISOString(),
				},
				'decidiq',
			)
			// `openVoting` is in the walk because it is the only schema-legal
			// route to `decided`: decidiq's PHP guard advertises deliberating →
			// decided (`allowDecideWithoutVote`, operations domain), but the
			// decision schema's x-openregister-lifecycle declares no such
			// edge — only voting → decided — so the guard-approved write is
			// then rejected by OpenRegister's lifecycle validation ("No
			// transition allows moving lifecycle from deliberating to
			// decided"). Measured live on the proof rig 2026-09-01.
			for (const action of ['propose', 'deliberate', 'openVoting', 'decide']) {
				const res = await api.post(
					`/index.php/apps/decidiq/api/decisions/${decision.id}/transition`,
					{ data: { action } },
				)
				expect([200, 422]).toContain(res.status())
			}
			const after = await getJson(
				api,
				`${OR}/objects/decidiq/decision/${decision.id}`,
			)
			expect(
				String(after.lifecycle ?? after.status ?? ''),
				`Decision ${decision.id} must reach "decided".`,
			).toBe('decided')

			await advanceRun(api, completeRun)
		}

		const run = await getJson(api, `${OR}/flow-runs/${completeRun}`)
		expect(run.status, await describeRun(api, completeRun)).toBe('suspended')
	})

	test('Then the employee gets a preparation task; completing it puts the case before the commission', async ({
		page,
	}) => {
		// The preparation task is the step AFTER both decisions, so without
		// decidiq the run never reaches it.
		test.skip(!decidiqInstalled, DECIDIQ_ABSENT)

		const tasks = (await tasksForCase(api, completeCase)).filter(
			(t) => String(t.title) === 'Rond de inhoudelijke voorbereiding af',
		)
		expect(
			tasks,
			`The employee task must exist after both decisions. ${await describeRun(api, completeRun)}`,
		).toHaveLength(1)
		expect(String(tasks[0].assignee)).toBe('behandelaars')

		await page.goto(`/index.php/apps/dossiq/tasks/${tasks[0].uuid}`, {
			...PAGE_LOAD,
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('body')).toContainText(
			'Rond de inhoudelijke voorbereiding af',
			{ timeout: 20_000 },
		)
		await shoot(page, '06-employee-task.png')

		// The admin is a member of `behandelaars`, so may complete it.
		await completeTask(api, token, String(tasks[0].uuid))
		await advanceRun(api, completeRun)

		await openCase(page, completeCase, 'Dakkapel Kerkstraat 14')
		await shoot(page, '07-case-before-commission.png')
		const status = names.get(
			String(
				(await getJson(api, `${OR}/objects/dossiq/case/${completeCase}`))
					.status,
			),
		)
		expect(status, await describeRun(api, completeRun)).toBe('Bij commissie')
	})

	test('When the commission approves, the decision document is attached and the case is closed', async ({
		page,
	}) => {
		// The commission's is the third decision; see DECIDIQ_ABSENT.
		test.skip(!decidiqInstalled, DECIDIQ_ABSENT)

		const open = (
			await results(
				api,
				`${OR}/objects/decidiq/decision?_limit=50&externalReference=${completeCase}`,
			)
		).filter(
			(d) =>
				!['decided', 'enacted', 'archived'].includes(
					String(d.lifecycle ?? d.status ?? ''),
				),
		)
		expect(
			open.length,
			`The commission decision must be open in decidiq. ${await describeRun(api, completeRun)}`,
		).toBeGreaterThanOrEqual(1)
		const decision = open[0]

		// `text` also fills the required property the flow's create step left
		// out — see the note on the first decision above.
		await updateObject(
			api,
			'decision',
			String(decision.id),
			{
				text: 'De commissie stemt in met het voorgenomen besluit.',
				outcome: 'adopted',
				decisionDate: new Date().toISOString(),
			},
			'decidiq',
		)
		// `openVoting` for the same reason as the first decision: the schema's
		// lifecycle map only reaches `decided` through `voting`.
		for (const action of ['propose', 'deliberate', 'openVoting', 'decide']) {
			await api.post(
				`/index.php/apps/decidiq/api/decisions/${decision.id}/transition`,
				{ data: { action } },
			)
		}
		await advanceRun(api, completeRun)

		await openCase(page, completeCase, 'Dakkapel Kerkstraat 14')
		await shoot(page, '08-closed-case-with-document.png')

		const closed = await getJson(
			api,
			`${OR}/objects/dossiq/case/${completeCase}`,
		)
		expect(
			String(closed.besluitDocument ?? ''),
			`The case must carry its decision document before it closes. ${await describeRun(api, completeRun)}`,
		).toContain('Besluit op de aanvraag')
		expect(names.get(String(closed.status))).toBe('Afgehandeld')
		await expect(page.locator(STATUS_BADGE)).toContainText('Afgehandeld')

		// `stopped` is the platform's word for a run that reached its end
		// node: `openregister.end` (EndNode) throws FlowStop when items reach
		// it, and FlowEngine maps FlowStop to STATUS_STOPPED. STATUS_COMPLETED
		// only marks a run whose marking drained without any stop node, which
		// this flow, ending deliberately at `end`, never does. Asserting
		// `completed` here contradicted that contract and failed a run that
		// had in fact closed the case correctly.
		const run = await getJson(api, `${OR}/flow-runs/${completeRun}`)
		expect(run.status, await describeRun(api, completeRun)).toBe('stopped')
	})

	test('And the run reports the objects it touched, grouped by node (7.3)', async () => {
		const uuid = incompleteRun || completeRun
		expect(
			uuid,
			'No run to read; the journeys above did not start one.',
		).not.toBe('')

		const touched = await getJson(api, `${OR}/flow-runs/${uuid}/objects`)
		expect(touched).toHaveProperty('run', uuid)
		const nodes = (touched.nodes ?? []) as Json[]
		expect(
			nodes.length,
			'A run that moved a status must report at least the node that moved it.',
		).toBeGreaterThan(0)

		// The status step names the CASE it updated, so the case's history can
		// say which node moved each status.
		const statusNode = nodes.find((n) => String(n.node) === 'status-ontvangen')
		expect(
			statusNode,
			'The first status step must appear in the traceability read.',
		).toBeTruthy()
		const updates = ((statusNode!.objects ?? []) as Json[]).filter(
			(o) => String(o.action) === 'update',
		)
		expect(updates.map((o) => String(o.objectUuid))).toContain(
			incompleteRun ? incompleteCase : completeCase,
		)
	})
})
