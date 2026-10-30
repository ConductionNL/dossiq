/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Package journey: dso-intake (hydra ADR-118, decisions 185 and 198).
 *
 * dso-intake is a settings-only package: it writes no rows, it points
 * `dso_vergunningaanvraag_schema` at integriq's `dso_verzoek` schema, which
 * the installer finds by register and slug on this instance. It installs on
 * every upgrade. An administrator sees the result on the administration
 * overview, where dossiq's DSO intake check reports the schema it reads.
 *
 * The journey opens the overview the way an administrator would, reads the
 * check the overview reads, and follows the schema id to OpenRegister to
 * find integriq's dso_verzoek. It writes nothing.
 */

import { expect, test } from '@playwright/test'
import { PAGE_LOAD } from '../helpers/nav.ts'

const DSO_CHECK = 'OCA\\Dossiq\\SetupCheck\\DsoIntakeCheck'

test.describe('package dso-intake', () => {
	// @e2e openspec/changes/dso-intake-package/specs/vth-dso-integration/spec.md#scenario-fresh-install-with-integriq
	test("an administrator finds DSO intake reading integriq's dso_verzoek schema", async ({
		page,
	}) => {
		test.setTimeout(120_000)
		await page.goto('/index.php/settings/admin/overview', PAGE_LOAD)

		const check = await page.evaluate(async (checkClass: string) => {
			const w = window as unknown as { OC?: { requestToken?: string } }
			const token =
				w.OC?.requestToken
				|| document.head.getAttribute('data-requesttoken')
				|| ''
			const res = await fetch('/index.php/settings/ajax/checksetup', {
				headers: { Accept: 'application/json', requesttoken: token },
			})
			const body = await res.json().catch(() => null)
			for (const checks of Object.values(body ?? {}) as Array<
				Record<string, any>
			>) {
				if (checks?.[checkClass]) {
					return checks[checkClass]
				}
			}
			return null
		}, DSO_CHECK)

		expect(check, "the overview lists dossiq's DSO intake check").not.toBeNull()
		expect(check.severity, check.description).toBe('success')
		const schemaId = /reads schema (\S+?)\.?$/.exec(check.description ?? '')?.[1]
		expect(schemaId, check.description).toBeTruthy()

		const slug = await page.evaluate(async (id: string) => {
			const res = await fetch(
				`/index.php/apps/openregister/api/schemas/${id}`,
				{ headers: { Accept: 'application/json' } },
			)
			const body = await res.json().catch(() => null)
			return body?.slug ?? null
		}, schemaId as string)
		expect(slug).toBe('dso_verzoek')
	})
})
