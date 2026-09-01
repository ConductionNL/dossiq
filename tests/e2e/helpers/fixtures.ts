/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Seeded-fixture helper for the DEEP, data-dependent dossiq e2e layer.
 *
 * Cases (zaken), caseTypes, statusTypes, statusRecords and complaints are
 * all OpenRegister objects in the `dossiq` register (the manifest pages
 * `Cases`/`CaseDetail` declare `register: "dossiq", schema: "case"`, and
 * the front-end uses the shared `createObjectStore('object')`). This helper
 * creates and tears down those objects directly through the OpenRegister
 * object CRUD API so the UI-driving specs start from known data:
 *
 *   GET    /apps/openregister/api/objects/dossiq/{schema}
 *   POST   /apps/openregister/api/objects/dossiq/{schema}
 *   GET    /apps/openregister/api/objects/dossiq/{schema}/{id}
 *   PUT    /apps/openregister/api/objects/dossiq/{schema}/{id}
 *   DELETE /apps/openregister/api/objects/dossiq/{schema}/{id}
 *
 * Playwright = UI only for assertions: this helper is *fixture setup/teardown*
 * (allowed — the prompt and ADR permit API/occ for seeding). The behavioural
 * assertions all happen against the rendered DOM in the spec files.
 *
 * Every object created here carries a unique run prefix in a human-visible
 * field (case.title, complaint.subject, caseType.name) so list assertions
 * can find exactly the seeded row, and afterAll cleanup can find + delete
 * every object this run produced.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect } from '@playwright/test'

/** OpenRegister register slug that owns every dossiq object. */
export const REGISTER = 'dossiq'

/**
 * Unique-per-process prefix. Every seeded object embeds this in a visible
 * field so list/detail assertions and afterAll cleanup can target exactly
 * the rows this run created (never another run's or real demo data).
 */
export const RUN_PREFIX = `E2EZAAK-${Date.now().toString(36)}-${Math.floor(Math.random() * 1e4)}`

const API_BASE = '/index.php/apps/openregister/api/objects'

/**
 * Read a CSRF request-token from a freshly-loaded dossiq page. The
 * OpenRegister write endpoints (POST/PUT/DELETE) are CSRF-protected, so
 * mutating calls must carry a `requesttoken` header. GET is not protected.
 * @param api  The authenticated request context (storageState).
 */
export async function getRequestToken(api: APIRequestContext): Promise<string> {
	const res = await api.get('/index.php/apps/dossiq/dashboard')
	const html = await res.text()
	const m = html.match(/data-requesttoken="([^"]+)"/)
	if (!m) {
		throw new Error('Could not read requesttoken from /apps/dossiq/dashboard')
	}
	return m[1]
}

/**
 * Standard headers for a CSRF-protected write call.
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
 * Pull the object array out of OpenRegister's list/response envelopes.
 * @param body The parsed response body.
 */
function unwrapList(body: any): any[] {
	if (Array.isArray(body)) return body
	if (Array.isArray(body?.results)) return body.results
	if (Array.isArray(body?.data)) return body.data
	return []
}

/**
 * Pull a single object out of a create/show envelope.
 * @param body The parsed response body.
 */
function unwrapObject(body: any): any {
	if (body && typeof body === 'object' && (body.id || body['@self'] || body.uuid))
		return body
	if (body?.results && !Array.isArray(body.results)) return body.results
	if (body?.object) return body.object
	return body
}

/**
 * The OpenRegister id of an object (uuid preferred, numeric id fallback).
 * @param obj The object whose id to read.
 */
export function objectId(obj: any): string {
	return String(obj?.['@self']?.id ?? obj?.uuid ?? obj?.id ?? '')
}

/**
 * Create one object of `schema` in the dossiq register.
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param schema Schema slug (e.g. "case", "caseType", "statusType").
 * @param data   Object body.
 */
