// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A shillinq fee rule (FeeSchedule) in words, for the fee table on the VTH
// settings: its name, where it comes from, and how the amount is worked out.

/**
 * An amount of money in the reader's locale.
 *
 * @param {number} value The amount.
 * @param {string} currency The ISO 4217 code.
 * @param {string} locale The locale.
 * @return {string} The amount, e.g. "€ 125,00".
 */
function money(value, currency, locale) {
	return new Intl.NumberFormat(locale || undefined, { style: 'currency', currency: currency || 'EUR' }).format(Number(value))
}

/**
 * A number in the reader's locale, with the decimals it has.
 *
 * @param {number} value The number.
 * @param {string} locale The locale.
 * @return {string} The number, e.g. "2,35".
 */
function decimal(value, locale) {
	return new Intl.NumberFormat(locale || undefined, { maximumFractionDigits: 2 }).format(Number(value))
}

/**
 * The rule's name, or its type value when it has none.
 *
 * @param {object} rule The fee schedule.
 * @return {string} The name.
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-fee-table-names-each-rule-and-its-calculation
 */
export function feeRuleName(rule) {
	return (rule && (rule.name || rule.typeValue)) || ''
}

/**
 * The regulation and article a rule comes from.
 *
 * @param {object} rule The fee schedule.
 * @return {string} E.g. "Legesverordening 2026, 2.3.1".
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-fee-table-names-each-rule-and-its-calculation
 */
export function feeRuleBasis(rule) {
	const basis = (rule && rule.legalBasis) || {}
	return [basis.regulation, basis.article].filter(Boolean).join(', ')
}

/**
 * How the rule works the amount out, in words.
 *
 * @param {object} rule The fee schedule.
 * @param {(text: string, vars?: object) => string} t Translate in the app's domain.
 * @param {string} locale The reader's locale.
 * @return {string} E.g. "2,35 % of the bouwkosten, at least € 125,00".
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-fee-table-names-each-rule-and-its-calculation
 */
export function feeRuleCalculation(rule, t, locale) {
	if (!rule) {
		return ''
	}
	const currency = rule.currency || 'EUR'
	switch (rule.calculation || 'fixed') {
	case 'percentage':
		return rule.minimumAmount
			? t('{percentage} % of the {base}, at least {minimum}', { percentage: decimal(rule.percentage, locale), base: rule.baseProperty || '', minimum: money(rule.minimumAmount, currency, locale) })
			: t('{percentage} % of the {base}', { percentage: decimal(rule.percentage, locale), base: rule.baseProperty || '' })
	case 'per_unit':
		return t('{price} per {unit}', { price: money(rule.unitPrice, currency, locale), unit: rule.unitLabel || rule.baseProperty || '' })
	case 'refund_percentage':
		return t('{percentage} % refunded', { percentage: decimal(rule.percentage, locale) })
	default:
		return rule.amount === undefined || rule.amount === null ? '' : money(rule.amount, currency, locale)
	}
}
