/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The catalogue page (DqCatalogus): its note is said once, after the
 * breadcrumb, and the object types list is a widget that reads its
 * endpoint.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(
	path.resolve(__dirname, '../../src/manifest.d/64-catalogue.json'),
	'utf8',
))
const page = fragment.pages.find((entry) => entry.id === 'Catalogue')
const widget = (id) => page.config.widgets.find((entry) => entry.id === id)

describe('the catalogue page', () => {
	it('says what it holds once, after the breadcrumb', () => {
		expect(page.config.headerMeta).toBe('case types, attributes, templates and object types in folders')
		expect(page.config.description).toBeUndefined()
	})

	it('lists the object types with the table widget that reads an endpoint', () => {
		// The legacy `table` type renders the OpenRegister object list, which
		// ignores `endpointSource` and so always read empty.
		const objectTypes = widget('catalogue-object-types')
		expect(objectTypes.type).toBe('object-table')
		expect(objectTypes.content.endpointSource).toMatchObject({
			url: '/apps/openregister/api/schemas',
			responsePath: 'results',
		})
	})
})
