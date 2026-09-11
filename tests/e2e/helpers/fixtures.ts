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

import { expect, test } from '@playwright/test'
import { occPurge, OccUnavailableError } from './occ.ts'
import { isStaleResidue, residueMinAgeMs, sweepsAllResidue } from './residue.ts'

/** OpenRegister register slug that owns every dossiq object. */
export const REGISTER = 'dossiq'

/**
 * The family prefix every fixture run shares. `RUN_PREFIX` extends it with a
 * per-process suffix, so `RUN_PREFIX` finds exactly this run's objects and
 * `FIXTURE_PREFIX` finds every run's — including the residue of a run that
 * crashed before its teardown fired. `sweepFixtureResidue` uses the family
 * form; per-spec teardown uses the run form.
 */
export const FIXTURE_PREFIX = 'E2EZAAK-'

/**
 * Unique-per-process prefix. Every seeded object embeds this in a visible
 * field so list/detail assertions and afterAll cleanup can target exactly
 * the rows this run created (never another run's or real demo data).
 */
export const RUN_PREFIX = `${FIXTURE_PREFIX}${Date.now().toString(36)}-${Math.floor(Math.random() * 1e4)}`

const API_BASE = '/index.php/apps/openregister/api/objects'

/**
 * OpenRegister's trash endpoint. `DELETE /api/deleted/{uuid}` destroys a row
 * that is genuinely in the trash and is NOT on an archival schema. It refuses
 * anything else: `400` for a live object, `403 SCHEMA_ARCHIVAL_IMMUTABLE` for an
 * archival record whether live or trashed. Archival rows go through
 * `helpers/occ.ts#occPurge` instead — see `purgeObject`.
 */
const TRASH_BASE = '/index.php/apps/openregister/api/deleted'

/**
 * Every schema the dossiq e2e fixtures create, CHILD-FIRST.
 *
 * Order is the cleanup order: rows that reference a case come before `case`,
 * and `case` comes before the caseType/statusType/workflowTemplate it points
 * at. Deleting a parent first is what left the dangling references that
 * reddened `spec-coverage/ui-pages.spec.ts` on a second run.
 */
export const FIXTURE_SCHEMAS = [
	'statusRecord',
	'caseProperty',
	// 🔴 `caseTask` IS GONE FROM THIS LIST, and it was here as cleanup order
	// rather than as a thing most specs made. remove-casetask deleted the
	// schema and `demo-caseload`, the last spec that created one, now seeds the
	// engine instead, so nothing this suite writes lands there. A name kept
	// here would cost a listing round trip per sweep against a schema that
	// answers nothing: `sweepPrefix` swallows a failed list, so it would be
	// silent as well as useless.
	//
	// One consequence is stated rather than discovered: on an instance
	// UPGRADED from a version that had the schema, rows earlier runs left
	// behind are no longer swept, because the import does not delete a schema
	// it stops declaring. They are orphan rows under an orphan schema and
	// removing them is an administrative act, not a test fixture's job.
	'contactmoment',
	// The things a case is about. Before `case` for the same reason every
	// other child is: `case` is on a CASCADE, so a case removed first takes
	// its objects with it and the sweep then reports rows it cannot find.
	'caseObject',
	// The dossier, child-first: the join names both the case and the document,
	// and the document names its type.
	'zaakinformatieobject',
	'informatieobject',
	'informatieobjecttype',
	'consultation',
	'objectionProceeding',
	// A role points at a case AND at a role type, so it goes before both.
	'role',
	'case',
	'roleType',
	// The team a case names. After `case` for the same reason `caseType` is:
	// it references it.
	'organisatieRol',
	'workflowTemplate',
	'statusType',
	'resultType',
	'caseType',
	'propertyDefinition',
] as const

/**
 * Read a CSRF request-token from a freshly-loaded dossiq page. The
 * OpenRegister write endpoints (POST/PUT/DELETE) are CSRF-protected, so
 * mutating calls must carry a `requesttoken` header. GET is not protected.
 *
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
 * Pull the object array out of OpenRegister's list/response envelopes.
 *
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
 *
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
 *
 * @param obj The object whose id to read.
 */
