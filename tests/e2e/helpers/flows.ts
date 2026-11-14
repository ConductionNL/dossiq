/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Throwaway flows, for specs that need a flow RUN to exist.
 *
 * CI never holds a run of its own accord: the flow dossiq ships arrives
 * DISABLED by spec (case-flow-human-steps 2.2). A spec that went looking for
 * "some run on the instance" therefore skipped on every CI run, and a skip
 * that fires every time is a test that has never run. These helpers let a
 * spec author a flow it owns, run it, and take it away again.
 *
 * NAME THE FLOW WITH RUN_PREFIX. case-actions-menu.spec.ts picks a flow off
 * the instance's list and passes over every name carrying FIXTURE_PREFIX, so
 * the flow it opens is never one another worker's teardown is about to
 * delete. `createFlow` refuses a name without it rather than trusting each
 * caller to remember.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect } from '@playwright/test'
import { FIXTURE_PREFIX } from './fixtures.ts'
import { occFlowWorkerPass } from './occ.ts'

/** OpenRegister's API root, which owns flows and their runs. */
export const OR_API = '/index.php/apps/openregister/api'

/** A flow graph, as `POST /api/flows` takes it. */
export interface FlowGraph {
	nodes: Array<Record<string, unknown>>
	edges: Array<Record<string, unknown>>
}

/** The object a run is about, as OpenRegister's FlowRunRow stores it. */
export interface FlowSubject {
	uuid: string
	register: string
	schema: string
}

/**
 * The headers a CSRF-protected OpenRegister write needs. The flow routes are
 * plain app routes, so the request token is what lets a POST or DELETE in.
 *
 * @param token CSRF request-token.
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
 *
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 * @param path  The route under OR_API.
 * @param data  The request body.
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

/**
 * Author the smallest flow the engine accepts and answer its uuid: a manual
 * start into an end. A node with no way out is refused at publish and at run
 * time (FlowDeadEnd), so the end is not optional.
 *
 * Off, and it can stay off. `enabled` only decides whether the flow's
 * TRIGGERS are subscribed; a run asked for by hand does not consult it, so
 * this flow can never start on its own. That is why a spec authors one rather
 * than enabling the shipped flow for the occasion: enabling that one would
 * start it on every case any other worker creates while the spec runs.
 *
 * Answers the uuid BEFORE anything else can fail, so the caller can record it
 * for teardown ahead of the publish.
 *
 * @param api         Authenticated request context.
 * @param token       CSRF request-token.
 * @param name        The flow's name. Must carry RUN_PREFIX, see the header.
 * @param description Which spec made it, for whoever finds it left behind.
 * @param graph       A graph of the caller's own, when the manual start into an
 *                    end is not the shape it needs. The RUN_PREFIX check, the
 *                    `enabled: false` and the uuid assertion are the reason to
 *                    come through here rather than posting `/flows` directly.
 */
