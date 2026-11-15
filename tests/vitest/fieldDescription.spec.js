/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeVelden's Velden table: what kind of answer a field holds, in
 * words ("Keuzelijst, 6 opties", "Ja of nee, berekend"), and how it is
 * filled in (Verplicht, Optioneel, Alleen lezen).
 *
 * @spec openspec/changes/case-type-fields-columns/specs/case-types/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)),
	translatePlural: (app, one, other, count) => (count === 1 ? one : other).replace('%n', String(count)),
}))

const { fieldFill, fieldKind, fieldTitle } = await import('../../src/utils/fieldDescription.js')

describe('fieldKind', () => {
	it('names the kind of answer', () => {
		expect(fieldKind({ propertyType: 'string' })).toBe('Text')
		expect(fieldKind({ propertyType: 'number' })).toBe('Number')
		expect(fieldKind({ propertyType: 'date' })).toBe('Date')
		expect(fieldKind({ propertyType: 'boolean' })).toBe('Yes or no')
	})

	it('counts the options of a choice list', () => {
		expect(fieldKind({ propertyType: 'enum', enumValues: ['a', 'b', 'c', 'd', 'e', 'f'] })).toBe('Choice list, 6 options')
		expect(fieldKind({ propertyType: 'string', enumValues: ['a'] })).toBe('Choice list, 1 option')
	})

	it('says where a value comes from and that it is calculated', () => {
		expect(fieldKind({ propertyType: 'string', propertySource: 'bag' })).toBe('Text from BAG')
		expect(fieldKind({ propertyType: 'boolean', calculation: 'x > 1' })).toBe('Yes or no, calculated')
	})

	it('keeps an unknown type as written', () => {
		expect(fieldKind({ propertyType: 'NcTalk' })).toBe('NcTalk')
		expect(fieldKind({})).toBe('')
	})
})

describe('fieldFill', () => {
	it('says required, optional or read only', () => {
		expect(fieldFill({ isRequired: true })).toBe('Required')
		expect(fieldFill({ isRequired: false })).toBe('Optional')
		expect(fieldFill({})).toBe('Optional')
		expect(fieldFill({ isRequired: true, calculation: 'x' })).toBe('Read only')
	})
})

describe('fieldOrigin', () => {
	it('names an own, an inherited and a shared field', async () => {
		const { fieldOrigin } = await import('../../src/utils/fieldDescription.js')
		expect(fieldOrigin({ name: 'bouwkosten' })).toBe('Own field')
		expect(fieldOrigin({ origin: 'own' })).toBe('Own field')
		expect(fieldOrigin({ origin: 'inherited', originCaseTypeTitle: 'Omgevingsvergunning' })).toBe('Inherited from Omgevingsvergunning')
		expect(fieldOrigin({ origin: 'inherited' })).toBe('Inherited')
		expect(fieldOrigin({ origin: 'shared' })).toBe('Shared')
	})
})

describe('fieldInCaseList', () => {
	it('says whether the case type shows the field as a case list column', async () => {
		const { fieldInCaseList } = await import('../../src/utils/fieldDescription.js')
		const caseType = { 'x-index': { columns: ['bouwkosten', 'status'] } }
		expect(fieldInCaseList({ name: 'bouwkosten' }, caseType)).toBe('Yes')
		expect(fieldInCaseList({ name: 'welstandsgebied' }, caseType)).toBe('No')
		expect(fieldInCaseList({ name: 'bouwkosten' }, {})).toBe('No')
		expect(fieldInCaseList({ name: 'bouwkosten' }, null)).toBe('No')
	})
})

describe('fieldTitle', () => {
	it('reads the title in the reader\'s language, else the name', () => {
		expect(fieldTitle({ name: 'bouwkosten', title: { nl: 'Bouwkosten', en: 'Building costs' } })).toBe('Bouwkosten')
		expect(fieldTitle({ name: 'bouwkosten', title: 'Bouwkosten' })).toBe('Bouwkosten')
		expect(fieldTitle({ name: 'bouwkosten' })).toBe('bouwkosten')
		expect(fieldTitle({ name: 'bouwkosten', title: '  ' })).toBe('bouwkosten')
	})
})
