/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AVG verwerkingenlogging spec coverage — AFTER the surface moved to OpenRegister.
 *
 * page-topology-cleanup (C1) retired procest's /verwerkingen page. Per ADR-047
 * the AVG/DSAR workflow and its register are OpenRegister capabilities, and this
 * page was never an implementation of one — it was a window onto OR's, and a
 * broken one: it called `/api/avg/verwerkingsactiviteiten`, which OR had renamed
 * to `/api/avg/processing-activities`. The call 404'd, and the page rendered a
 * "No processing activities — run the repair step" empty state that blamed
 * missing data for a dead endpoint. The catalogue was in OR the whole time.
 *
 * So the requirement did not disappear, it changed address. These tests assert
 * exactly that: procest no longer hosts the surface, and the capability is live
 * where it belongs.
 *
 * "Live" is asserted on what OpenRegister RECORDED, not on an endpoint
 * answering. A route that returns 200 with an empty list, or validates a
 * missing parameter, stays green when no processing is logged at all. So each
 * test reads a real dossiq object whose schema opts in to read logging
 * (`x-openregister-processing.logReads`), then finds that read again: in the
 * processing log, attributed to the catalogue row the schema declares, and in
 * the data subject's inzage export.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	createObject,
	deleteObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	RUN_PREFIX,
	seedCase,
} from '../helpers/fixtures.ts'

const BASE = BASE_URL
const OBJECTS = '/index.php/apps/openregister/api/objects/dossiq'
const AVG = '/index.php/apps/openregister/api/avg'

/** The data subject the seeded role names, unique to this run. */
const SUBJECT = `${RUN_PREFIX}-betrokkene`

let api: APIRequestContext
let token: string
let caseTypeId: string
let caseTypeSeeded = false
let caseUuid: string
let roleUuid: string

