// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The vocabulary the status field-rule editor offers: empty condition rows,
 * what survives a save when the form holds no list, and the reader's words.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	CONDITION_KINDS,
	conditionKindLabels,
	FIELD_RULES,
	fieldRuleLabels,
	pruneFieldRules,
	ruleCondition,
} from '../../src/utils/statusFieldRules.js'

describe('an empty condition row', () => {
	it('defaults to asking whether a field is filled in', () => {
		expect(ruleCondition()).toEqual({
			kind: 'fieldPresent',
			field: '',
			value: '',
			documentType: '',
		})
	})

	it('keeps a kind the platform knows', () => {
		expect(ruleCondition('fieldEquals').kind).toBe('fieldEquals')
		expect(ruleCondition('documentPresent').kind).toBe('documentPresent')
	})

	it('falls back to fieldPresent for a kind it does not know', () => {
		expect(ruleCondition('fieldMissing').kind).toBe('fieldPresent')
	})
})

describe('pruning what the form holds', () => {
	it('saves nothing when the form holds no list', () => {
		expect(pruneFieldRules(undefined)).toEqual([])
		expect(pruneFieldRules(null)).toEqual([])
		expect(pruneFieldRules({ rule: 'required', field: 'motivering' })).toEqual([])
	})

	it('keeps a condition the platform reads beside the rule', () => {
		expect(
			pruneFieldRules([
				{
					rule: 'readOnly',
					field: 'besluit',
					groups: ['juristen', ' juristen ', ''],
					message: ' Vastgelegd ',
					condition: { kind: 'fieldEquals', field: 'status', value: 'besloten' },
				},
			]),
		).toEqual([
			{
				rule: 'readOnly',
				field: 'besluit',
				groups: ['juristen'],
				message: 'Vastgelegd',
				condition: { kind: 'fieldEquals', field: 'status', value: 'besloten', documentType: '' },
			},
		])
	})
})

describe('the reader\'s words', () => {
	it('names every field rule the editor offers', () => {
		const labels = fieldRuleLabels()

		expect(Object.keys(labels)).toEqual(FIELD_RULES)
		expect(labels).toEqual({
			required: 'Must be filled in',
			readOnly: 'Cannot be changed',
			hidden: 'Not shown',
		})
	})

	it('names every condition kind the editor offers', () => {
		const labels = conditionKindLabels()

		expect(Object.keys(labels)).toEqual(CONDITION_KINDS)
		expect(labels).toEqual({
			fieldPresent: 'a field is filled in',
			fieldEquals: 'a field has a value',
			documentPresent: 'a document is on the case',
		})
	})
})
