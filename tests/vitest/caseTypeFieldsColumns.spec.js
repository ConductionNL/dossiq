/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeVelden's Velden table reads Veld, Soort, Invullen: the field, the
 * kind of answer in words and how it is filled in, through the app's cell
 * formatters. "In de lijst" has no filter, as drawn: its funnel took the
 * width the field names need.
 *
 * @spec openspec/changes/case-type-fields-columns/specs/case-types/spec.md
 * @spec openspec/changes/case-type-fields-in-list-no-filter/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ collections: {}, objectTypeRegistry: {} }) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const page = readJson('src', 'manifest.json').pages.find((entry) => entry.id === 'CaseTypeDetail')
const editor = page.config.widgets.find((entry) => entry.id === 'case-type-fields-editor')
const nl = readJson('l10n', 'nl.json').translations

describe('the Velden table (DqZaaktypeVelden)', () => {
	it('reads Field, Kind, Fill in', () => {
		expect(editor.content.columns).toEqual([
			{ key: 'name', label: 'Field', cellClass: 'cn-cell--wrap', formatter: 'fieldTitle' },
			{ key: 'propertyType', label: 'Kind', formatter: 'fieldKind' },
			{ key: 'isRequired', label: 'Fill in', formatter: 'fieldFill' },
			{ key: 'origin', label: 'Origin', formatter: 'fieldOrigin', widget: 'badge', emptyValue: 'own', widgetProps: { colorMap: { own: 'default', shared: 'info', inherited: 'purple' } } },
			{ key: 'caseType', label: 'In the list', formatter: 'fieldInCaseList', filterable: false },
		])
		expect(editor.content.columns.map((col) => nl[col.label])).toEqual(['Veld', 'Soort', 'Invullen', 'Herkomst', 'In de lijst'])
	})

	it('has the formatters it names', async () => {
		const formatters = (await import('../../src/services/formatters.js')).default
		expect(formatters.fieldKind('enum', { propertyType: 'enum', enumValues: ['a', 'b'] })).toBeTruthy()
		expect(formatters.fieldFill(true, { isRequired: true })).toBeTruthy()
	})

	it('adds the inherited fields read only from the blueprint', () => {
		expect(editor.content.readOnlyRows).toEqual({ url: '/apps/dossiq/api/case-types/@objectId/blueprint', responsePath: 'propertyDefinitions', where: { origin: ['inherited', 'shared'] } })
	})

	it('has the origin and case list formatters, the latter reading the page record', async () => {
		const formatters = (await import('../../src/services/formatters.js')).default
		expect(formatters.fieldOrigin('x', { origin: 'inherited', originCaseTypeTitle: 'Omgevingsvergunning' })).toBeTruthy()
		expect(formatters.fieldInCaseList('x', { name: 'a' }, {}, undefined, { object: { 'x-index': { columns: ['a'] } } })).toBe('Yes')
	})

	it('keeps the category in the form', () => {
		expect(editor.content.formIncludeFields).toContain('category')
	})
})
