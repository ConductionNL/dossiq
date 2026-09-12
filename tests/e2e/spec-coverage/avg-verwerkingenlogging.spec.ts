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
 *
 * NOT YET WATCHED FAILING. Everything these two tests guard is enforced in
 * OpenRegister's PHP, so the only honest mutation is a server-side one, and
 * that needs a change to the shared dev instance still awaiting approval. The
 * mutation points, and the assertion each must redden:
 *
 *  - openregister lib/Controller/ProcessingLogController.php, involvedParty():
 *    replace the findBySubject() result with `[]`. The export test must fail
 *    on "the inzage export for <subject> lists the read of role <uuid>".
 *  - openregister lib/Db/Verwerkingsactiviteit.php, jsonSerialize(): drop the
 *    `status` key. The catalogue test must fail on "the catalogue row carries a
 *    review status".
 *  - openregister lib/Service/ProcessingLogService.php, logRead(): return
 *    before the entry is buffered. Both tests must fail on their processing-log
 *    poll ("is recorded in OpenRegister's processing log" and the export poll).
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
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

/**
 * Read one of the app's declaration files.
 *
 * The retirement scenario is a statement about the MANIFEST, not only about a
 * screen, so proving it needs the declaration as well as the render. Both are
 * asserted below and neither is enough on its own: the declaration alone never
 * notices a renderer that ignores it, and the render alone never notices a
 * declaration that only happens to produce the right screen on an instance
 * where OpenRegister is installed.
 *
 * @param name The file, relative to `src/`.
 * @return The parsed JSON.
 */
function readSrcJson(name: string): any {
	return JSON.parse(
		fs.readFileSync(path.join(__dirname, '..', '..', '..', 'src', name), 'utf8'),
	)
}

/** The app's bundled manifest, before fragments and the layout pass. */
const MANIFEST = readSrcJson('manifest.json')

/** The canonical navigation layout, which decides WHERE an entry lives. */
const MENU_LAYOUT = readSrcJson('menu-layout.json')

/** The label the retired page and the surviving link both carry. */
const AVG_LABEL = 'Processing activities (AVG)'

let api: APIRequestContext
let token: string
let caseTypeId: string
let caseTypeSeeded = false
let caseUuid: string
let roleUuid: string

