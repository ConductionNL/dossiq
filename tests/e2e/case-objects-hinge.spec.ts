/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-HINGE-01, REQ-HINGE-04 and REQ-HINGE-05: the Objects tab shows the
 * linked object's own title, the object's page names the cases it carries,
 * and a case location inherits the object's geometry with its provenance.
 *
 * WHY THIS IS AN E2E AND NOT ONLY A UNIT SUITE. Everything this change ships
 * is a DECLARATION, and a declaration is only true if OpenRegister reads it.
 * `caseObjectHinge.spec.js` asserts that the lens, the list surface, the name
 * field and the geometry declaration are well formed and name properties that
 * exist. It cannot assert that any of them was honoured, and OpenRegister's
 * HingeAnnotationValidator reports a malformed one as a log WARNING and then
 * carries on, so the failure mode this suite exists for is an empty column and
 * a green pipeline.
 *
 * Three things only a live instance can answer:
 *
 *  - A lens holds the path, not the value, so the proof is that renaming the
 *    object in its own register changes what the case shows while nothing on
 *    the case is written.
 *  - `objectNameField` is applied at SAVE time, so the proof that the reverse
 *    view lists cases by name is a read of `/referenced-by` after a save.
 *  - Inherited geometry comes from the referenced record's own `@self.geo` in
 *    one hop, so the proof is a feature carrying `_source: inherited` and
 *    `_through`, which no stub can produce.
 *
 * WHAT THIS SUITE DOES NOT DO. It seeds its own object, its own case and its
 * own link under RUN_PREFIX and touches nothing else. A case object is a link
 * row on somebody's real case if it is selected by anything broader, so
 * nothing here is ever selected by a filter broader than the run prefix.
 *
 * NOT RUN IN THIS LANE. There is no Playwright host here; this suite is
 * written and tagged against the scenarios and runs in the nightly.
 */

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	REGISTER,
	RUN_PREFIX,
	seedCase,
	showObject,
} from './helpers/fixtures.ts'
import { PAGE_LOAD } from './helpers/nav.ts'

/** OpenRegister's object API, which owns every endpoint under test. */
const OR = '/index.php/apps/openregister/api'

/** The seeded case, the seeded object and the link between them. */
let caseId = ''
let objectUuid = ''
let linkId = ''

/** The CSRF request-token for every write in this suite. */
let token = ''

test.describe('Objects as the hinge between cases', () => {
	test.beforeAll(async ({ request }) => {
		token = await getRequestToken(request)

		// The thing the case is about: an object with a title, a status and a
		// point, in the mock register rather than in dossiq's own.
		const target = await createObject(request, token, REGISTER, 'object', {
			name: `${RUN_PREFIX} Pand Kerkstraat 1`,
			status: 'in gebruik',
			'@self': {
				geo: {
					type: 'FeatureCollection',
					features: [
						{
							type: 'Feature',
							geometry: { type: 'Point', coordinates: [4.9, 52.37] },
							properties: { _purpose: 'address' },
						},
					],
				},
			},
		})
		objectUuid = objectId(target)

		caseId = objectId(
			await seedCase(request, token, {
				title: `${RUN_PREFIX} Handhaving Kerkstraat`,
			}),
		)

		const link = await createObject(request, token, 'dossiq', 'caseObject', {
			case: caseId,
			objectType: 'pand',
			objectIdentification: '0363010000000001',
			objectUrl: `${OR}/objects/${REGISTER}/object/${objectUuid}`,
		})
		linkId = objectId(link)
	})

	test.afterAll(async ({ request }) => {
		await cleanupRunObjects(request, token)
	})

	test('shows the linked object title on the case, and follows a rename', async ({
		request,
		page,
	}) => {
		await page.goto(`/index.php/apps/dossiq/#/cases/${caseId}`, PAGE_LOAD)
		await page.getByRole('tab', { name: 'Related' }).click()
		await expect(page.getByText(`${RUN_PREFIX} Pand Kerkstraat 1`)).toBeVisible()

		// A lens holds the path. Renaming the object in its own register moves
		// what the case shows, and writes nothing on the case.
		const before = await showObject(request, 'dossiq', 'caseObject', linkId)
		await createObject(
			request,
			token,
			REGISTER,
			'object',
			{
				name: `${RUN_PREFIX} Pand Kerkstraat 1a`,
				status: 'in gebruik',
			},
			objectUuid,
		)

		await page.reload(PAGE_LOAD)
		await page.getByRole('tab', { name: 'Related' }).click()
		await expect(
			page.getByText(`${RUN_PREFIX} Pand Kerkstraat 1a`),
		).toBeVisible()

		const after = await showObject(request, 'dossiq', 'caseObject', linkId)
		expect(after['@self'].updated).toBe(before['@self'].updated)
	})

	test('refuses a write that names a lens property', async ({ request }) => {
		const read = await showObject(request, 'dossiq', 'caseObject', linkId)
		const response = await request.put(
			`${OR}/objects/dossiq/caseObject/${linkId}`,
			{
				headers: { requesttoken: token },
				data: read,
			},
		)

		expect(response.status()).toBe(400)
		expect(await response.text()).toContain('objectTitle')
	})

	test('names the case in the reverse view on the object, from the first save', async ({
		request,
	}) => {
		// The link row was created in beforeAll and has not been edited since, so
		// this also answers the ordering question: the name is written by the save
		// that creates the record, not by a later one. A calculated mirror of the
		// case title would be empty here, because metadata is hydrated before the
		// event that materialises a calculation.
		const response = await request.get(
			`${OR}/objects/${REGISTER}/object/${objectUuid}/referenced-by`,
		)
		expect(response.ok()).toBe(true)

		const body = await response.json()
		const group = body.groups.find((entry) => entry.schema.slug === 'caseObject')
		expect(group).toBeTruthy()
		expect(group.results.map((row) => row.title)).toContain(
			`${RUN_PREFIX} Handhaving Kerkstraat`,
		)
	})

	test('answers the declared list surface for case objects', async ({
		request,
	}) => {
		const response = await request.get(
			`${OR}/schemas/caseObject/list-presentation`,
		)
		expect(response.ok()).toBe(true)

		const body = await response.json()
		expect(body.declared).toBe(true)
		expect(body.columns.map((column) => column.property)).toEqual([
			'objectType',
			'objectIdentification',
			'description',
		])
	})

	test('inherits the object geometry onto the case location, marked inherited', async ({
		request,
	}) => {
		const location = await createObject(
			request,
			token,
			'dossiq',
			'case-location',
			{
				case: caseId,
				label: `${RUN_PREFIX} Inspectielocatie`,
				source: 'bag',
				linkedObject: `${OR}/objects/${REGISTER}/object/${objectUuid}`,
			},
		)

		const response = await request.get(
			`${OR}/objects/dossiq/case-location/${objectId(location)}/geo-features`,
		)
		expect(response.ok()).toBe(true)

		const body = await response.json()
		const inherited = body.features.find(
			(feature) => feature.properties._source === 'inherited',
		)
		expect(inherited).toBeTruthy()
		expect(inherited.properties._through).toBe('linkedObject')
		expect(inherited.properties._fromObject).toBe(objectUuid)
		expect(inherited.properties._relationLabel).toBe('Linked object')
	})
})
