// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * A KPI read against its target: where the bar stops, where the target mark
 * stands, and the sentence that says both (REQ-CRS-35, row 10.1).
 *
 * The library's gauge shows the RATIO of value to target ("89%" for 80 against
 * 90), which is a different number from the KPI itself: a reader who sees 89%
 * beside an on-time KPI of 80% is told something that is not true. This
 * reading keeps the value and the target as they are and scales both on one
 * axis.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
 */

/**
 * Read a number, or null when it is not one.
 *
 * @param {unknown} raw The raw value.
 * @return {number|null}
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
 */
export function toNumber(raw) {
	if (raw === null || raw === undefined || raw === '' || typeof raw === 'boolean') {
		return null
	}
	const value = Number(raw)
	return Number.isFinite(value) ? value : null
}

/**
 * Format a number for the gauge: rounded to `decimals`, with the suffix.
 *
 * @param {number} value The number.
 * @param {object} [format] `{ decimals, suffix }`.
 * @return {string}
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
 */
export function formatGaugeNumber(value, format = {}) {
	const decimals = Number.isInteger(format.decimals) && format.decimals >= 0 ? format.decimals : 0
	return Number(value).toFixed(decimals) + (typeof format.suffix === 'string' ? format.suffix : '')
}

/**
 * The gauge's reading of a value against its target.
 *
 * The axis runs from 0 to `max` when the content gives one, else to the larger
 * of value and target, so neither ever falls off the bar. `met` says whether
 * the value reached the target; `invert` is for a KPI where lower is better.
 *
 * @param {unknown} rawValue The KPI value.
 * @param {unknown} rawTarget The target.
 * @param {object} [options] `{ max, invert, decimals, suffix }`.
 * @return {{value: number, target: number, valueText: string, targetText: string, fill: number, mark: number, met: boolean}|null}
 *   Null when value or target is not a number.
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/dashboard/spec.md
 */
export function gaugeReading(rawValue, rawTarget, options = {}) {
	const value = toNumber(rawValue)
	const target = toNumber(rawTarget)
	if (value === null || target === null) {
		return null
	}
	const declaredMax = toNumber(options.max)
	const max = declaredMax !== null && declaredMax > 0
		? Math.max(declaredMax, value, target)
		: Math.max(value, target)
	const share = (number) => (max > 0 ? Math.min(100, Math.max(0, (number / max) * 100)) : 0)
	return {
		value,
		target,
		valueText: formatGaugeNumber(value, options),
		targetText: formatGaugeNumber(target, options),
		fill: share(value),
		mark: share(target),
		met: options.invert === true ? value <= target : value >= target,
	}
}
