/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Package journey: bezwaar-beroep (hydra ADR-118, decision 185).
 *
 * The package is `install: on-request`, so nothing installs it on upgrade. An
 * administrator asks for it through the setup action `seed`. The journey asks,
 * then opens both case types the way an administrator would and finds each
 * one with its published flow, then asks again and finds nothing written.
 *
 * The ids are the fixed ids in lib/Settings/packages/bezwaar-beroep/, which
 * is the point of a package: every instance holds the same rows.
 *
 * Writes real state: the package stays installed. That is the state the
 * second call, and every later run, expects.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, PAGE_LOAD } from '../helpers/nav.ts'

const CASE_TYPES = [
	{
		id: 'b3c1a000-0000-4000-a000-00000000be2a',
		title: 'Bezwaar',
		flow: 'Bezwaar Standaard Workflow',
	},
	{
		id: 'b3c1a000-0000-4000-a000-00000000be30',
		title: 'Beroep',
		flow: 'Beroep Standaard Workflow',
	},
]

/**
 * Run the setup action `seed` from the loaded page, with CSRF.
 *
 * @param page The page, on any dossiq route.
 * @return The HTTP status and the decoded body.
 */
async function runSeed(page: Page): Promise<{ status: number; body: any }> {
	return await page.evaluate(async () => {
		const w = window as unknown as { OC?: { requestToken?: string } }
		const token =
			w.OC?.requestToken
			|| document.head.getAttribute('data-requesttoken')
			|| ''
		const res = await fetch('/index.php/apps/dossiq/api/setup/action/seed', {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				requesttoken: token,
			},
		})
		return { status: res.status, body: await res.json().catch(() => null) }
	})
}

test.describe('package bezwaar-beroep', () => {
	// @e2e openspec/specs/case-types/spec.md#an-administrator-installs-bezwaar-and-beroep
	test('an administrator installs bezwaar and beroep and finds both case types', async ({
		page,
	}) => {
		test.setTimeout(180_000)
		await page.goto('/index.php/apps/dossiq', PAGE_LOAD)
		await dismissSupportDialog(page)

		const first = await runSeed(page)
		expect(
			first.body,
			`POST /api/setup/action/seed returned ${first.status} with no JSON body`,
		).not.toBeNull()
		expect(first.status, first.body?.message).toBe(200)
		expect(first.body.success).toBe(true)
		expect(['installed', 'current']).toContain(first.body.detail?.status)
		expect(first.body.detail?.package).toBe('bezwaar-beroep')

		for (const caseType of CASE_TYPES) {
			await page.goto(
				`/index.php/apps/dossiq/settings/case-types/${caseType.id}`,
				PAGE_LOAD,
			)
			await dismissSupportDialog(page)
			const detail = page.locator('.cn-detail-page')
			await expect(detail).toBeVisible({ timeout: 30_000 })
			await expect(
				detail.getByText(caseType.title, { exact: true }).first(),
			).toBeVisible()
			// The flow under Versions: the case type is usable, not just stored.
			await expect(detail.getByText(caseType.flow).first()).toBeVisible({
				timeout: 30_000,
			})
		}

		// Asking again finds the package current and writes nothing.
		const second = await runSeed(page)
		expect(second.status, second.body?.message).toBe(200)
		expect(second.body.success).toBe(true)
		expect(second.body.detail?.status).toBe('current')
	})
})
