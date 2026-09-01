import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for Dossiq's key surfaces (GAP-5).
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 *
 * NOTE: dossiq serves its SPA at /apps/dossiq/index (the bare
 * /apps/dossiq/ route 404s), so navigation targets the /index entrypoint.
 */
import { request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	deleteObject,
	ensureCaseType,
	getRequestToken,
	objectId,
	seedCase,
} from '../helpers/fixtures.ts'
import { shootByNav, shootSurface } from './_visual-helpers.ts'

const APP = '/index.php/apps/dossiq/index'

test.describe('Dossiq — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}#/`, 'dashboard.png')
	})

	test('cases list', async ({ page }) => {
		await shootByNav(page, `${APP}#/`, 'Cases', 'cases.png')
	})

	// The "verwerkingen overview (AVG)" baseline was retired with the page it
	// shot: page-topology-cleanup (C1) moved the processing-activity register to
	// OpenRegister per ADR-047, so the screenshot belongs to OR's /avg surface,
	// not here.
})

/*
 * New-UI surface (this week): the CaseDetail page is where the deelzaak
 * (Sub-cases) + case-email (Email) tabs attach. Baseline the detail shell on a
 * seeded case. Dynamic regions (ids, timestamps, owner) are masked by the
 * shared visual helper, so the shot is deterministic across runs even though
 * the seeded case's uuid differs each time.
 *
 * FLAG: in the deployed @conduction/nextcloud-vue (beta.108) the CaseDetail
 * main panel renders empty in the fixed visual viewport (the same slot-render
 * gap that hides the Sub-cases/Email tabs — see deelzaak-case-email.spec.ts).
 * The baseline therefore captures the detail SHELL (nav + content host) and is
 * kept `fixme` until the lib renders the detail body, so a misleading blank
 * baseline is never committed. The e2e proves the underlying data round-trip.
 */
test.describe('Dossiq — case detail (deelzaak/email host) visual', () => {
	let api: APIRequestContext
	let token: string
	let caseId: string
	let caseTypeId: string
	let caseTypeSeeded = false

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		const ct = await ensureCaseType(api, token)
		caseTypeId = ct.id
		caseTypeSeeded = ct.seeded
		const kase = await seedCase(api, token, {
			title: 'VISUAL-BASELINE Case detail',
			caseType: caseTypeId,
			identifier: 'VISUAL-BASELINE-DETAIL',
			description: 'Stable case for the CaseDetail visual baseline.',
		})
		caseId = objectId(kase)
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token, ['case'])
		if (caseTypeSeeded) await deleteObject(api, token, 'caseType', caseTypeId)
		await api.dispose()
	})

	test.fixme('case detail', async ({ page }) => {
		// History-mode detail route (verified live): /apps/dossiq/cases/:id.
		await shootSurface(
			page,
			`/index.php/apps/dossiq/cases/${caseId}`,
			'case-detail.png',
		)
	})
})
