/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The queue urgency settings as the admin section (adminFields.js) and the
 * case type editor read and write them. The bounds are the server's (UrgencyProfile.php); the
 * form refuses a value outside them before anything is sent, and the server
 * clamps anything that still gets through.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The four settings: form field, app config key, default and bounds.
 *
 * @type {Array<{field: string, key: string, default: number, max: number, whole: boolean}>}
 */
export const QUEUE_URGENCY_FIELDS = [
	{
		field: 'criticalDays',
		key: 'queue_critical_days',
		default: 3,
		max: 60,
		whole: true,
	},
	{
		field: 'warningDays',
		key: 'queue_warning_days',
		default: 7,
		max: 120,
		whole: true,
	},
	{
		field: 'priorityWeight',
		key: 'queue_priority_weight',
		default: 10,
		max: 50,
		whole: false,
	},
	{
		field: 'idleWeight',
		key: 'queue_idle_weight',
		default: 0.5,
		max: 1.5,
		whole: false,
	},
]

/**
 * Read one typed value, accepting a decimal comma.
 *
 * @param {unknown} raw What the field holds.
 * @return {number|null} The number, or null when it is empty or not a number.
 */
function readNumber(raw) {
	if (raw === null || raw === undefined) {
		return null
	}
	const text = String(raw).trim().replace(',', '.')
	if (text === '') {
		return null
	}
	const value = Number(text)
	return Number.isFinite(value) ? value : null
}

/**
 * The message for a value outside its bounds.
 *
 * @param {{max: number, whole: boolean}} spec The field's bounds.
 * @return {string} The translated message.
 */
function boundsMessage(spec) {
	return spec.whole
		? t('dossiq', 'Enter a whole number from 0 to {max}.', { max: spec.max })
		: t('dossiq', 'Enter a number from 0 to {max}.', { max: spec.max })
}

/**
 * Check one value against its bounds.
 *
 * @param {unknown} raw What the field holds.
 * @param {{max: number, whole: boolean}} spec The field's bounds.
 * @return {{value: (number|null), error: string}} The number and '' when valid, else the message.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
export function checkQueueValue(raw, spec) {
	const value = readNumber(raw)
	if (
		value === null
		|| value < 0
		|| value > spec.max
		|| (spec.whole && !Number.isInteger(value))
	) {
		return { value: null, error: boundsMessage(spec) }
	}
	return { value, error: '' }
}

/**
 * Read a case type's threshold override from its field.
 *
 * Empty means "use the instance default", which is stored as no value at all:
 * the field is left off the case type rather than written as null, because
 * the schema declares an integer.
 *
 * @param {unknown} raw What the field holds.
 * @param {number} max The upper bound (60 critical, 120 warning).
 * @return {{value: (number|undefined), error: string}} The whole number, undefined when empty, or the message.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function readThresholdOverride(raw, max) {
	if (readNumber(raw) === null && String(raw ?? '').trim() === '') {
		return { value: undefined, error: '' }
	}
	const checked = checkQueueValue(raw, { max, whole: true })
	return checked.error
		? { value: undefined, error: checked.error }
		: { value: checked.value, error: '' }
}
