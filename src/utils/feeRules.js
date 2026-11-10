// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A shillinq fee rule (FeeSchedule) in words, for the fee table on the VTH
// settings: its name, where it comes from, and how the amount is worked out.

/**
 * An amount of money in the reader's locale.
 *
 * @param {number} value The amount.
 * @param {{currency: string, locale: string}} format The ISO 4217 code and the locale.
 * @return {string} The amount, e.g. "€ 125,00".
 */
function money(value, format) {
	return new Intl.NumberFormat(format.locale || undefined, { style: 'currency', currency: format.currency || 'EUR' }).format(Number(value))
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
 * A percentage of a base, with its minimum when the rule has one.
 *
 * @param {object} rule The fee rule.
 * @param {{t: (text: string, vars: object) => string, locale: string, currency: string}} format The bound t(), the locale and the currency.
 * @return {string} The calculation in words.
 */
function percentageLine(rule, format) {
	const { t } = format
	return rule.minimumAmount
		? t('{percentage} % of the {base}, at least {minimum}', { percentage: decimal(rule.percentage, format.locale), base: rule.baseProperty || '', minimum: money(rule.minimumAmount, format) })
		: t('{percentage} % of the {base}', { percentage: decimal(rule.percentage, format.locale), base: rule.baseProperty || '' })
}

/**
 * A price per unit, named by its unit label or its base.
 *
 * @param {object} rule The fee rule.
 * @param {{t: (text: string, vars: object) => string, locale: string, currency: string}} format The bound t(), the locale and the currency.
 * @return {string} The calculation in words.
 */
function perUnitLine(rule, format) {
	const { t } = format
	return t('{price} per {unit}', { price: money(rule.unitPrice, format), unit: rule.unitLabel || rule.baseProperty || '' })
}

/**
 * The share of a fee that is refunded.
 *
 * @param {object} rule The fee rule.
 * @param {{t: (text: string, vars: object) => string, locale: string, currency: string}} format The bound t(), the locale and the currency.
 * @return {string} The calculation in words.
 */
function refundLine(rule, format) {
	const { t } = format
	return t('{percentage} % refunded', { percentage: decimal(rule.percentage, format.locale) })
}

/**
 * A fixed amount, or nothing when the rule has none.
 *
 * @param {object} rule The fee rule.
 * @param {{t: (text: string, vars: object) => string, locale: string, currency: string}} format The locale and the currency.
 * @return {string} The amount.
 */
function fixedAmountLine(rule, format) {
	return rule.amount === undefined || rule.amount === null ? '' : money(rule.amount, format)
}

/**
 * The calculation in words per kind of rule; any other kind is a fixed amount.
 */
const FEE_CALCULATIONS = new Map([
	['percentage', percentageLine],
	['per_unit', perUnitLine],
	['refund_percentage', refundLine],
])

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
	const line = FEE_CALCULATIONS.get(rule.calculation || 'fixed') ?? fixedAmountLine
	return line(rule, { t, locale, currency: rule.currency || 'EUR' })
}
