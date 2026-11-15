/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `feeRuleCalculation` (src/utils/feeRules.js):
 * every calculation, every fallback, and the currency and locale used.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-fee-table-names-each-rule-and-its-calculation
 */

import { describe, expect, it } from 'vitest'
import { feeRuleCalculation } from '../../src/utils/feeRules.js'

/**
 * A translate function that fills the placeholders and marks the key.
 *
 * @param {string} text The key.
 * @param {object} vars The values.
 * @return {string} The filled, marked text.
 */
function t(text, vars) {
	return '[' + text.replace(/\{(\w+)\}/g, (m, k) => (vars && vars[k] !== undefined ? vars[k] : m)) + ']'
}

/**
 * The calculation in words, with plain spaces.
 *
 * @param {object} rule The fee rule.
 * @param {string} locale The locale.
 * @return {string} The text.
 */
function fmt(rule, locale = 'nl-NL') {
	return feeRuleCalculation(rule, t, locale).replace(/\u00A0/g, ' ')
}

describe('feeRuleCalculation golden', () => {
	it.each([null, undefined, 0, ''])('is empty for %j', (rule) => {
		expect(feeRuleCalculation(rule, t, 'nl-NL')).toBe('')
	})

	it('reads a percentage with and without a minimum', () => {
		expect(fmt({ calculation: 'percentage', percentage: 2.355, minimumAmount: 125, baseProperty: 'bouwkosten', currency: 'USD' }))
			.toBe('[2,36 % of the bouwkosten, at least US$ 125,00]')
		expect(fmt({ calculation: 'percentage', percentage: 3 })).toBe('[3 % of the ]')
		expect(fmt({ calculation: 'percentage', percentage: 3, minimumAmount: 0, baseProperty: 'x' })).toBe('[3 % of the x]')
	})

	it('reads a unit price with its unit or base', () => {
		expect(fmt({ calculation: 'per_unit', unitPrice: 82.4, unitLabel: 'boom', baseProperty: 'b' })).toBe('[€ 82,40 per boom]')
		expect(fmt({ calculation: 'per_unit', unitPrice: 1, baseProperty: 'm2' })).toBe('[€ 1,00 per m2]')
		expect(fmt({ calculation: 'per_unit', unitPrice: 1 })).toBe('[€ 1,00 per ]')
	})

	it('reads a refund percentage', () => {
		expect(fmt({ calculation: 'refund_percentage', percentage: 75 })).toBe('[75 % refunded]')
	})

	it('reads a fixed amount, untranslated, and nothing without one', () => {
		expect(fmt({ amount: 245 })).toBe('€ 245,00')
		expect(fmt({ calculation: 'fixed', amount: '10', currency: 'GBP' })).toBe('£ 10,00')
		expect(fmt({ calculation: 'other', amount: 0 })).toBe('€ 0,00')
		expect(fmt({ amount: null })).toBe('')
		expect(fmt({})).toBe('')
		expect(fmt({ calculation: 'toString', amount: 5 })).toBe('€ 5,00')
	})

	it('formats in the given locale, or the default one without', () => {
		expect(fmt({ amount: 1234.5 }, 'en-US')).toBe('€1,234.50')
		expect(typeof feeRuleCalculation({ amount: 1 }, t, '')).toBe('string')
	})
})
