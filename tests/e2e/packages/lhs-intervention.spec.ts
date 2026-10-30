/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Package journey: lhs-intervention (hydra ADR-118, decisions 185, 186 and 204).
 *
 * The package is `install: auto`. It writes one row into OpenRegister's
 * decision-tables register: the table `dossiq.lhs-intervention`, which gives
 * the recommended intervention for a behaviour row and a consequence column.
 * The journey finds that table the way an administrator would look it up in
 * OpenRegister, by its key, and checks it is whole: one table, sixteen rules,
 * one for each row and column.
 *
 * A table the instance already held under the key is kept as it is, so the
 * journey checks the shape and not the wording of an intervention.
 *
 * Writes nothing.
 */

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, PAGE_LOAD } from '../helpers/nav.ts'

const KEY = 'dossiq.lhs-intervention'

test.describe('package lhs-intervention', () => {
	// @e2e openspec/specs/enforcement-lhs/spec.md#scenario-the-intervention-table-is-in-openregister-under-its-key
	test('an administrator finds the intervention table under its key, with a rule for each row and column', async ({
		page,
	}) => {
		test.setTimeout(120_000)
		await page.goto('/index.php/apps/dossiq', PAGE_LOAD)
		await dismissSupportDialog(page)

		const tables = await page.evaluate(async (key: string) => {
			const res = await fetch(
				`/index.php/apps/openregister/api/objects/decision-tables/decision-table?key=${encodeURIComponent(key)}&_limit=50`,
				{
					headers: { Accept: 'application/json' },
				},
			)
			const body = await res.json().catch(() => null)
			const found = Array.isArray(body) ? body : (body?.results ?? [])
			return found.filter((row: any) => row.key === key)
		}, KEY)

		expect(tables, `tables under ${KEY}`).toHaveLength(1)
		const table = tables[0]
		expect(table.hitPolicy).toBe('UNIQUE')
		expect(table.inputs.map((input: any) => input.name)).toEqual([
			'behaviour',
			'consequence',
		])
		expect(table.outputs.map((output: any) => output.name)).toEqual([
			'interventionStep',
			'description',
		])

		const pairs = table.rules.map((rule: any) => rule.inputEntries.join(':'))
		for (const row of ['A', 'B', 'C', 'D']) {
			for (const column of ['1', '2', '3', '4']) {
				expect(
					pairs.filter((pair: string) => pair === `${row}:${column}`),
					`rule for ${row}${column}`,
				).toHaveLength(1)
			}
		}
		for (const rule of table.rules) {
			expect(
				String(rule.outputEntries?.[0] ?? '').trim(),
				`rule ${rule.id} names an intervention`,
			).not.toBe('')
		}
	})
})
