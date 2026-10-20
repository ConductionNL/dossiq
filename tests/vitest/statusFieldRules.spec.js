// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * What a status asks of the fields, as the case reads it. The status is
 * edited through the library's object list, which writes only the keys a
 * save changed (tests/vitest/caseTypeStatusFieldLists.spec.js).
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	fieldRule,
	normaliseGroups,
	pruneFieldRules,
	stateFieldRefusal,
} from '../../src/utils/statusFieldRules.js'

describe('the rules a status declares', () => {
	it('keeps a rule that names a field', () => {
		const rules = pruneFieldRules([
			{ rule: 'required', field: ' motivering ', groups: [], message: '' },
		])

		expect(rules).toEqual([
			{ rule: 'required', field: 'motivering', groups: [], message: '' },
		])
	})

	it('drops a row that names no field, so hesitating does not break the publish', () => {
		expect(pruneFieldRules([fieldRule('hidden')])).toEqual([])
	})

	it('drops a rule whose kind the platform does not read', () => {
		// `readonly` is not refused by anything: it simply never matches, and
		// the administrator goes on believing the field is locked.
		expect(
			pruneFieldRules([{ rule: 'readonly', field: 'confidentiality' }]),
		).toEqual([])
	})

	it('keeps a condition written in the vocabulary derivedWhen uses', () => {
		const rules = pruneFieldRules([
			{
				rule: 'required',
				field: 'motivering',
				condition: {
					kind: 'fieldEquals',
					field: 'outcome',
					value: 'refused',
				},
			},
		])

		expect(rules[0].condition).toEqual({
			kind: 'fieldEquals',
			field: 'outcome',
			value: 'refused',
			documentType: '',
		})
	})

	it('drops a condition whose kind is not one of the three', () => {
		const rules = pruneFieldRules([
			{
				rule: 'required',
				field: 'motivering',
				condition: { kind: 'invented' },
			},
		])

		expect(rules[0].condition).toBeUndefined()
	})

	it('drops the blank and repeated group names', () => {
		expect(normaliseGroups(['a', ' a ', '', null, 'b'])).toEqual(['a', 'b'])
	})
})

describe('a save the state rules refused', () => {
	it('reads the code and the sentence one level down, where they arrive', () => {
		const refusal = stateFieldRefusal({
			error: 'Hook stopped the save',
			errors: {
				code: 'state-field-required',
				field: 'motivering',
				state: 's2',
				message: 'Fill in the motivation before deciding.',
			},
		})

		expect(refusal.code).toBe('state-field-required')
		expect(refusal.field).toBe('motivering')
		// Never re-translated here: the sentence is the one the administrator
		// wrote on the rule, in their own language.
		expect(refusal.message).toBe('Fill in the motivation before deciding.')
	})

	it('names the clause a state condition refused on', () => {
		const refusal = stateFieldRefusal({
			errors: {
				code: 'lifecycle-state-entry-refused',
				clause: 'object.besluit',
				state: 'besloten',
				message: 'A decision is needed first.',
			},
		})

		expect(refusal.field).toBe('object.besluit')
	})

	it('leaves every other refusal to whoever else reads it', () => {
		expect(stateFieldRefusal({ code: 'reason_required' })).toBeNull()
		expect(stateFieldRefusal(null)).toBeNull()
	})
})