test.describe('AVG verwerkingenlogging spec coverage', () => {
	// @e2e openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#scenario-procest-hosts-no-processing-activities-page
	//
	// 🔴 THIS TEST REGRESSED AND WAS REPAIRED. It drove the retired route and
	// asserted one English heading was absent, and nothing else. The scenario
	// states four things, and the re-measurement of 2026-09-12 caught the cost:
	// `AvgRegisterLink` carries no `section` key in `src/manifest.json`, so one
	// of the scenario's own THENs read as false in the source while this test
	// stayed green. It reads false for a reason that turns out to be good news,
	// and that is exactly why nobody should have had to go and look.
	//
	// ✅ THE PRODUCT MOVED, NOT THE REQUIREMENT. ADR-110 took the `section`
	// decision out of `manifest.json` and into `src/menu-layout.json`, which
	// lists the ids under `integrationsSection`; `applyIntegrationsSection` in
	// @conduction/nextcloud-vue then stamps `section: "integrations"` onto the
	// entry as the effective manifest is built. `AvgRegisterLink` is in that
	// list. The THEN holds, through a mechanism the spec text predates.
	//
	// So every THEN is asserted now, each one twice where it can be: on the
	// DECLARATION, and on what the declaration produces on screen. The rendered
	// half is what makes the effective `section` assertable at all, because
	// CnAppRoot renders `#cn-integrations` from entries whose effective section
	// is `integrations` and from nothing else.
	//
	// ✅ MUTATION CHECK RUN 2026-09-12 — see the file header for the three
	// server-side mutations that are still pending permission. This one needed
	// neither permission nor a lock, because the relocation is client-side:
	// `tests/e2e/helpers/mutate-bundle.ts` rewrote the served bundle so the
	// broken code ran in the browser while nothing on disk moved.
	//
	//   find    /"integrationsSection":\["AvgRegisterLink",/
	//   replace '"integrationsSection":['
	//   red on  "the retired processing-activities entry must not be in the navigation"
	//
	// Dropping the id returns the link to the navigation, which is the state
	// the scenario forbids, and the heading-only assertion this replaced stays
	// green through all of it.
	test('procest no longer hosts a processing-activities page', async ({
		page,
	}) => {
		// THEN no `/verwerkingen` page exists. On the declaration first: a page
		// removed from the manifest is the thing that retires a route, and the
		// screen below cannot tell "no such page" from "the page failed to
		// render".
		const verwerkingenPages = (MANIFEST.pages ?? []).filter((pageDef: any) =>
			/verwerkingen/i.test(
				`${pageDef.route ?? ''} ${pageDef.id ?? ''} ${pageDef.component ?? ''}`,
			),
		)
		expect(
			verwerkingenPages.map((pageDef: any) => pageDef.id),
			'the manifest must declare no processing-activities page',
		).toEqual([])

		// MUST be the app's real id. Against `/apps/procest/...` the server
		// serves nothing at all, so `toHaveCount(0)` would pass without the
		// retirement having anything to do with it — the test would assert the
		// absence of a heading that could never have rendered either way.
		await page.goto(`${BASE}/index.php/apps/dossiq/verwerkingen`)
		// The retired route is unrouted, so the SPA falls back to the app root
		// rather than rendering the old overview. Assert the heading is gone —
		// asserting a 404 would be wrong, the server serves the SPA for any app path.
		await expect(page.getByRole('heading', { name: AVG_LABEL })).toHaveCount(0)

		// AND no processing-activities NAVIGATION entry exists. This is the
		// clause the old test left entirely unguarded, and the one the
		// mutation above breaks.
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(nav).toBeVisible({ timeout: 30_000 })
		await expect(
			nav.locator('[data-testid="cn-nav-entry-AvgRegisterLink"]'),
			'the retired processing-activities entry must not be in the navigation',
		).toHaveCount(0)
		await expect(
			nav.getByText(AVG_LABEL, { exact: true }),
			'nothing in the navigation may read as a processing-activities page of our own',
		).toHaveCount(0)

		// AND `VerwerkingenOverview.vue` is not registered as a component.
		// Read off the bundle the browser actually loaded rather than off the
		// repository: a component deleted from `src/` but still referenced by a
		// stale build is exactly the state this clause exists to catch, and
		// only the served artefact knows which one is running.
		const bundle = await page.evaluate(async () => {
			const el = [...document.querySelectorAll('script[src]')].find((script) =>
				/dossiq-main\.js/.test(script.getAttribute('src') || ''),
			)
			if (!el) return ''
			const res = await fetch(el.getAttribute('src') as string)
			return res.ok ? await res.text() : ''
		})
		expect(
			bundle.length,
			'the page must have loaded a dossiq bundle, or the clause below proves nothing',
		).toBeGreaterThan(1000)
		expect(
			bundle.includes('VerwerkingenOverview'),
			'VerwerkingenOverview must not be registered as a component',
		).toBe(false)

		// AND the link into OpenRegister's `/avg` surface is a
		// `section: "integrations"` entry, gated on
		// `visibleIf.appInstalled: "openregister"`.
		const link = (MANIFEST.menu ?? []).find(
			(item: any) => item.id === 'AvgRegisterLink',
		)
		expect(
			link,
			"the manifest must keep a link into OpenRegister's AVG surface",
		).toBeTruthy()
		expect(link.href, "the link must address OpenRegister's /avg surface").toBe(
			'/apps/openregister/#/avg',
		)
		expect(
			link.visibleIf?.appInstalled,
			'the link must be gated on OpenRegister being installed, or it advertises a 404 as a feature',
		).toBe('openregister')
		// `section: "integrations"` is stamped on by `applyIntegrationsSection`
		// from this list (ADR-110), which is why the key is absent from
		// `manifest.json` and present on the effective entry.
		expect(
			MENU_LAYOUT.integrationsSection,
			'the link must be declared an integrations entry, which is what moves it out of the navigation',
		).toContain('AvgRegisterLink')

		// And the effective section, read off the only surface that renders it:
		// CnAppRoot builds `#cn-integrations` from entries whose effective
		// section is `integrations`, and from nothing else. A declaration the
		// build then ignores fails here rather than passing above.
		await nav.locator('[data-testid="cn-nav-settings"]').click()
		await nav.locator('[data-testid="cn-nav-personal-settings"]').click()
		// ⚠️ THE SECTION ID IS NOT THE ONE THE APP WRITES. CnAppRoot declares
		// `id="cn-integrations"`, and Nextcloud's NcAppSettingsSection renders
		// it as `settings-section_cn-integrations`. `#cn-integrations` matches
		// nothing and fails as though the section were missing. Located by role
		// and name instead, which is the same thing a reader sees and is not a
		// hostage to that prefix.
		const integrations = page
			.getByRole('dialog')
			.getByRole('region', { name: 'Integrations' })
		await expect(
			integrations,
			'the per-user settings modal must carry an Integrations section',
		).toBeVisible({ timeout: 15_000 })
		const avgEntry = integrations.getByRole('link', { name: AVG_LABEL })
		await expect(
			avgEntry,
			'the AVG link must render in Integrations, which is where a section:"integrations" entry goes',
		).toHaveCount(1)
		await expect(
			avgEntry,
			"and it must still address OpenRegister's /avg surface",
		).toHaveAttribute('href', /\/apps\/openregister\/#\/avg$/)
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