export function objectId(obj: any): string {
	return String(obj?.['@self']?.id ?? obj?.uuid ?? obj?.id ?? '')
}

/**
 * Create one object of `schema` in the dossiq register.
 *
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
 *
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
 *
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
 *
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
 * List EVERY object of `schema`, following the pagination cursor.
 *
 * `listObjects` caps at `_limit=200`. On a demo-sized instance the case table
 * runs past that, and a teardown that only ever saw the first page reported
 * success while leaving the rest behind. This walks `_page` until the server
 * stops handing back rows.
 *
 * @param api    Authenticated request context.
 * @param schema Schema slug.
 */
export async function listAllObjects(
	api: APIRequestContext,
	schema: string,
): Promise<any[]> {
	const all: any[] = []
	const limit = 200
	for (let page = 1; page <= 100; page++) {
		const qs = new URLSearchParams({
			_limit: String(limit),
			_page: String(page),
		}).toString()
		const res = await api.get(`${API_BASE}/${REGISTER}/${schema}?${qs}`)
		if (res.ok() === false) break
		const rows = unwrapList(await res.json())
		all.push(...rows)
		if (rows.length < limit) break
	}
	return all
}

/**
 * Remove an object PERMANENTLY, whatever its schema declares, and report
 * whether it actually went.
 *
 * Two things make a plain DELETE insufficient, and both were measured on a
 * persistent rig rather than reasoned about:
 *
 *  1. `case` is an ARCHIVAL schema (`x-openregister-archival`). A user-driven
 *     `DELETE /api/objects/dossiq/case/{id}` is refused with
 *     `403 SCHEMA_ARCHIVAL_IMMUTABLE`, and `deleteObject` never inspected the
 *     response — so the old teardown reported success and removed NOTHING.
 *     After 11 runs one rig held 68 cases, 33 of them fixture leftovers.
 *  2. For the schemas that DO accept a delete, the delete is SOFT. The row
 *     leaves the object API but stays in the trash, and anything still holding
 *     its uuid gets a 404 on lookup. Six soft-deleted statusTypes plus ten
 *     leftover cases pointing at them is exactly what reddened
 *     `spec-coverage/ui-pages.spec.ts:55` ("dashboard mounts without console
 *     errors") on a second full run.
 *
 * The HTTP pair handles case 2: the object delete soft-deletes the row and the
 * trash delete then destroys it. That reaches every NON-archival schema here.
 *
 * Case 1 has no HTTP answer at all, and that is the contract rather than a gap.
 * OpenRegister refuses an archival record on every delete route it serves —
 * `403 SCHEMA_ARCHIVAL_IMMUTABLE` from the object API, and the same from the
 * trash endpoint whether the row is live or trashed. Destroying a legally
 * retained record is an administrative act, so the only sanctioned way is a
 * command that needs shell access to the server:
 *
 *     occ openregister:objects:purge <uuid> --force --apply
 *
 * `--force` is what says out loud that an archival record is being destroyed.
 * `helpers/occ.ts` works out how to reach `occ` on this rig and fails loudly if
 * it cannot, because a teardown that cannot remove a case does not leave one
 * survivor — it poisons every later run on the instance.
 *
 * The CLI is used ONLY when the HTTP pair did not manage it, so an ordinary
 * fixture row still costs no process spawn. NO status is trusted anywhere here,
 * exit code included: the return value comes from re-reading the object.
 *
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param schema Schema slug.
 * @param id     Object id/uuid.
 * @return `true` when the object no longer resolves.
 * @throws OccUnavailableError When the row needs the CLI purge and occ cannot be reached.
 */
export async function purgeObject(
	api: APIRequestContext,
	token: string,
	schema: string,
	id: string,
): Promise<boolean> {
	if (!id) return true

	/**
	 * Whether the object still answers. An unreadable answer counts as "still
	 * there": a teardown may only report a clean sweep it actually observed.
	 */
	const stillResolves = async (): Promise<boolean> => {
		for (let attempt = 0; attempt < 2; attempt++) {
			const check = await api
				.get(`${API_BASE}/${REGISTER}/${schema}/${id}`)
				.catch(() => null)
			if (check !== null) return check.status() !== 404
		}
		return true
	}

	await api
		.delete(`${API_BASE}/${REGISTER}/${schema}/${id}`, {
			headers: writeHeaders(token),
		})
		.catch(() => undefined)
	await api
		.delete(`${TRASH_BASE}/${id}`, { headers: writeHeaders(token) })
		.catch(() => undefined)

	if ((await stillResolves()) === false) return true

	await occPurge([id])

	return (await stillResolves()) === false
}

