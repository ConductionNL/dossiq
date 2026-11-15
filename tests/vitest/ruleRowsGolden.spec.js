// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `ruleRows` (src/services/schemaRules.js): the
 * title, line and pill of each kind of rule row.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-the-rules-tab-lists-rules-as-rows
 */

import { describe, expect, it } from 'vitest'
import { ruleRows } from '../../src/services/schemaRules.js'

const UUID = '11111111-1111-4111-8111-111111111111'

describe('rule rows golden', () => {
	it.each([[null], ['x'], [undefined]])('reads %j as no rows', (rules) => {
		expect(ruleRows(rules)).toEqual([])
	})

	it('titles a rule by its property, else by its key or label in words', () => {
		const props = { feeAmount: { title: 'Fee', description: 'Charged once. Then more.' } }
		const kinds = { calculation: { description: 'Calculates a value' } }
		expect(ruleRows([
			{ id: 1, key: 'feeAmount', kind: 'calculation' },
			{ id: 'r2', key: 'startDate', kind: 'default', enabled: false },
			{ id: 'r3', key: 'other', kind: 'calculation', enabled: false },
			{ id: 'r4', label: 'Some Label', kind: 'unknown' },
		], props, kinds)).toEqual([
			{ id: '1', title: 'Fee', line: 'Charged once.', pill: 'calculated' },
			{ id: 'r2', title: 'Start date', line: 'default', pill: 'off' },
			{ id: 'r3', title: 'Other', line: 'Calculates a value', pill: 'calculated' },
			{ id: 'r4', title: 'Some label', line: 'unknown', pill: 'on' },
		])
	})

	it('titles a rule named by an id by its kind, with no line', () => {
		expect(ruleRows([{ id: UUID, kind: 'mapping' }], {}, { mapping: { description: 'Maps' } })).toEqual([
			{ id: UUID, title: 'Maps', line: '', pill: 'on' },
		])
	})

	it('reads rows without fields, and missing properties and kinds', () => {
		expect(ruleRows([null, {}, { key: 'a', kind: 'k' }], null, null)).toEqual([
			{ id: '', title: '', line: '', pill: 'on' },
			{ id: '', title: '', line: '', pill: 'on' },
			{ id: '', title: 'A', line: 'k', pill: 'on' },
		])
	})

	it('falls back from an empty property title and an empty description', () => {
		expect(ruleRows([{ id: 'x', key: 'my_field', kind: 'k' }], { my_field: { title: '', description: '' } }, {})).toEqual([
			{ id: 'x', title: 'My_field', line: 'k', pill: 'on' },
		])
	})
})
