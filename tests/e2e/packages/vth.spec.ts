/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Package journey: vth (hydra ADR-118, decisions 185 and 190).
 *
 * The package is `install: auto`: every install and upgrade writes it, so the
 * journey asks for nothing. It finds the six case types the way an
 * administrator would, opens handhavingszaak and sees both of its routes, and
 * reads the three inspection checklists of toezichtzaak bouw.
 *
 * A case type is found by identifier. On an instance where the former VTH
 * seeder ran, the installer adopted the rows that seeder wrote, and those
 * have no identifier: there the journey finds the case type by its title.
 * Either way there is exactly one, which is the point of adoption.
 *
 * Writes nothing.
 */

import { expect, type Page, test } from '@playwright/test'
import { dismissSupportDialog, PAGE_LOAD } from '../helpers/nav.ts'

const CASE_TYPES = [
	{ identifier: 'omgevingsvergunning-bouwactiviteit', title: 'Omgevingsvergunning Bouwactiviteit', flows: ['Aanvraag omgevingsvergunning'] },
	{ identifier: 'sloopmelding', title: 'Sloopmelding', flows: [] },
	{ identifier: 'toezichtzaak-bouw', title: 'Toezichtzaak Bouw', flows: ['Toezichtbezoek'] },
	{ identifier: 'toezichtzaak-milieu', title: 'Toezichtzaak Milieu', flows: [] },
	{ identifier: 'handhavingszaak', title: 'Handhavingszaak', flows: ['Handhavingstraject', 'Spoedig herstel (Awb 5:31)'] },
	{ identifier: 'invorderingszaak', title: 'Invorderingszaak', flows: [] },
]

const CHECKLISTS = [
	'Bouwtoezicht fase 1 - Fundering',
	'Bouwtoezicht fase 2 - Ruwbouw',
	'Bouwtoezicht fase 3 - Oplevering',
]

/**
 * Every row of one dossiq schema, through the objects API.
 *
 * @param page The page, on any dossiq route.
 * @param schema The schema slug.
 * @return The rows.
 */
async function rows(page: Page, schema: string): Promise<any[]> {
	return await page.evaluate(async (slug: string) => {
		const res = await fetch(`/index.php/apps/openregister/api/objects/dossiq/${slug}?_limit=500`, {
			headers: { Accept: 'application/json' },
		})
		const body = await res.json().catch(() => null)
		return Array.isArray(body) ? body : (body?.results ?? [])
	}, schema)
}

/**
 * The id of a row, wherever the API puts it.
 *
 * @param row The row.
 * @return The id.
 */
function idOf(row: any): string {
	return String(row?.id ?? row?.['@self']?.id ?? '')
}

test.describe('package vth', () => {
	// @e2e openspec/changes/vth-package/specs/vth-case-type-seed/spec.md#scenario-a-clean-install-has-the-six-vth-case-types
	// @e2e openspec/changes/vth-package/specs/vth-case-type-seed/spec.md#scenario-an-upgraded-instance-keeps-one-case-type-per-title
	// @e2e openspec/changes/vth-package/specs/vth-workflow-templates/spec.md#scenario-handhavingszaak-has-two-routes
	// @e2e openspec/changes/vth-package/specs/vth-case-type-seed/spec.md#scenario-toezichtzaak-bouw-has-its-three-checklists
	test('an administrator finds the six VTH case types, both handhaving routes and the checklists', async ({ page }) => {
		test.setTimeout(240_000)
		await page.goto('/index.php/apps/dossiq', PAGE_LOAD)
		await dismissSupportDialog(page)

		const caseTypes = await rows(page, 'caseType')
		const ids: Record<string, string> = {}
		for (const caseType of CASE_TYPES) {
			const found = caseTypes.filter((row) => row.identifier === caseType.identifier
				|| (!row.identifier && row.title === caseType.title))
			// One per case type: the install wrote it or adopted it, never both.
			expect(found.map(idOf), `case types for ${caseType.identifier}`).toHaveLength(1)
			ids[caseType.identifier] = idOf(found[0])
		}

		for (const caseType of CASE_TYPES.filter((entry) => entry.flows.length > 0)) {
			await page.goto(`/index.php/apps/dossiq/settings/case-types/${ids[caseType.identifier]}`, PAGE_LOAD)
			await dismissSupportDialog(page)
			const detail = page.locator('.cn-detail-page')
			await expect(detail).toBeVisible({ timeout: 30_000 })
			await expect(detail.getByText(caseType.title, { exact: true }).first()).toBeVisible()
			for (const flow of caseType.flows) {
				await expect(detail.getByText(flow).first(), `${caseType.identifier} shows the flow ${flow}`).toBeVisible({ timeout: 30_000 })
			}
		}

		const checklists = (await rows(page, 'inspectionChecklistTemplate'))
			.filter((row) => String(row.caseType ?? '') === ids['toezichtzaak-bouw'])
		for (const name of CHECKLISTS) {
			expect(checklists.filter((row) => row.name === name), `checklist ${name}`).toHaveLength(1)
		}
	})
})