test.describe('AVG verwerkingenlogging spec coverage', () => {
	// @e2e openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#scenario-procest-hosts-no-processing-activities-page
	test('procest no longer hosts a processing-activities page', async ({
		page,
	}) => {
		// MUST be the app's real id. Against `/apps/procest/...` the server
		// serves nothing at all, so `toHaveCount(0)` would pass without the
		// retirement having anything to do with it — the test would assert the
		// absence of a heading that could never have rendered either way.
		await page.goto(`${BASE}/index.php/apps/dossiq/verwerkingen`)
		// The retired route is unrouted, so the SPA falls back to the app root
		// rather than rendering the old overview. Assert the heading is gone —
		// asserting a 404 would be wrong, the server serves the SPA for any app path.
		await expect(
			page.getByRole('heading', { name: 'Processing activities (AVG)' }),
		).toHaveCount(0)
	})

	test.describe('the capability is reachable in OpenRegister', () => {
		test.beforeAll(async ({ baseURL }) => {
			// Four writes and a token fetch; on a loaded instance that alone can
			// exceed the default hook budget.
			test.setTimeout(120_000)
			api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
			token = await getRequestToken(api)
			const ct = await ensureCaseType(api, token)
			caseTypeId = ct.id
			caseTypeSeeded = ct.seeded
			caseUuid = objectId(
				await seedCase(api, token, {
					title: `${RUN_PREFIX} AVG processing-log case`,
					caseType: caseTypeId,
				}),
			)
			const roleType = await createObject(api, token, 'roleType', {
				name: `${RUN_PREFIX} Initiator`,
				description:
					'Throwaway role type seeded by avg-verwerkingenlogging.spec.',
				caseType: caseTypeId,
				genericRole: 'initiator',
			})
			roleUuid = objectId(
				await createObject(api, token, 'role', {
					name: `${RUN_PREFIX} Initiator`,
					roleType: objectId(roleType),
					case: caseUuid,
					participant: SUBJECT,
				}),
			)
		})

		test.afterAll(async () => {
			// Child-first, and only what this block seeded: a sweep over every
			// fixture schema does not fit a hook budget on a loaded instance.
			await cleanupRunObjects(api, token, ['role', 'case', 'roleType'])
			if (caseTypeSeeded)
				await deleteObject(api, token, 'caseType', caseTypeId)
			await api.dispose()
		})

		// @e2e openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#scenario-the-capability-is-reachable-in-openregister
		test('a case read is logged against a catalogued activity that carries a review status', async () => {
			// The catalogue is only worth reviewing if the processing it describes is
			// actually recorded against it. So this starts from a real read of a real
			// case, and walks from the STORED log entry to the catalogue row it names.
			// A 200 on the catalogue endpoint alone, which is all this test used to
			// assert, stays green while nothing is logged at all.
			const since = new Date(Date.now() - 120_000).toISOString()
			const read = await api.get(`${OBJECTS}/case/${caseUuid}`)
			expect(read.status(), 'reading the seeded case').toBe(200)
			const self = (await read.json())['@self'] ?? {}

			const findRead = async (): Promise<any> => {
				const res = await api.get(`${AVG}/verwerkingen`, {
					params: {
						register: String(self.register ?? ''),
						schema: String(self.schema ?? ''),
						action: 'read',
						from: since,
						limit: '1000',
					},
				})
				if (res.status() !== 200) return undefined
				const rows: any[] = (await res.json()).results ?? []
				return rows.find((row) => row.objectUuid === caseUuid)
			}
			await expect
				.poll(async () => (await findRead())?.objectUuid ?? null, {
					timeout: 15_000,
					message: `the read of case ${caseUuid} is recorded in OpenRegister's processing log`,
				})
				.toBe(caseUuid)
			const entry = await findRead()
			expect(entry.actor, 'the entry names who read the case').toBe('admin')

			// dossiq's case schema declares `attribution.default: zaakafhandeling`.
			// The entry must land on THAT catalogue row, not on the
			// niet-geclassificeerde-verwerking fallback that swallows every read
			// whose attribution did not resolve.
			const catalogue = await api.get(`${AVG}/processing-activities`)
			expect(catalogue.status()).toBe(200)
			const activities: any[] = (await catalogue.json()).results ?? []
			const attributed = activities.find((a) => a.uuid === entry.activityId)
			expect(
				attributed?.code,
				'the read is attributed to the activity the case schema declares',
			).toBe('zaakafhandeling')

			// The catalogue review status: every activity carries its lifecycle
			// state, which is what an FG reviews the catalogue by.
			expect(
				['concept', 'published', 'archived'],
				`the catalogue row carries a review status, got ${JSON.stringify(attributed?.status)}`,
			).toContain(attributed?.status)

			// The unclassified-processing counter: the compliance report counts the
			// reads the fallback absorbed, so the gap is visible rather than silent.
			const compliance = await api.get(`${AVG}/compliance`)
			expect(compliance.status()).toBe(200)
			const unclassified = (await compliance.json()).totals
				?.unclassifiedProcessing
			expect(
				Number.isInteger(unclassified) && unclassified >= 0,
				`the compliance report carries the unclassified-processing counter, got ${JSON.stringify(unclassified)}`,
			).toBe(true)
		})

		// @e2e openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#scenario-the-capability-is-reachable-in-openregister
		test('the inzageverzoek export returns the logged read of that data subject', async () => {
			// OR-PA-7. The role schema declares `subjectIdFields: {contact:
			// participant}`, so reading a role records WHOSE data was read. The
			// per-subject export must hand that read back when the subject asks.
			// Asserting only that the route validates, as this test used to, stays
			// green when the export returns an empty list for everyone.
			const read = await api.get(`${OBJECTS}/role/${roleUuid}`)
			expect(read.status(), 'reading the seeded role').toBe(200)

			const exportFor = () =>
				api.get(`${AVG}/verwerkingen/betrokkene`, {
					params: { subjectIdType: 'contact', subjectIdValue: SUBJECT },
				})

			await expect
				.poll(
					async () => {
						const res = await exportFor()
						if (res.status() !== 200) return [`HTTP ${res.status()}`]
						const reads: any[] = (await res.json()).reads ?? []
						return reads.map((r) => r.objectUuid)
					},
					{
						timeout: 15_000,
						message: `the inzage export for ${SUBJECT} lists the read of role ${roleUuid}`,
					},
				)
				.toContain(roleUuid)

			const body = await (await exportFor()).json()
			expect(body.subject).toEqual({ idType: 'contact', idValue: SUBJECT })
			const reads: any[] = body.reads
			expect(body.count).toBe(reads.length)
			const ours = reads.find((r) => r.objectUuid === roleUuid)
			expect(ours.action).toBe('read')
			expect(ours.actor).toBe('admin')
			// Every read in a subject's extract is about that subject, never a
			// neighbour's.
			for (const r of reads) {
				expect(r.subjectIdValue).toBe(SUBJECT)
			}

			// And the export refuses to run without naming a subject.
			const bare = await api.get(`${AVG}/verwerkingen/betrokkene`)
			expect(bare.status()).toBe(400)
		})
	})
})
