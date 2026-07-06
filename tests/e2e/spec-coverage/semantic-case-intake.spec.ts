/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for semantic-case-intake. The backend /
 * cross-app scenarios (SemanticTypeResolver discovery, graceful degrade,
 * the pipelinq→procest handoff execution, and the declarative notification)
 * run inside OpenRegister's handoff engine + pipelinq's produce-side and
 * carry @e2e excludes in the spec (proven by PHPUnit against the REAL
 * HandoffKindContracts, plus OR's own engine tests). These tests cover the
 * procest-owned UI surface: a handoff-created case (carrying handoffSource)
 * shows its provenance in the Werkvoorraad intake list and on the case
 * detail overview.
 */

import { test, expect } from '@playwright/test'
import { getRequestToken, ensureCaseType, seedCase, objectId } from '../helpers/fixtures'
import { STORAGE_STATE } from '../helpers/auth'
import { request as pwRequest, type APIRequestContext } from '@playwright/test'

const APP = '/index.php/apps/procest/index'

test.describe('Semantic case intake — handoff provenance UI', () => {
	let api: APIRequestContext
	let token: string
	let caseId: string

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		const ct = await ensureCaseType(api, token)
		// Seed a case that looks handoff-created: the mandatory contract
		// `source` field maps to handoffSource; a non-empty value marks it.
		const kase = await seedCase(api, token, {
			title: 'HANDOFF Intake demo case',
			caseType: ct.id,
			identifier: 'HANDOFF-INTAKE-1',
			description: 'Case that arrived via the ns#Case semantic handoff.',
			intakeChannel: 'handoff',
			handoffSource: 'urn:openregister:pipelinq:request:demo-123',
		})
		caseId = objectId(kase)
	})

	test.afterAll(async () => {
		await api?.dispose()
	})

	// @e2e openspec/specs/semantic-case-intake/spec.md#behandelaar-sees-the-handoff-case-with-origin
	test('handoff case shows a provenance badge in the Werkvoorraad intake', async ({ page }) => {
		await page.goto(`${APP}#/werkvoorraad`)
		const row = page.locator('.werkvoorraad__row', { hasText: 'HANDOFF Intake demo case' })
		await expect(row).toBeVisible({ timeout: 15000 })
		await expect(row.locator('.werkvoorraad__handoff-badge')).toBeVisible()
	})

	// @e2e openspec/specs/semantic-case-intake/spec.md#behandelaar-sees-the-handoff-case-with-origin
	test('case detail shows the handoff provenance with a source link', async ({ page }) => {
		await page.goto(`${APP}#/cases/${caseId}`)
		const provenance = page.getByTestId('handoff-provenance')
		await expect(provenance).toBeVisible({ timeout: 20000 })
		await expect(provenance).toContainText('Received via handoff')
		await expect(provenance.getByRole('link', { name: 'Open source object' }))
			.toHaveAttribute('href', /openregister/)
	})
})
