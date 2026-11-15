/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The VTH settings' fee table names each shillinq fee rule, its basis and
 * how its amount is worked out (board DqVthInstellingen, Legesregels).
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-fee-table-names-each-rule-and-its-calculation
 */
import { describe, expect, it } from 'vitest'
import { feeRuleBasis, feeRuleCalculation, feeRuleName } from '../../src/utils/feeRules.js'

const t = (text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && vars[k] !== undefined ? vars[k] : m))

describe('fee rules in the VTH settings', () => {
	it('names the rule, falling back to its type value', () => {
		expect(feeRuleName({ name: 'Kappen', typeValue: 'omgevingsvergunning' })).toBe('Kappen')
		expect(feeRuleName({ typeValue: 'omgevingsvergunning' })).toBe('omgevingsvergunning')
	})

	it('cites the regulation and article', () => {
		expect(feeRuleBasis({ legalBasis: { regulation: 'Legesverordening 2026', article: '2.3.1' } })).toBe('Legesverordening 2026, 2.3.1')
	})

	it('says how each kind of rule works the amount out', () => {
		const fmt = (rule) => feeRuleCalculation(rule, t, 'nl-NL').replace(/\u00A0/g, ' ')
		expect(fmt({ calculation: 'percentage', percentage: 2.35, minimumAmount: 125, baseProperty: 'bouwkosten' })).toBe('2,35 % of the bouwkosten, at least € 125,00')
		expect(fmt({ calculation: 'per_unit', unitPrice: 82.4, unitLabel: 'boom' })).toBe('€ 82,40 per boom')
		expect(fmt({ calculation: 'refund_percentage', percentage: 75 })).toBe('75 % refunded')
		expect(fmt({ amount: 245 })).toBe('€ 245,00')
	})
})
