/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Package journey: besluitvorming (hydra ADR-118, decisions 185 and 190).
 *
 * The package is `install: on-request`: decision types live in decidiq now,
 * so nothing installs it on upgrade. An administrator asks for it through
 * the package API. The journey asks, opens the three case types the way an
 * administrator would and finds each with its flow, then asks again and
 * finds nothing written.
 *
 * The case types are found by identifier, not by the package's fixed ids: on
 * an instance where the former besluitvorming seeder ran, the installer
 * adopts the rows it wrote, and those keep their own ids (decision 190).
 *
 * Writes real state: the package stays installed. That is the state the
 * second call, and every later run, expects.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, PAGE_LOAD } from '../helpers/nav.ts'

const CASE_TYPES = [
	{
		identifier: 'bvw-college-besluit',
		title: 'College-besluit',
		flow: 'College-besluit workflow v1',
	},
	{
		identifier: 'bvw-raadsbesluit',
		title: 'Raadsbesluit',
		flow: 'Raadsbesluit workflow v1',
	},
	{
		identifier: 'bvw-mandaatbesluit',
		title: 'Mandaatbesluit',
		flow: 'Mandaatbesluit workflow v1',
	},
]

/**
 * Ask the package API to install one package, from the loaded page, with CSRF.
 *
 * @param page The page, on any dossiq route.
 * @param id The package id.
 * @return The HTTP status and the decoded body.
 */
async function install(
	page: Page,
	id: string,
): Promise<{ status: number; body: any }> {
	return await page.evaluate(async (packageId: string) => {
		const w = window as unknown as { OC?: { requestToken?: string } }
		const token =
			w.OC?.requestToken
			|| document.head.getAttribute('data-requesttoken')
			|| ''
		const res = await fetch(
			`/index.php/apps/dossiq/api/packages/${packageId}/install`,
			{
				method: 'POST',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					requesttoken: token,
				},
			},
		)
		return { status: res.status, body: await res.json().catch(() => null) }
	}, id)
}

/**
 * The ids of the case types with one identifier, through the objects API.
 *
 * @param page The page, on any dossiq route.
 * @param identifier The case type identifier.
 * @return The ids found.
 */
async function caseTypeIds(page: Page, identifier: string): Promise<string[]> {
	return await page.evaluate(async (wanted: string) => {
		const res = await fetch(
			`/index.php/apps/openregister/api/objects/dossiq/caseType?identifier=${encodeURIComponent(wanted)}&_limit=200`,
			{
				headers: { Accept: 'application/json' },
			},
		)
		const body = await res.json().catch(() => null)
		const rows: any[] = Array.isArray(body) ? body : (body?.results ?? [])
		return rows
			.filter((row) => row.identifier === wanted)
			.map((row) => String(row.id ?? row['@self']?.id ?? ''))
	}, identifier)
}

test.describe('package besluitvorming', () => {
	// @e2e openspec/specs/besluitvorming-workflow/spec.md#scenario-req-bvw-001-a-activate-college-besluit-template
	// @e2e openspec/specs/case-types/spec.md#an-on-request-package-installs-through-the-api
	test('an administrator installs besluitvorming and finds the three case types', async ({
		page,
	}) => {
		test.setTimeout(180_000)
		await page.goto('/index.php/apps/dossiq', PAGE_LOAD)
		await dismissSupportDialog(page)

		const first = await install(page, 'besluitvorming')
		expect(
			first.body,
			`POST /api/packages/besluitvorming/install returned ${first.status} with no JSON body`,
		).not.toBeNull()
		expect(first.status, first.body?.message).toBe(200)
		expect(['installed', 'current']).toContain(first.body.status)
		expect(first.body.package).toBe('besluitvorming')

		for (const caseType of CASE_TYPES) {
			const ids = await caseTypeIds(page, caseType.identifier)
			// One case type per identifier: the install adopted or wrote it, never both.
			expect(
				ids,
				`case types with identifier ${caseType.identifier}`,
			).toHaveLength(1)
			await page.goto(
				`/index.php/apps/dossiq/settings/case-types/${ids[0]}`,
				PAGE_LOAD,
			)
			await dismissSupportDialog(page)
			const detail = page.locator('.cn-detail-page')
			await expect(detail).toBeVisible({ timeout: 30_000 })
			await expect(
				detail.getByText(caseType.title, { exact: true }).first(),
			).toBeVisible()
			await expect(detail.getByText(caseType.flow).first()).toBeVisible({
				timeout: 30_000,
			})
		}

		const second = await install(page, 'besluitvorming')
		expect(second.status, second.body?.message).toBe(200)
		expect(second.body.status).toBe('current')
	})
})
