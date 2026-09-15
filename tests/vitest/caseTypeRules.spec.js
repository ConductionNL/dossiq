// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The rules the platform holds for the case, read rather than kept.
 *
 * Two assertions here guard instructions the engine's own handover repeats.
 *
 *  - the kinds come from the vocabulary endpoint. A consumer that copied the
 *    list renders a blank the day the engine adds a kind, and a blank cell
 *    reads as "this rule has no kind", which is never true;
 *  - a rule is addressed by its id and never by its position. The order is a
 *    property of the pipeline and moves the day a kind is added, so a url built
 *    from a row index would silently try the wrong rule.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	isRuleVocabulary,
	kindsByName,
	ruleEvaluateUrl,
	ruleInventoryUrl,
	traceOf,
} from '../../src/services/schemaRules.js'

describe('the vocabulary the engine publishes', () => {
	it('recognises the three closed sets', () => {
		expect(isRuleVocabulary({ kinds: [], verdicts: [], actions: [] })).toBe(true)
		expect(isRuleVocabulary({ kinds: [] })).toBe(false)
		expect(isRuleVocabulary(null)).toBe(false)
	})

	it('carries a kind this release predates', () => {
		const kinds = kindsByName({
			kinds: [{ kind: 'somethingNew', description: 'Decides something new.' }],
			verdicts: [],
			actions: [],
		})

		expect(kinds.somethingNew.description).toBe('Decides something new.')
	})

	it('answers nothing rather than guessing when the endpoint refused', () => {
		expect(kindsByName({})).toEqual({})
	})
})

describe('addressing one rule', () => {
	it('puts the whole rule id in the path, colons and all', () => {
		const url = ruleEvaluateUrl('case', 'stateFieldRule:case:0c4b-uuid')

		// The id is `<kind>:<schemaSlug>:<key>`, derived from three facts every
		// time. Nothing allocates it, so nothing may rewrite it either.
		expect(decodeURIComponent(url)).toContain('stateFieldRule:case:0c4b-uuid')
		expect(url).toContain('/evaluate')
	})

	it('reads the inventory of one schema by slug', () => {
		expect(ruleInventoryUrl('case')).toContain('/schemas/case/rules')
	})
})

describe('what a trial decided', () => {
	it('keeps the operand and the value beside the verdict', () => {
		const trace = traceOf({
			ok: true,
			trace: {
				verdict: 'no_match',
				operand: 'object.bedrag',
				operandValue: '400',
				message: '',
			},
		})

		expect(trace.verdict).toBe('no_match')
		expect(trace.operand).toBe('object.bedrag')
		expect(trace.operandValue).toBe('400')
	})

	it('falls back to the sentence a refusal carries when there is no trace', () => {
		const trace = traceOf({ ok: false, error: { message: 'That rule is not on this schema.' } })

		expect(trace.message).toBe('That rule is not on this schema.')
		expect(trace.verdict).toBe('')
	})
})
