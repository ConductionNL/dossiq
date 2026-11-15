/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqOrganisaties draws a header filter on every column. "Lopende zaken" and
 * "Laatste contact" are counted from related rows, so they filter through
 * those rows (`aggregate.filterable`). "Plaats" and DqContacten's "Woonplaats"
 * are fields inside the address object, which the library filters by their
 * dotted key; the schemas must describe that field.
 *
 * @spec exclude board layout: DqOrganisaties and DqContacten are the specification
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const register = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'dossiq_mock_register.json'), 'utf8'))
const schemas = register.components.schemas
const columnsOf = (id) => manifest.pages.find((entry) => entry.id === id).config.columns
const column = (id, key) => columnsOf(id).find((c) => c && c.key === key)

describe('DqOrganisaties header filters', () => {
	it('filters Lopende zaken and Laatste contact through their related rows', () => {
		expect(column('Organisations', 'openCases').aggregate).toMatchObject({ filterable: true, where: { requester: '@self.id' } })
		expect(column('Organisations', 'lastContact').aggregate).toMatchObject({ filterable: true, op: 'max', field: 'startTime', where: { contact: '@self.id' } })
	})

	it('filters Plaats on a field the company schema describes', () => {
		expect(column('Organisations', 'address.place')).toBeTruthy()
		expect(schemas.kvkCompany.properties.address.properties.place.type).toBe('string')
	})
})

describe('DqContacten header filters', () => {
	it('filters Woonplaats on a field the person schema describes', () => {
		expect(column('Contacts', 'residence.city')).toBeTruthy()
		expect(schemas.brpPerson.properties.residence.properties.city.type).toBe('string')
	})
})