export async function createFlow(
	api: APIRequestContext,
	token: string,
	name: string,
	description: string,
	graph?: FlowGraph,
): Promise<string> {
	expect(
		name,
		'A seeded flow must carry RUN_PREFIX, or another spec can pick it up.',
	).toContain(FIXTURE_PREFIX)

	const flow = await orPost(api, token, '/flows', {
		name,
		description,
		app: 'dossiq',
		enabled: false,
		nodes: graph?.nodes ?? [
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
		edges: graph?.edges ?? [{ id: 'start-end', from: 'start', to: 'end' }],
	})
	const flowId = String(flow.uuid ?? '')
	expect(flowId, 'The created flow must carry a uuid.').not.toBe('')
	return flowId
}

/**
 * Publish a flow and answer the version it made.
 *
 * The run endpoint would walk a draft, but a draft test run is recorded with
 * no version, and a run without one has no graph for the flow page to pin.
 * Publishing costs one request and makes every later run an ordinary pinned
 * one.
 *
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param flowId The flow's uuid.
 */
export async function publishFlow(
	api: APIRequestContext,
	token: string,
	flowId: string,
): Promise<number> {
	const published = await orPost(api, token, `/flows/${flowId}/publish`)
	const version = Number(published.version ?? 0)
	expect(
		version,
		'Publishing must answer with the version it made.',
	).toBeGreaterThan(0)
	return version
}

/**
 * Run a flow once and answer the run it made.
 *
 * `sync: true` finishes the run inside the request, so it is in the history
 * before the caller reads it and no background worker can move it mid-test.
 * The subject is optional because OpenRegister accepts a run without one
 * (FlowRunAdvancer seeds a subjectless run from a bare holder). Where there is
 * one it is `{uuid, register, schema}`, the three keys FlowRunRow stores, and
 * exactly what the case's "Start a sub-process" dialog posts
 * (src/dialogs/CaseStartFlowDialog.vue).
 *
 * @param api     Authenticated request context.
 * @param token   CSRF request-token.
 * @param flowId  The flow's uuid.
 * @param subject The object the run is about, if any.
 */
export async function runFlow(
	api: APIRequestContext,
	token: string,
	flowId: string,
	subject?: FlowSubject,
): Promise<Record<string, unknown>> {
	return await orPost(
		api,
		token,
		`/flows/${flowId}/run`,
		subject === undefined ? { sync: true } : { subject, sync: true },
	)
}

/**
 * Read one run back, as `GET /api/flow-runs/{uuid}` serves it.
 *
 * @param api Authenticated request context.
 * @param run The run's uuid.
 */
export async function readFlowRun(
	api: APIRequestContext,
	run: string,
): Promise<Record<string, unknown>> {
	const response = await api.get(`${OR_API}/flow-runs/${run}`)
	expect(
		response.ok(),
		`read run ${run} -> ${response.status()} ${await response.text()}`,
	).toBeTruthy()
	const body = (await response.json()) as Record<string, unknown>
	return (body.results ?? body) as Record<string, unknown>
}

/**
 * Whether the worker still owes this run a pass.
 *
 * Queued and running are the obvious two. A SUSPENDED run is owed one too once
 * its `resumeAt` has come: that is how a completed task wakes it —
 * `FlowRunService::signal()` sets `resumeAt` to now and leaves the rest to the
 * worker. A run suspended with a `resumeAt` in the FUTURE is parked on a
 * person, which is an answer rather than a state to drive out of.
 *
 * @param run The run, as `readFlowRun` answers it.
 */
export function owedAPass(run: Record<string, unknown>): boolean {
	const status = String(run.status ?? '')
	if (status === 'queued' || status === 'running') {
		return true
	}

	if (status !== 'suspended' || !run.resumeAt) {
		return false
	}

	return Date.parse(String(run.resumeAt)) <= Date.now()
}

/**
 * Walk a run until the worker owes it nothing: parked on a person, or done.
 *
 * 🔴 A RUN DOES NOT MOVE ON ITS OWN HERE. OpenRegister's `FlowRunWorker` is a
 * cron job, and CI serves Nextcloud with `php -S` and no cron at all, so a
 * spec that merely waited would time out on a run that was never going to be
 * touched. Each pass is performed the way an operator would, through
 * `occ background-job:execute` (see `helpers/occ.ts`).
 *
 * The pass's exit code is checked but is NOT the evidence: Nextcloud logs a
 * background job that throws and still exits 0. The run is read back after
 * every pass and the loop stops on what it says.
 *
 * @param api    Authenticated request context.
 * @param run    The run's uuid.
 * @param passes The most passes to spend. A linear flow needs one per wait.
 * @return The run as the last pass left it.
 */
export async function advanceFlowRun(
	api: APIRequestContext,
	run: string,
	passes = 4,
): Promise<Record<string, unknown>> {
	let current = await readFlowRun(api, run)

	for (let pass = 0; pass < passes && owedAPass(current); pass++) {
		const worked = await occFlowWorkerPass()
		expect(
			worked.code,
			`The flow worker pass exited ${worked.code}: ${worked.output.trim().slice(0, 400)}`,
		).toBe(0)
		current = await readFlowRun(api, run)
	}

	return current
}

/**
 * Remove a seeded flow with its runs, and answer whatever is still there.
 *
 * The flow is the only handle on its runs. OpenRegister publishes no delete
 * for a run: DELETE /api/flows/{id} is the only route that removes one, by
 * sweeping the flow's runs, steps, state and versions (FlowService::delete).
 * That sweep swallows its own failure and still answers 200, so the flow and
 * each run are read back rather than the status trusted.
 *
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param flowId The flow's uuid.
 * @param runs   The uuids of the runs it made, to read back.
 * @return One label per row that survived; empty on a clean removal.
 */
export async function removeFlow(
	api: APIRequestContext,
	token: string,
	flowId: string,
	runs: string[],
): Promise<string[]> {
	const leaks: string[] = []

	await api
		.delete(`${OR_API}/flows/${flowId}`, { headers: writeHeaders(token) })
		.catch(() => undefined)

	const flowLeft = await api.get(`${OR_API}/flows/${flowId}`).catch(() => null)
	if (flowLeft === null || flowLeft.status() !== 404) {
		leaks.push(`flow ${flowId}`)
	}

	for (const run of runs) {
		if (run === '') continue
		const runLeft = await api.get(`${OR_API}/flow-runs/${run}`).catch(() => null)
		if (runLeft === null || runLeft.status() !== 404) {
			leaks.push(`flow run ${run}`)
		}
	}

	return leaks
}
