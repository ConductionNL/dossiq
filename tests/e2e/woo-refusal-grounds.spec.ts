/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-refusal-grounds-list: the settled Woo refusal grounds, maintained by an
 * administrator on a settings page, with every change in the history.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	updateObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** The schema the grounds live in. */
const SCHEMA = 'wooRefusalGround'

test.describe('Woo refusal grounds', () => {
	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, await getRequestToken(request))
	})

	// @e2e openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#scenario-the-seed-is-the-settled-list
	// @e2e openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#scenario-a-narrower-ground-sits-under-a-broader-one
	test('the seeded list carries 5.1.2.e under 5.1.2 under 5.1', async ({
		request,
	}) => {
		const rows = await listObjects(request, SCHEMA, { code: '5.1.2.e' })
		expect(rows.length, 'the seed carries 5.1.2.e').toBe(1)
		expect(rows[0].parent).toBe('5.1.2')

		const broader = await listObjects(request, SCHEMA, { code: '5.1.2' })
		expect(broader[0].parent).toBe('5.1')
		expect(broader[0].citable).toBe(false)
	})

	// @e2e openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#scenario-an-administrator-adds-a-narrower-ground
	// @e2e openspec/changes/woo-refusal-grounds-list/specs/woo-refusal-grounds/spec.md#scenario-a-label-edit-is-in-the-history
	test('an admin adds 5.1.2.e.1 under 5.1.2.e, edits its label, and reads both in the history', async ({
		page,
		request,
	}) => {
		const token = await getRequestToken(request)
		const created = await createObject(request, token, SCHEMA, {
			code: '5.1.2.e.1',
			article: '5.1',
			paragraph: '2',
			letter: 'e',
			label: 'Namen van medewerkers',
			description:
				'Een nadere grond onder de eerbiediging van de persoonlijke levenssfeer.',
			parent: '5.1.2.e',
			status: 'active',
			legalSource:
				'https://wetten.overheid.nl/jci1.3:c:BWBR0045754&hoofdstuk=5&artikel=5.1&lid=2&onderdeel=e',
			kind: 'relative',
			citable: true,
		})
		const id = objectId(created)
		await updateObject(request, token, SCHEMA, id, {
			label: 'Namen en functies van medewerkers',
		})

		await page.goto(
			'/index.php/apps/dossiq/settings/woo-refusal-grounds',
			PAGE_LOAD,
		)
		await expect(page.getByText('5.1.2.e.1')).toBeVisible()

		const trail = await request.get(
			`/index.php/apps/openregister/api/objects/dossiq/${SCHEMA}/${id}/audit-trails`,
		)
		expect(trail.ok()).toBeTruthy()
		const entries = JSON.stringify(await trail.json())
		expect(entries).toContain('Namen van medewerkers')
		expect(entries).toContain('Namen en functies van medewerkers')
	})
})
