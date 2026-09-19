/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-LEAF-101 to REQ-LEAF-106: the leaf declarations, read back from Open
 * Register rather than from the files that declared them.
 *
 * 🔴 THE FILES ARE NOT THE AUTHORITY AND THAT IS THE WHOLE POINT OF THIS
 * SUITE. `PrerequisitesTest`-style file assertions live in
 * `tests/Unit/LeafIntegrationDeclarationsTest.php` and they prove what dossiq
 * WROTE. What decides whether the Mail sidebar offers a button is what Open
 * Register STORED, and three things sit between the two:
 *
 *   1. `ImportHandler` skips an app import entirely when
 *      `version_compare(new, existing, '<=')` holds, so a declaration with the
 *      register version left alone reaches a fresh CI install and no existing
 *      instance at all. Every assertion here would still pass against the
 *      files and fail against the instance.
 *   2. `case` is union-merged with `register.d/dso-omgevingsloket.json`, so the
 *      stored `configuration` is not the block any one file holds.
 *   3. An UNKNOWN configuration key is dropped in silence. A key Open Register
 *      does not recognise never comes back and nothing anywhere says so.
 *
 * So every read below goes to `/apps/openregister/api/schemas`.
 *
 * 🔴 WHAT IT DELIBERATELY DOES NOT ASSERT. Not that a Deck board or a Talk
 * room can be created: both are the leaf's own behaviour, owned by
 * OpenRegister and nextcloud-vue, and asserting them here would be a second
 * copy of their tests that eventually disagrees with them. This asserts the
 * DECLARATIONS reached the store, which is the half dossiq owns.
 *
 * NOT RUN IN THIS LANE. No Playwright runs here. The suite is written and
 * tagged so the nightly run owns it.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

/** The register every dossiq schema lives in. */
const REGISTER = 'dossiq'

/**
 * Every schema of the dossiq register, as Open Register stored it.
 *
 * @param api A request context.
 *
 * @return The schemas, keyed by slug.
 */
async function storedSchemas(api: APIRequestContext): Promise<Record<string, any>> {
	const response = await api.get('/index.php/apps/openregister/api/schemas')
	expect(
		response.ok(),
		`the schemas must be readable, or nothing below means anything; got ${response.status()}`,
	).toBeTruthy()

	const body = await response.json()
	const rows = (body?.results ?? body?.schemas ?? body ?? []) as any[]
	expect(
		Array.isArray(rows) && rows.length > 0,
		'the schema list came back empty, which is a failed read and not a failed declaration',
	).toBeTruthy()

	const bySlug: Record<string, any> = {}
	for (const row of rows) {
		const slug = String(row?.slug ?? row?.title ?? '')
		if (slug !== '') {
			bySlug[slug] = row
		}
	}

	return bySlug
}

/**
 * One schema's linkedTypes, as stored.
 *
 * @param schemas The stored schemas.
 * @param slug    The schema slug.
 *
 * @return The declared types.
 */
function linkedTypes(schemas: Record<string, any>, slug: string): string[] {
	const schema = schemas[slug]
	expect(schema, `${slug} must exist in the ${REGISTER} register`).toBeTruthy()

	return (schema?.configuration?.linkedTypes ?? []) as string[]
}

test.describe('The leaf declarations, as Open Register stored them', () => {
	test.setTimeout(120_000)

	// @e2e openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#scenario-import-accepts-the-templates
	test('the two create-from-email templates survived the import', async ({
		request,
	}) => {
		const schemas = await storedSchemas(request)

		for (const slug of ['case', 'complaint']) {
			const template = schemas[slug]?.configuration?.mailObjectTemplate
			expect(
				template,
				`${slug} must carry a mailObjectTemplate after the import; an unknown configuration `
					+ 'key is dropped in silence, so its absence here is the whole failure mode',
			).toBeTruthy()
			expect(Object.keys(template).length).toBeGreaterThan(0)

			// The identity is never prefilled from a From header.
			expect(template.initiatorSourceId).toBeUndefined()
			expect(template.requester).toBeUndefined()
			expect(template.complainant).toBeUndefined()
		}

		expect(schemas.case.configuration.mailObjectTemplate.intakeChannel).toBe(
			'email',
		)
	})

	// @e2e openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#scenario-case-button-appears-in-the-mail-sidebar
	test('only case and complaint are offered as create targets', async ({
		request,
	}) => {
		const schemas = await storedSchemas(request)

		// The sidebar draws a create button for a schema in ITS list, and it
		// builds that list by filtering on linkedTypes.includes('mail'). A
		// template without the sentinel is a button that never appears.
		const offered = Object.entries(schemas)
			.filter(([, schema]) => {
				const types = (schema?.configuration?.linkedTypes ?? []) as string[]
				return (
					types.includes('mail')
					&& Boolean(schema?.configuration?.mailObjectTemplate)
				)
			})
			.map(([slug]) => slug)
			.sort()

		expect(offered).toEqual(['case', 'complaint'])
	})

	// @e2e openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#scenario-a-conversation-can-be-linked-to-a-case
	test('the case carries the talk and deck leaves', async ({ request }) => {
		const schemas = await storedSchemas(request)
		const types = linkedTypes(schemas, 'case')

		expect(types).toContain('talk')
		expect(types).toContain('deck')
		// The control: the leaves that were already there must still be there.
		// A union merge that replaced the block rather than merging it would
		// pass the two assertions above and quietly drop five.
		expect(types).toContain('mail')
		expect(types).toContain('calendar')
		expect(types).toContain('maps')
	})

	// @e2e openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#scenario-inspection-location-on-the-map
	test('the two inspection surfaces carry the maps leaf', async ({ request }) => {
		const schemas = await storedSchemas(request)

		expect(linkedTypes(schemas, 'inspectionChecklistRun')).toContain('maps')
		// fieldInspection had no `configuration` object at all, so this is the
		// one that proves a block CREATED by this change survived the import.
		expect(linkedTypes(schemas, 'fieldInspection')).toContain('maps')
		// And the checklist run kept what it already had.
		expect(linkedTypes(schemas, 'inspectionChecklistRun')).toContain('forms')
		expect(linkedTypes(schemas, 'inspectionChecklistRun')).toContain('photos')
	})

	// @e2e openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md#scenario-forms-app-absent
	test('a case type accepts and stores an intake form reference', async ({
		request,
	}) => {
		const schemas = await storedSchemas(request)
		const property = schemas.caseType?.properties?.intakeFormRef

		expect(
			property,
			'the case type must carry intakeFormRef, or nothing can bind a form',
		).toBeTruthy()
		expect(property.type).toBe('string')
		expect(
			(schemas.caseType?.required ?? []).includes('intakeFormRef'),
			'making it required would refuse every case type that already exists',
		).toBeFalsy()
	})
})