export async function createObject(
	api: APIRequestContext,
	token: string,
	schema: string,
	data: Record<string, unknown>,
): Promise<any> {
	const res = await api.post(`${API_BASE}/${REGISTER}/${schema}`, {
		headers: writeHeaders(token),
		data,
	})
	expect(
		res.ok(),
		`create ${schema} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	return unwrapObject(await res.json())
}

/**
 * List objects of `schema`, optionally filtered. Filters are passed as
 * query params (OpenRegister treats unknown params as field filters).
 * @param api    Authenticated request context.
 * @param schema Schema slug.
 * @param params Extra query params (filters / _limit).
 */
export async function listObjects(
	api: APIRequestContext,
	schema: string,
	params: Record<string, string> = {},
): Promise<any[]> {
	const qs = new URLSearchParams({ _limit: '200', ...params }).toString()
	const res = await api.get(`${API_BASE}/${REGISTER}/${schema}?${qs}`)
	expect(res.ok(), `list ${schema} -> ${res.status()}`).toBeTruthy()
	return unwrapList(await res.json())
}

/**
 * Fetch a single object by id.
 * @param api    Authenticated request context.
 * @param schema Schema slug.
 * @param id     Object id/uuid.
 */
export async function showObject(
	api: APIRequestContext,
	schema: string,
	id: string,
): Promise<any> {
	const res = await api.get(`${API_BASE}/${REGISTER}/${schema}/${id}`)
	expect(res.ok(), `show ${schema}/${id} -> ${res.status()}`).toBeTruthy()
	return unwrapObject(await res.json())
}

/**
 * Delete a single object by id (idempotent — a 404 is tolerated so cleanup
 * never fails a suite when an earlier step already removed the row).
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param schema Schema slug.
 * @param id     Object id/uuid.
 */
export async function deleteObject(
	api: APIRequestContext,
	token: string,
	schema: string,
	id: string,
): Promise<void> {
	if (!id) return
	await api.delete(`${API_BASE}/${REGISTER}/${schema}/${id}`, {
		headers: writeHeaders(token),
	})
}

/**
 * Attempt a delete and RETURN the outcome (status + parsed body) instead of
 * swallowing it. Used to assert a rejection — e.g. an archival schema
 * (x-openregister-archival) returns 403 ArchivalImmutableException on a
 * user-driven delete.
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param schema Schema slug.
 * @param id     Object id/uuid.
 * @return `{ status, body }` of the DELETE response.
 */
export async function tryDeleteObject(
	api: APIRequestContext,
	token: string,
	schema: string,
	id: string,
): Promise<{ status: number; body: unknown }> {
	const res = await api.delete(`${API_BASE}/${REGISTER}/${schema}/${id}`, {
		headers: writeHeaders(token),
	})
	return { status: res.status(), body: await res.json().catch(() => ({})) }
}

/**
 * Discover an existing caseType to attach seeded cases to. The `case` schema
 * requires `caseType`; a real caseType (with its statusTypes) is needed for
 * the transition engine. If none exists we seed a throwaway one tagged with
 * RUN_PREFIX so cleanup removes it.
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 */
export async function ensureCaseType(
	api: APIRequestContext,
	token: string,
): Promise<{ id: string; name: string; seeded: boolean }> {
	const existing = await listObjects(api, 'caseType')
	if (existing.length > 0) {
		const ct = existing[0]
		return {
			id: objectId(ct),
			name: String(ct.title ?? ct.name ?? 'caseType'),
			seeded: false,
		}
	}
	// Live caseType schema requires `title` (+ identifier), not `name`.
	const name = `${RUN_PREFIX} CaseType`
	const ct = await createObject(api, token, 'caseType', {
		title: name,
		identifier: `${RUN_PREFIX.toLowerCase()}-casetype`,
		description: 'Throwaway caseType seeded by the dossiq deep e2e layer.',
	})
	return { id: objectId(ct), name, seeded: true }
}

/**
 * Seed a case with the given title and fields. Returns the created object.
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param fields Case fields (must satisfy required title + caseType).
 */
export async function seedCase(
	api: APIRequestContext,
	token: string,
	fields: Record<string, unknown> & { title: string; caseType: string },
): Promise<any> {
	return createObject(api, token, 'case', {
		identifier: `${RUN_PREFIX}-${Math.floor(Math.random() * 1e4)}`,
		priority: 'normal',
		intakeChannel: 'manual',
		...fields,
	})
}

/**
 * Live schema field map (the deployed schemas differ from the stale
 * lib/Settings/dossiq_register.json — caseType uses `title`+`identifier`,
 * statusType uses `name`+`caseType`+`order`+`isFinal`, workflowTemplate uses
 * `title`+`caseType`+`isActive`+`transitions` (a JSON string)).
 */

/** A seeded state machine: a caseType, three statusTypes, an active template. */
export interface StateMachine {
	caseTypeId: string
	statusReceived: string
	statusInProgress: string
	statusDone: string
	/** ids of every object created, child-first, for ordered cleanup. */
	created: Array<[string, string]>
}

/**
 * Seed a complete, guarded state machine for one throwaway caseType:
 *
 *   Ontvangen (order 1)  --t1: Start behandeling-->  In behandeling (order 2)
 *   In behandeling       --t2: Afhandelen (guard: requiredField `result`)-->
 *                                                     Afgehandeld (final, order 3)
 *
 * The closing transition carries a `requiredField` guard on `description`
 * (a free-string field — `result` is a uuid-format reference and cannot hold
 * an arbitrary value), so a transition attempt while `description` is empty is
 * blocked by the engine (409) — which is what the guard-enforcement assertion
 * checks. Setting `description` then lets the same transition pass.
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 */
export async function seedStateMachine(
	api: APIRequestContext,
	token: string,
): Promise<StateMachine> {
	const created: Array<[string, string]> = []
	const add = (schema: string, obj: any): string => {
		const id = objectId(obj)
		created.push([schema, id])
		return id
	}

	const caseType = await createObject(api, token, 'caseType', {
		title: `${RUN_PREFIX} Vergunning`,
		identifier: `${RUN_PREFIX.toLowerCase()}-verg`,
		description: 'Throwaway caseType for the dossiq state-machine e2e layer.',
	})
	const caseTypeId = add('caseType', caseType)

	const r = await createObject(api, token, 'statusType', {
		name: `${RUN_PREFIX} Ontvangen`,
		caseType: caseTypeId,
		order: 1,
		isFinal: false,
	})
	const p = await createObject(api, token, 'statusType', {
		name: `${RUN_PREFIX} In behandeling`,
		caseType: caseTypeId,
		order: 2,
		isFinal: false,
	})
	const d = await createObject(api, token, 'statusType', {
		name: `${RUN_PREFIX} Afgehandeld`,
		caseType: caseTypeId,
		order: 3,
		isFinal: true,
	})
	const statusReceived = add('statusType', r)
	const statusInProgress = add('statusType', p)
	const statusDone = add('statusType', d)

	const transitions = [
		{
			id: 't1',
			label: 'Start behandeling',
			fromStatus: statusReceived,
			toStatus: statusInProgress,
			guards: [],
		},
		{
			id: 't2',
			label: 'Afhandelen',
			fromStatus: statusInProgress,
			toStatus: statusDone,
			guards: [{ type: 'requiredField', field: 'description' }],
		},
	]
	const wf = await createObject(api, token, 'workflowTemplate', {
		title: `${RUN_PREFIX} Workflow`,
		caseType: caseTypeId,
		isActive: true,
		isDraft: false,
		version: 1,
		transitions: JSON.stringify(transitions),
	})
	add('workflowTemplate', wf)

	return { caseTypeId, statusReceived, statusInProgress, statusDone, created }
}

const DOSSIQ_API = '/index.php/apps/dossiq/api'

/**
 * GET the engine's available transitions for a case.
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param caseId The case id/uuid.
 */
export async function getAvailableTransitions(
	api: APIRequestContext,
	token: string,
	caseId: string,
): Promise<any> {
	const res = await api.get(`${DOSSIQ_API}/case/${caseId}/available-transitions`, {
		headers: writeHeaders(token),
	})
	return { status: res.status(), body: await res.json().catch(() => ({})) }
}

/**
 * POST a guarded transition. Returns {status, body} — caller asserts.
 * @param api          Authenticated request context.
 * @param token        CSRF request-token.
 * @param caseId       The case id/uuid.
 * @param transitionId The transition id from the active template.
 * @param comment      Optional transition comment.
 */
export async function executeTransition(
	api: APIRequestContext,
	token: string,
	caseId: string,
	transitionId: string,
	comment?: string,
): Promise<any> {
	const res = await api.post(`${DOSSIQ_API}/case/${caseId}/transition`, {
		headers: writeHeaders(token),
		data: comment !== undefined ? { transitionId, comment } : { transitionId },
	})
	return { status: res.status(), body: await res.json().catch(() => ({})) }
}

/**
 * GET the replayed transition history of a case.
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param caseId The case id/uuid.
 */
export async function getTransitionHistory(
	api: APIRequestContext,
	token: string,
	caseId: string,
): Promise<any> {
	const res = await api.get(`${DOSSIQ_API}/case/${caseId}/transition-history`, {
		headers: writeHeaders(token),
	})
	return { status: res.status(), body: await res.json().catch(() => ({})) }
}

/**
 * PUT a partial update onto an existing object (merges over the full body).
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param schema Schema slug.
 * @param id     Object id/uuid.
 * @param patch  Fields to merge over the current object body.
 */
export async function updateObject(
	api: APIRequestContext,
	token: string,
	schema: string,
	id: string,
	patch: Record<string, unknown>,
): Promise<any> {
	const current = await showObject(api, schema, id)
	const res = await api.put(`${API_BASE}/${REGISTER}/${schema}/${id}`, {
		headers: writeHeaders(token),
		data: { ...current, ...patch },
	})
	expect(
		res.ok(),
		`update ${schema}/${id} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	return unwrapObject(await res.json())
}

/**
 * Find every object of `schema` whose stringified body contains RUN_PREFIX
 * and delete it. Used by afterAll to guarantee no seeded data is left behind.
 * @param api     Authenticated request context.
 * @param token   CSRF request-token.
 * @param schemas Schema slugs to sweep (order matters: children before parents).
 */
export async function cleanupRunObjects(
	api: APIRequestContext,
	token: string,
	schemas: string[],
): Promise<void> {
	for (const schema of schemas) {
		let rows: any[]
		try {
			rows = await listObjects(api, schema)
		} catch {
			continue
		}
		for (const row of rows) {
			if (JSON.stringify(row).includes(RUN_PREFIX)) {
				await deleteObject(api, token, schema, objectId(row))
			}
		}
	}
}