/**
 * Attempt a delete and RETURN the outcome (status + parsed body) instead of
 * swallowing it. Used to assert a rejection — e.g. an archival schema
 * (x-openregister-archival) returns 403 ArchivalImmutableException on a
 * user-driven delete.
 *
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
 * Monotonic counter making every seeded identifier unique WITHIN a worker.
 *
 * `RUN_PREFIX` is already unique per PROCESS, and a Playwright worker is a
 * process, so it separates workers on its own. What it does not separate is two
 * calls in the SAME worker: a `caseType.identifier` derived from `RUN_PREFIX`
 * alone is the same string on the second call, and the second create then
 * collides on a field the schema expects to be distinct.
 */
let fixtureSeq = 0

/**
 * A suffix unique to this call, on this worker, on this run.
 *
 * @return Short suffix safe to append to an identifier or a title.
 */
function nextFixtureSuffix(): string {
	fixtureSeq += 1
	return String(fixtureSeq)
}

/**
 * Case types this suite is allowed to ADOPT: every one on the instance that
 * some fixture run does not already own.
 *
 * Adopting `[0]` unfiltered is what pinned the whole suite to `workers: 1`.
 * Worker A seeds a throwaway caseType, worker B adopts it as if it were
 * instance data (`seeded: false`, so B never cleans it up), and A's teardown
 * then deletes it out from under B mid-test. Excluding rows that carry
 * `FIXTURE_PREFIX` removes that whole class: a worker can only ever adopt a
 * caseType no teardown will remove.
 *
 * Filtering rather than always-seeding is deliberate. Five specs
 * (case-communication, case-documents, case-parties, case-task-pane,
 * case-detail-kpis-and-tabs) each record the same reason for adopting instead
 * of seeding: `case` is an ARCHIVAL schema, so a seeded case cannot be deleted
 * in teardown, and a caseType that IS deleted therefore leaves permanent cases
 * pointing at a type that is gone — which reddens unrelated specs. Making every
 * caller seed its own type would reintroduce exactly that.
 *
 * 🔴 PUBLISHED ONLY, AND THIS HALF IS NOT OPTIONAL. Since #1918
 * `case.caseType` carries `x-relation-filter: {isDraft: false}`, and the
 * caseType schema DEFAULTS `isDraft` to true. A case seeded against a draft
 * type therefore has no usable type: the list comes back empty and reads as
 * "there is nothing here" rather than as a bad fixture. #1944 fixed the
 * fixtures that CREATE a type; the six specs that ADOPT one were not covered.
 *
 * Measured on the dev instance rather than assumed: of 21 live case types
 * 13 are drafts, so blind `[0]` adoption picks one more often than not, and
 * the register import itself ships 6 of its 14 with the field ABSENT.
 *
 * The two filters compose in a way that matters under parallel workers.
 * Another worker's seeded type is `isDraft: false` and would have been a
 * perfectly valid adoption; the FIXTURE_PREFIX filter excludes it and would
 * otherwise push these specs onto a DRAFT shipped type instead — making the
 * multi-worker case worse than the serial one it was meant to enable.
 *
 * `=== false` and not `!== true`: absent must count as a draft, because that
 * is what the schema default makes it.
 *
 * @param api Authenticated request context.
 * @return Published case types no fixture run owns, in server order.
 */
export async function adoptableCaseTypes(api: APIRequestContext): Promise<any[]> {
	const rows = await listObjects(api, 'caseType')
	return rows.filter(
		(row: any) =>
			row.isDraft === false
			&& JSON.stringify(row).includes(FIXTURE_PREFIX) === false,
	)
}

