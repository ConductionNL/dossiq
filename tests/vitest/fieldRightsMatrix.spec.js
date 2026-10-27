/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaaktypeVelden "Wie wat ziet en invult, per rol en status": the field
 * rights as a matrix of fields by status, over the stored rules.
 *
 * @spec openspec/changes/field-rights-matrix-by-status/specs/case-types/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { fieldRightsMatrix } from '../../src/utils/fieldRightsMatrix.js'

const ROOT = path.resolve(__dirname, '../..')
const STATUSES = [
	{ id: 's1', name: 'Ontvangen', fieldRules: [{ rule: 'required', field: 'bouwkosten' }] },
	{ id: 's2', name: 'In behandeling' },
	{ id: 's3', name: 'Afgehandeld' },
]
const PROPERTIES = [
	{ name: 'bouwkosten', title: 'Bouwkosten' },
	{ name: 'soortBouwwerk', title: 'Soort bouwwerk', requiredAtStatus: { id: 's2' } },
	{ name: 'welstandsgebied', title: 'Welstandsgebied' },
	{ name: 'situatietekening', title: 'Situatietekening' },
]
const RULES = [
	{ field: 'bouwkosten', rule: 'readOnly', groups: ['Intaker'], statuses: ['s2', 's3'] },
	{ field: 'welstandsgebied', rule: 'hidden', groups: ['Intaker'] },
	{ field: 'welstandsgebied', rule: 'readOnly', groups: ['Iedereen'], statuses: ['s3'] },
	{ field: 'ignored', rule: 'readOnly', groups: [] },
]

describe('the field rights matrix', () => {
	const matrix = fieldRightsMatrix({ rules: RULES, statuses: STATUSES, properties: PROPERTIES })

	it('has a column per role, labelled with the statuses its rules name', () => {
		expect(matrix.columns.map((column) => column.label)).toEqual(['Intaker, in behandeling, afgehandeld', 'Iedereen, afgehandeld'])
	})

	it('has a row per field a rule names or a status requires, in declared order', () => {
		expect(matrix.rows.map((row) => row.label)).toEqual(['Bouwkosten', 'Soort bouwwerk', 'Welstandsgebied'])
	})

	it('says per role what the field is: a rule first, then required in one of its statuses, else edit', () => {
		const states = matrix.rows.map((row) => row.cells.map((cell) => cell.state))
		expect(states).toEqual([
			['readOnly', 'edit'],
			['required', 'edit'],
			['hidden', 'readOnly'],
		])
	})

	it('reads a status name in a language map and labels a group with its role types', () => {
		const labelled = fieldRightsMatrix({
			rules: [{ field: 'bouwkosten', rule: 'readOnly', groups: ['zuiddrecht-vergunningen'], statuses: ['s1'] }],
			statuses: [{ id: 's1', name: { nl: 'Ontvangen', en: 'Received' } }],
			properties: PROPERTIES,
			roleNames: { 'zuiddrecht-vergunningen': ['Intaker', 'Vergunningverlener'] },
		})
		expect(labelled.columns.map((column) => column.label)).toEqual(['Intaker / Vergunningverlener, ontvangen'])
	})

	it('labels a field with its title as text when the title is a language map', () => {
		const matrix = fieldRightsMatrix({
			rules: [{ field: 'bouwkosten', rule: 'readOnly', groups: ['g'] }],
			properties: [{ name: 'bouwkosten', title: { nl: 'Bouwkosten', en: 'Building costs' } }],
		})
		expect(matrix.rows.map((row) => row.label)).not.toContain('[object Object]')
		expect(['Bouwkosten', 'Building costs']).toContain(matrix.rows[0].label)
	})

	it('is empty for a case type without rules or statuses', () => {
		expect(fieldRightsMatrix({})).toEqual({ columns: [], rows: [] })
	})

	it('is the Fields tab card, labelled per role and status', () => {
		const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
		const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
		const card = page.configAppend.widgets.find((widget) => widget.id === 'case-type-field-rules')
		expect(card.type).toBe('CaseTypeFieldRightsWidget')
		expect(card.title).toBe('Who sees and fills what, per role and status')
		const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
		expect(nl[card.title]).toBe('Wie wat ziet en invult, per rol en status')
		expect(nl[card.subtitle]).toBe('Verborgen, verplicht of alleen lezen per rol en status')
	})

	it('declares the optional statuses on a field rule (caseType 1.41.0)', () => {
		const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/register.d/99-case-type-field-rule-statuses.json'), 'utf8'))
		const caseType = fragment.components.schemas.caseType
		expect(caseType.version).toBe('1.41.0')
		expect(caseType.properties.fieldRoleRules.items.properties.statuses.type).toBe('array')
	})
})
