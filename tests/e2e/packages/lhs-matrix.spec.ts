/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Package journey: lhs-matrix (hydra ADR-118, decisions 185 and 204).
 *
 * The package is `install: auto`: every install and upgrade writes it, so the
 * journey asks for nothing. It reads what an enforcement officer relies on:
 * one active matrix, a grid with one cell for each behaviour row and
 * consequence column, and a lookup that names an intervention.
 *
 * The matrix is not looked for by name. An instance whose administrator
 * replaced the shipped default keeps its own active matrix, and the installer
 * adds no second one; there the journey finds that matrix. Either way exactly
 * one is active.
 *
 * Writes nothing.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, PAGE_LOAD } from '../helpers/nav.ts'

const ROWS = ['A', 'B', 'C', 'D']
const COLUMNS = ['1', '2', '3', '4']

/**
 * Every row of one dossiq schema, through the objects API.
 *
 * @param page The page, on any dossiq route.
 * @param schema The schema slug.
 * @return The rows.
 */
async function rows(page: Page, schema: string): Promise<any[]> {
	return await page.evaluate(async (slug: string) => {
		const res = await fetch(
			`/index.php/apps/openregister/api/objects/dossiq/${slug}?_limit=500`,
			{
				headers: { Accept: 'application/json' },
			},
		)
		const body = await res.json().catch(() => null)
		return Array.isArray(body) ? body : (body?.results ?? [])
	}, schema)
}

test.describe('package lhs-matrix', () => {
	// @e2e openspec/specs/enforcement-lhs/spec.md#scenario-a-clean-install-has-one-active-matrix
	// @e2e openspec/specs/enforcement-lhs/spec.md#scenario-an-instance-with-its-own-active-matrix-gets-no-second-one
	// @e2e openspec/specs/enforcement-lhs/spec.md#scenario-the-grid-has-one-cell-for-each-row-and-column
	test('an enforcement officer finds one active matrix, a whole grid and an intervention', async ({
		page,
	}) => {
		test.setTimeout(120_000)
		await page.goto('/index.php/apps/dossiq', PAGE_LOAD)
		await dismissSupportDialog(page)

		const active = (await rows(page, 'lhsMatrix')).filter(
			(row) => row.active === true,
		)
		expect(
			active.map((row) => row.name),
			'active matrices',
		).toHaveLength(1)
		expect(
			Array.isArray(active[0].cells) && active[0].cells.length > 0,
			'the active matrix has cells',
		).toBe(true)

		const cells = await rows(page, 'lhsMatrixCell')
		for (const row of ROWS) {
			for (const column of COLUMNS) {
				const found = cells.filter(
					(cell) =>
						cell.behaviourRow === row
						&& String(cell.consequenceColumn) === column,
				)
				expect(found, `grid cell ${row}${column}`).toHaveLength(1)
				expect(
					String(found[0].interventionStep ?? '').trim(),
					`grid cell ${row}${column} names an intervention`,
				).not.toBe('')
			}
		}

		const lookup = await page.evaluate(async () => {
			const token = (window as any).OC?.requestToken ?? ''
			const res = await fetch(
				'/index.php/apps/dossiq/api/vth/lhs/lookup?behaviour=B&gevolg=2',
				{
					headers: { Accept: 'application/json', requesttoken: token },
				},
			)
			return { status: res.status, body: await res.json().catch(() => null) }
		})
		expect(lookup.status, 'the enforcement lookup answers').toBe(200)
		expect(lookup.body?.behaviourRow).toBe('B')
		expect(lookup.body?.consequenceColumn).toBe('2')
		expect(
			String(lookup.body?.interventionStep ?? '').trim(),
			'the lookup names an intervention',
		).not.toBe('')
	})
})
