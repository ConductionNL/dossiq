// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * How late a board card is, by the board's due rule.
 *
 * The workflow board is dossiq's own component, so the library's `dueRule`
 * does not reach it by itself. This reads the same rule shape the library's
 * board takes (`{ field, variantWhen: [{ op, value, variant }] }`) and gives
 * the card its severity: `error` is overdue, `warning` is due soon, anything
 * else is fine. The first rule that matches wins, as in the library.
 *
 * Without a rule the card behaves as it always did: overdue after the
 * deadline, a warning within three days. That is the full structure.
 *
 * Kept free of the library import on purpose. The library's own resolver
 * pulls in `@nextcloud/auth`, which reads `window` while loading, and this
 * runs in node-environment specs.
 *
 * @param {number|null} daysRemaining Days until the deadline, negative when past.
 * @param {{variantWhen?: Array<{op: string, value: number, variant: string}>}|null} [dueRule]
 *   The board's rule, or nothing.
 * @return {'overdue'|'warning'|'ok'|null} The severity, or null without a deadline.
 *
 * @spec openspec/changes/simple-list-and-dashboard/specs/dashboard/spec.md#REQ-DASH-027
 */
export function cardDueSeverity(daysRemaining, dueRule) {
	if (daysRemaining === null || daysRemaining === undefined) {
		return null
	}
	const rules = Array.isArray(dueRule?.variantWhen) ? dueRule.variantWhen : null
	if (rules === null) {
		if (daysRemaining < 0) {
			return 'overdue'
		}
		return daysRemaining <= 3 ? 'warning' : 'ok'
	}
	for (const rule of rules) {
		if (!holds(daysRemaining, rule?.op, Number(rule?.value))) {
			continue
		}
		if (rule.variant === 'error' || rule.variant === 'danger') {
			return 'overdue'
		}
		return rule.variant === 'warning' ? 'warning' : 'ok'
	}
	return 'ok'
}

/**
 * One comparison of a day count against a rule.
 *
 * @param {number} days The day count.
 * @param {string} op `lt`, `lte`, `gt`, `gte` or `eq`.
 * @param {number} value The rule's number.
 * @return {boolean} Whether the rule holds.
 *
 * @spec openspec/changes/simple-list-and-dashboard/specs/dashboard/spec.md#REQ-DASH-027
 */
function holds(days, op, value) {
	if (!Number.isFinite(value)) {
		return false
	}
	if (op === 'lt') {
		return days < value
	}
	if (op === 'lte') {
		return days <= value
	}
	if (op === 'gt') {
		return days > value
	}
	if (op === 'gte') {
		return days >= value
	}
	return days === value
}