/**
 * Discover an existing caseType to attach seeded cases to. The `case` schema
 * requires `caseType`; a real caseType (with its statusTypes) is needed for
 * the transition engine. If none exists we seed a throwaway one tagged with
 * RUN_PREFIX so cleanup removes it.
 *
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 */
export async function ensureCaseType(
	api: APIRequestContext,
	token: string,
): Promise<{ id: string; name: string; seeded: boolean }> {
	const existing = await adoptableCaseTypes(api)
	if (existing.length > 0) {
		const ct = existing[0]
		return {
			id: objectId(ct),
			name: String(ct.title ?? ct.name ?? 'caseType'),
			seeded: false,
		}
	}
	// Live caseType schema requires `title` (+ identifier), not `name`.
	const suffix = nextFixtureSuffix()
	const name = `${RUN_PREFIX} CaseType ${suffix}`
	const ct = await createObject(api, token, 'caseType', {
		title: name,
		identifier: `${RUN_PREFIX.toLowerCase()}-casetype-${suffix}`,
		description: 'Throwaway caseType seeded by the dossiq deep e2e layer.',
		// PUBLISHED, NOT DRAFT. `case.caseType` carries
		// `x-relation-filter: {isDraft: false}` and the caseType schema defaults
		// `isDraft` to TRUE, so a type seeded without this is a draft and never
		// appears in the New case picker.
		isDraft: false,
	})
	return { id: objectId(ct), name, seeded: true }
}

