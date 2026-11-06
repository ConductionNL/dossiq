/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeVelden's Moedertype card: Erft van, Geërfde velden "6 van 14"
 * and Overschreven, read from the case type's blueprint, which counts them.
 * A type without a parent keeps "erft van geen moedertype". As the board:
 * "Erft van" links to the parent, and no overridden field reads "Geen".
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const page = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const parent = page.config.sideColumn.find((card) => card.id === 'side-case-type-parent')
const nl = read('l10n/nl.json').translations

describe('the parent type card (DqZaaktypeVelden)', () => {
	it('reads the blueprint', () => {
		expect(parent.content.endpointSource.url).toBe('/apps/dossiq/api/case-types/@objectId/blueprint')
	})

	it('draws Erft van, Geërfde velden and Overschreven, only for a type with a parent', () => {
		const fields = parent.content.fields
		expect(fields.map((field) => nl[field.label])).toEqual(['Erft van', 'Geërfde velden', 'Overschreven'])
		fields.forEach((field) => expect(field.when).toEqual({ field: 'caseType.parentCaseType', op: 'notEmpty' }))
		expect(fields[1].parts.map((part) => part.path || part.text)).toEqual(['counts.propertyDefinitions.inherited', 'of', 'counts.propertyDefinitions.total'])
		expect(fields[2].path).toBe('counts.propertyDefinitions.overridden')
		expect(nl[fields[2].map['0']]).toBe('Geen')
		expect(fields[0].route).toEqual({ name: 'CaseTypeDetail', params: { id: '@source.caseType.parentCaseType' } })
		expect(nl[parent.content.emptyText]).toBeTruthy()
	})
})
