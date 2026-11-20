/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the workflow editor's step and transition panels read what they are
 * given: lists that arrive as a JSON string or as an array, and a step's
 * config with a default for every field.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-process-step-configuration/tasks.md
 */

/**
 * A list that arrives as a JSON string or as an array: the parsed string,
 * a copy of the array, or an empty list for nothing or unreadable JSON.
 *
 * @param {string|Array|null|undefined} value The list.
 * @return {Array} The list.
 */
export function parseJsonList(value) {
	if (!value) return []
	if (typeof value === 'string') {
		try {
			return JSON.parse(value)
		} catch {
			return []
		}
	}
	return [...value]
}

/**
 * A step's config with every field read or defaulted.
 *
 * @param {object|string|null|undefined} config The config, or its JSON.
 * @return {{sla: object, requiredFields: Array, autoActions: Array, escalationRule: object}} The config.
 */
export function parseStepConfig(config) {
	const safe = configObject(config)
	return {
		sla: slaOf(safe.sla),

		requiredFields: copiedList(safe.requiredFields),

		autoActions: copiedList(safe.autoActions),

		escalationRule: escalationRuleOf(safe.escalationRule),
	}
}

/**
 * The config as an object: parsed when it is JSON, empty when unusable.
 *
 * @param {object|string|null|undefined} config The config, or its JSON.
 * @return {object} The config object.
 */
function configObject(config) {
	let raw = config
	if (typeof raw === 'string') {
		try {
			raw = JSON.parse(raw)
		} catch {
			raw = null
		}
	}
	return raw && typeof raw === 'object' ? raw : {}
}

/**
 * The step's service level: a finite value and a unit name.
 *
 * @param {object|undefined} sla The stored service level.
 * @return {{value: number|null, unit: string}} The service level.
 */
function slaOf(sla) {
	return {
		value: sla && Number.isFinite(sla.value) ? sla.value : null,

		unit: sla && typeof sla.unit === 'string' ? sla.unit : '',
	}
}

/**
 * A copy of a stored list, or an empty list.
 *
 * @param {unknown} list The stored value.
 * @return {Array} The list.
 */
function copiedList(list) {
	return Array.isArray(list) ? [...list] : []
}

/**
 * The stored string, or the fallback for anything else.
 *
 * @param {unknown} value The stored value.
 * @param {string} fallback The default.
 * @return {string} The string.
 */
function stringOr(value, fallback) {
	return typeof value === 'string' ? value : fallback
}

/**
 * The step's escalation rule with every field read or defaulted.
 *
 * @param {object|undefined} rule The stored rule.
 * @return {object} The rule.
 */
function escalationRuleOf(rule) {
	const stored = rule && typeof rule === 'object' ? rule : {}
	return {
		trigger: stringOr(stored.trigger, 'preBreach'),

		offset: Number.isFinite(stored.offset) ? stored.offset : 0,

		offsetUnit: stringOr(stored.offsetUnit, 'businessDays'),

		notifyRole: stringOr(stored.notifyRole, ''),

		escalateToRole: stringOr(stored.escalateToRole, ''),

		openIncident: !!stored.openIncident,
	}
}
