/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqCatalogus draws a count beside each folder: Zaaktypen, Attributen,
 * Sjablonen and Objecttypen each say how many they hold, counted from the
 * schema or endpoint the folder's own page lists.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const catalogue = read('src/manifest.d/64-catalogue.json').pages[0].config
const pages = read('src/manifest.json').pages
const items = catalogue.widgets.find((widget) => widget.id === 'catalogue-folders').content.items
const objectTypes = catalogue.widgets.find((widget) => widget.id === 'catalogue-object-types').content.endpointSource
const item = (label) => items.find((entry) => entry.label === label)
const listed = (route) => pages.find((page) => page.route === route).config

describe('the catalogue folder counts (DqCatalogus)', () => {
	it('counts the case types', () => {
		expect(item('Case types').countFrom).toEqual({ register: 'dossiq', schema: 'caseType' })
	})

	it.each([['Attributes', '/settings/attributes'], ['Templates', '/settings/workflow-definitions']])('counts %s from the schema its page lists', (label, route) => {
		const config = listed(route)
		expect(item(label).countFrom).toEqual({ register: config.register, schema: config.schema })
	})

	it('counts the object types from the endpoint the table reads', () => {
		expect(item('Object types').countFrom).toEqual({ url: objectTypes.url, responsePath: objectTypes.responsePath })
	})
})