/**
 * Seed a case with the given title and fields. Returns the created object.
 *
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
	/**
	 * The caseType's TITLE, exactly as stored.
	 *
	 * Returned because a caller cannot reconstruct it. The title carries a
	 * per-call suffix (`RUN_PREFIX` is per-process, so a second call in the
	 * same worker would otherwise reuse the first call's identifier), and a
	 * spec that rebuilt it from `RUN_PREFIX` alone matched EVERY machine this
	 * worker seeded rather than its own.
	 */
	caseTypeTitle: string
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
 *
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

	// The suffix is what lets one worker seed more than one state machine:
	// `RUN_PREFIX` is per-process, so without it the second call reuses the
	// first call's identifier.
	const machineSuffix = nextFixtureSuffix()
	const caseTypeTitle = `${RUN_PREFIX} Vergunning ${machineSuffix}`
	const caseType = await createObject(api, token, 'caseType', {
		title: caseTypeTitle,
		identifier: `${RUN_PREFIX.toLowerCase()}-verg-${machineSuffix}`,
		description: 'Throwaway caseType for the dossiq state-machine e2e layer.',
		// See `ensureCaseType`: the schema defaults this to true and
		// `case.caseType` filters the picker on `isDraft: false`.
		isDraft: false,
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

	return {
		caseTypeId,
		caseTypeTitle,
		statusReceived,
		statusInProgress,
		statusDone,
		created,
	}
}

const DOSSIQ_API = '/index.php/apps/dossiq/api'

/**
 * GET the engine's available transitions for a case.
 *
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
 *
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
 *
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
 *
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
 * OpenRegister's task ENGINE. A flow task is not an OpenRegister object, so it
 * has its own table, its own field names and its own verbs — `/api/objects/…`
 * cannot see it and `cleanupRunObjects` cannot sweep it.
 */
export const FLOW_TASKS_BASE = '/index.php/apps/openregister/api/flow-tasks'

/**
 * The fields a seeded engine task takes.
 *
 * Three names change from the `caseTask` schema this replaced, and they are
 * the three that silently seed nothing when written the old way: `case`
 * becomes `objectUuid` (OpenRegister has no case entity — the case IS the
 * object), `status` becomes `state`, and `dueDate` becomes `dueAt`.
 */
export interface FlowTaskSeed {
	/** The task title. Carry RUN_PREFIX so a row locator can find it. */
	title: string
	/** The object the task hangs off — a case uuid, for dossiq. */
	objectUuid?: string
	/** The uid the task is assigned to. Omit to leave it in the pool. */
	assignee?: string
	/** available | enabled | active. A terminal state needs the verb. */
	state?: string
	/** ISO instant. `overdue` is `dueAt < now`, an INSTANT comparison. */
	dueAt?: string
	/** low | normal | high | urgent. */
	priority?: string
	/** The uids that may claim an unassigned task, i.e. its pool. */
	candidateUsers?: string[]
	/** The group ids that may claim an unassigned task. */
	candidateGroups?: string[]
}

/** Every engine task this process seeded, newest last, for teardown. */
const seededFlowTasks: string[] = []

/**
 * Seed one task IN THE ENGINE and return its uuid.
 *
 * 🔴 SEEDING A `caseTask` OBJECT SEEDS SOMETHING NO SURFACE READS. dossiq#2357
 * moved every task read onto the engine and #2408 moved the Tasks index with
 * it, so a fixture that still posts `/api/objects/dossiq/caseTask` writes a
 * different table: the list then shows nothing and the spec times out on a
 * title that was never going to arrive, which reads as a broken list rather
 * than as a fixture pointing at the wrong store.
 *
 * The uuid is the id. The numeric primary key is one no route accepts.
 *
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 * @param seed  The task's fields.
 */
export async function seedFlowTask(
	api: APIRequestContext,
	token: string,
	seed: FlowTaskSeed,
): Promise<string> {
	const res = await api.post(FLOW_TASKS_BASE, {
		headers: writeHeaders(token),
		data: { appId: REGISTER, state: 'available', ...seed },
	})
	expect(
		res.status(),
		`seed engine task "${seed.title}" -> ${res.status()} ${await res.text()}`,
	).toBe(201)

	const created = await res.json()
	const uuid = String(created?.uuid ?? '')
	expect(uuid, `seeded task "${seed.title}" came back without a uuid`).not.toBe('')
	// Read back what the engine STORED, not what was asked for. A state the
	// engine declined would otherwise be discovered by a lens assertion three
	// screens away from the cause.
	expect(
		String(created.state),
		`seeded task "${seed.title}" did not take the state asked for`,
	).toBe(String(seed.state ?? 'available'))
	seededFlowTasks.push(uuid)
	return uuid
}

/**
 * Drive one lifecycle verb on an engine task and assert it was accepted.
 *
 * A terminal task cannot be CREATED by an ordinary caller — the engine
 * refuses a task born closed — so a spec that needs a completed task drives
 * it there through the verb, which is also the transition a person makes.
 *
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 * @param uuid  The task.
 * @param verb  claim | complete | cancel | …
 * @param body  The verb's payload, when it takes one.
 */
export async function invokeFlowTask(
	api: APIRequestContext,
	token: string,
	uuid: string,
	verb: string,
	body: Record<string, unknown> = {},
): Promise<any> {
	const res = await api.post(`${FLOW_TASKS_BASE}/${uuid}/${verb}`, {
		headers: writeHeaders(token),
		data: body,
	})
	expect(
		res.ok(),
		`${verb} task ${uuid} -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	return await res.json()
}

/**
 * Read the engine inbox with explicit query parameters.
 *
 * @param api    Authenticated request context.
 * @param params The inbox query, as the endpoint takes it.
 */
export async function listFlowTasks(
	api: APIRequestContext,
	params: Record<string, string>,
): Promise<any[]> {
	const query = new URLSearchParams(params).toString()
	const res = await api.get(`${FLOW_TASKS_BASE}?${query}`)
	expect(
		res.ok(),
		`list engine tasks (${query}) -> ${res.status()} ${await res.text()}`,
	).toBeTruthy()
	const body = await res.json()
	return Array.isArray(body?.results) ? body.results : []
}

/**
 * Cancel every engine task this process seeded.
 *
 * `cancel` is the only removal verb the engine publishes — it TERMINATES
 * rather than erases — and failures are swallowed on purpose: a task a test
 * already completed answers 409 to a cancel, and a teardown that threw on
 * that would redden a run whose assertions all passed.
 *
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 */
export async function cleanupFlowTasks(
	api: APIRequestContext,
	token: string,
): Promise<void> {
	while (seededFlowTasks.length > 0) {
		const uuid = seededFlowTasks.pop() as string
		try {
			await api.post(`${FLOW_TASKS_BASE}/${uuid}/cancel`, {
				headers: writeHeaders(token),
				data: {},
			})
		} catch {
			// Best effort; the next run's residue sweep is the backstop.
		}
	}
}

/**
 * Find every object of `schema` whose stringified body contains RUN_PREFIX
 * and delete it. Used by afterAll to guarantee no seeded data is left behind.
 *
 * @param api     Authenticated request context.
 * @param token   CSRF request-token.
 * @param schemas Schema slugs to sweep (order matters: children before parents).
 */
export async function cleanupRunObjects(
	api: APIRequestContext,
	token: string,
	schemas: string[] = [...FIXTURE_SCHEMAS],
): Promise<void> {
	// This sweep is a NETWORK walk over every fixture schema plus the trash, so
	// its cost scales with `FIXTURE_SCHEMAS`, not with what the spec created. At
	// ten schemas on a loaded instance it does not fit the 30s the config gives
	// a hook, and the run then fails with `"afterAll" hook timeout` — pointing at
	// the spec that happened to finish last rather than at the sweep.
	//
	// Raised here rather than in playwright.config.ts on purpose: the config
	// timeout also governs every TEST, and loosening that would hide a genuinely
	// slow test. This widens only the teardown that is genuinely slow.
	try {
		test.setTimeout(120_000)
	} catch {
		// Called outside a running test/hook. Nothing to extend; carry on.
	}

	const survivors = [
		...(await sweepPrefix(api, token, RUN_PREFIX, schemas)),
		...(await sweepTrash(api, token, RUN_PREFIX)),
	]
	if (survivors.length > 0) {
		throw new Error(
			'e2e teardown left objects behind, so the next run on this instance '
				+ `starts dirty: ${survivors.join(', ')}`,
		)
	}
}

/**
 * Remove the residue of fixture runs that are no longer running.
 *
 * Called once from `global-setup.ts`, before any spec has run. Per-spec
 * teardown can only sweep its own `RUN_PREFIX`; a run that was interrupted
 * (Ctrl-C, a crashed worker, a `globalTimeout`) never reaches its teardown at
 * all, and its objects then belong to no future run's prefix. This is what
 * collects them.
 *
 * 🔴 IT USED TO REMOVE EVERY `E2EZAAK-` OBJECT, on the stated premise that
 * "the suite runs single-worker and owns its instance". On the shared
 * developer instance it does not: several sessions run this suite against the
 * same Nextcloud, the family prefix is common to all of them, and one run's
 * setup deleted another session's case type, statuses and workflow template
 * between that session's `beforeAll` and its first assertion. The victim saw
 * "No status types defined" on a case type it had just seeded.
 *
 * So the sweep is now bounded by AGE (`residue.ts`): a live row is removed only
 * when its own `updated`/`created` stamp is older than
 * `DOSSIQ_E2E_RESIDUE_MIN_AGE_MINUTES` (default 120, well past the suite's 38
 * minute `globalTimeout`), which no running suite's fixtures can be. A crashed
 * run's leftovers therefore still go, one sweep after they age out, without
 * anybody passing a flag.
 *
 * The TRASH is left alone unless the instance is declared the caller's own
 * (`DOSSIQ_E2E_SWEEP_ALL_RESIDUE=1`, which also drops the age bound). A
 * trashed row carries no timestamp OpenRegister will return (`@self.created`
 * and `updated` are null there), and a row nobody can date may belong to a run
 * that trashed it a second ago.
 *
 * @param api   Authenticated request context.
 * @param token CSRF request-token.
 * @return Ids that could not be removed (empty on a clean sweep).
 */
export async function sweepFixtureResidue(
	api: APIRequestContext,
	token: string,
): Promise<string[]> {
	const minAgeMs = residueMinAgeMs()
	const includeTrash = sweepsAllResidue()

	return [
		...(await sweepPrefix(
			api,
			token,
			FIXTURE_PREFIX,
			[...FIXTURE_SCHEMAS],
			minAgeMs,
		)),
		...(includeTrash ? await sweepTrash(api, token, FIXTURE_PREFIX) : []),
	]
}

/**
 * Purge every TRASHED row whose body carries `prefix`.
 *
 * The live sweep cannot reach these: a spec that deletes its own object during
 * a test soft-deletes it, so by teardown the row is gone from the object API
 * and `sweepPrefix` never enumerates it, while it sits in the trash for good.
 * After two full runs on one rig that surface held 8 prefixed rows with
 * nothing to remove them.
 *
 * The trash endpoint refuses an archival record even once it is trashed, so a
 * row an older rig managed to soft-delete before openregister#3428 landed can
 * only leave through the CLI purge. Survivors of the HTTP pass are handed to it
 * in ONE batched invocation rather than one process per row.
 *
 * @param api    Authenticated request context.
 * @param token  CSRF request-token.
 * @param prefix Run prefix or family prefix.
 * @return Trash ids that survived the sweep.
 */
async function sweepTrash(
	api: APIRequestContext,
	token: string,
	prefix: string,
): Promise<string[]> {
	const matching = async (): Promise<string[]> => {
		const res = await api.get(`${TRASH_BASE}?limit=500`).catch(() => null)
		if (res === null || res.ok() === false) return []
		const rows = unwrapList(await res.json().catch(() => ({})))
		return rows
			.filter((row: any) => JSON.stringify(row).includes(prefix))
			.map((row: any) => objectId(row))
			.filter((id: string) => id !== '')
	}

	for (const id of await matching()) {
		await api
			.delete(`${TRASH_BASE}/${id}`, { headers: writeHeaders(token) })
			.catch(() => undefined)
	}

	const refused = await matching()
	if (refused.length > 0) {
		await occPurge(refused)
	}

	return (await matching()).map((id) => `deleted/${id}`)
}

/**
 * Purge every object whose body carries `prefix`, plus the child rows the app
 * itself created against those objects.
 *
 * The child sweep is the half a prefix match cannot do on its own: a
 * `statusRecord` written by the transition engine carries the case's UUID and
 * none of the fixture's text, so `JSON.stringify(row).includes(prefix)` never
 * matches it. Those rows outlived every previous teardown.
 *
 * `minAgeMs` bounds the sweep to rows that are nobody's live fixture (see
 * `sweepFixtureResidue`). It is zero for a run's own teardown, which removes
 * what it seeded however fresh. A child row of a case that IS removed goes
 * with it whatever its own age, because it would otherwise be left pointing
 * at a case that no longer exists.
 *
 * @param api      Authenticated request context.
 * @param token    CSRF request-token.
 * @param prefix   Run prefix or family prefix.
 * @param schemas  Schema slugs to sweep, child-first.
 * @param minAgeMs How old a prefixed row must be to be removed; 0 for all.
 * @return Ids that still resolve after the sweep.
 */
async function sweepPrefix(
	api: APIRequestContext,
	token: string,
	prefix: string,
	schemas: string[],
	minAgeMs = 0,
): Promise<string[]> {
	const survivors: string[] = []

	// Case ids first, so the child sweep below knows what to orphan-hunt for.
	const caseIds = new Set<string>()
	if (schemas.includes('case') === true) {
		for (const row of await listAllObjects(api, 'case').catch(() => [])) {
			if (
				JSON.stringify(row).includes(prefix)
				&& isStaleResidue(row, minAgeMs)
			) {
				caseIds.add(objectId(row))
			}
		}
	}

	for (const schema of schemas) {
		let rows: any[]
		try {
			rows = await listAllObjects(api, schema)
		} catch {
			continue
		}

		for (const row of rows) {
			const id = objectId(row)
			if (id === '') continue
			const matchesPrefix =
				JSON.stringify(row).includes(prefix) && isStaleResidue(row, minAgeMs)
			const matchesCase =
				caseIds.has(String(row.case ?? '')) === true
				|| caseIds.has(String(row.parentCase ?? '')) === true
			if (matchesPrefix === false && matchesCase === false) continue

			// An OccUnavailableError is NOT a survivor: it means no row can be
			// removed at all, so it must abort here rather than be reported once
			// per fixture as though each one had individually resisted.
			const gone = await purgeObject(api, token, schema, id).catch(
				(error: unknown) => {
					if (error instanceof OccUnavailableError) throw error
					return false
				},
			)
			if (gone === false) survivors.push(`${schema}/${id}`)
		}
	}

	return survivors
}
