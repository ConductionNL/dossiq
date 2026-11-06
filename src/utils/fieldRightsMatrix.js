/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's field rights as a matrix of fields by role: per cell what
 * the field is for that role, in the statuses its rules name (DqZaaktypeVelden
 * "Wie wat ziet en invult, per rol en status"). A view over the stored rules, which stay the
 * truth: fieldRoleRules (per role, a Nextcloud group, optionally limited to
 * statuses), the statuses' required field rules and a property's
 * requiredAtStatus.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { resolveTranslatable } from './i18nResolver.js'

const ROLE_STATES = { hidden: 'hidden', readOnly: 'readOnly' }

/**
 * The id a reference carries, a uuid or a row with `id`.
 *
 * @param {unknown} value The reference.
 * @return {string} The id, or ''.
 */
function idOf(value) {
	if (value && typeof value === 'object') {
		return String(value.id ?? value.uuid ?? '')
	}
	return typeof value === 'string' ? value : ''
}

/**
 * The non-empty strings of a list, once each.
 *
 * @param {unknown} value The list.
 * @return {Array<string>} The names.
 */
function names(value) {
	if (!Array.isArray(value)) {
		return []
	}
	return [...new Set(value.filter((entry) => typeof entry === 'string').map((entry) => entry.trim()).filter(Boolean))]
}

/**
 * The fields a status requires, from its field rules.
 *
 * @param {object} status The status type.
 * @return {Array<string>} The field names.
 */
function requiredIn(status) {
	const rules = Array.isArray(status?.fieldRules) ? status.fieldRules : []
	return rules
		.filter((rule) => rule && rule.rule === 'required')
		.flatMap((rule) => (Array.isArray(rule.fields) ? rule.fields : [rule.field]))
		.filter((field) => typeof field === 'string' && field !== '')
}

/**
 * The matrix of fields by role, as the board draws it: a column per role a
 * rule names, labelled with the statuses its rules are limited to
 * ("Intaker, ontvangen"), and per cell what the field is for that role.
 *
 * A row is a field that a role rule names or a status requires, in the order
 * the fields are declared. A cell is `hidden` or `readOnly` when a rule of
 * that role says so, else `required` when one of the column's statuses (every
 * status, for a role without a limit) requires the field, else `edit`.
 *
 * @param {object} input The case type's parts.
 * @param {Array<object>} [input.rules] Its fieldRoleRules.
 * @param {Array<object>} [input.statuses] Its status types, in order.
 * @param {Array<object>} [input.properties] Its property definitions.
 * @param {Object<string, Array<string>>} [input.roleNames] The role type names per Nextcloud group, to label a group's column.
 * @return {{columns: Array<{id: string, label: string, statuses: Array<string>}>, rows: Array<{field: string, label: string, cells: Array<{state: string}>}>}} The matrix.
 */
export function fieldRightsMatrix({ rules = [], statuses = [], properties = [], roleNames = {} } = {}) {
	const statusOrder = statuses.map((status) => idOf(status)).filter((id) => id !== '')
	const statusName = new Map(statuses.map((status) => [idOf(status), resolveTranslatable(status?.name ?? '').text]))
	const labels = new Map()
	const order = []
	for (const property of properties) {
		const name = typeof property?.name === 'string' ? property.name.trim() : ''
		if (name !== '' && !labels.has(name)) {
			// A field's title is translatable, so the store answers a language map.
			const title = resolveTranslatable(property.title ?? '').text
			labels.set(name, title || name)
			order.push(name)
		}
	}

	const declared = (Array.isArray(rules) ? rules : [])
		.filter((rule) => rule && ROLE_STATES[rule.rule] && typeof rule.field === 'string' && rule.field.trim() !== '')
		.map((rule) => ({ field: rule.field.trim(), state: ROLE_STATES[rule.rule], roles: names(rule.groups), statuses: names(rule.statuses) }))
		.filter((rule) => rule.roles.length > 0)

	const roleStatuses = new Map()
	for (const rule of declared) {
		for (const role of rule.roles) {
			const limit = roleStatuses.get(role) || new Set()
			rule.statuses.forEach((status) => limit.add(status))
			roleStatuses.set(role, limit)
		}
	}
	const columns = [...roleStatuses].map(([role, limit]) => {
		const limited = statusOrder.filter((id) => limit.has(id))
		const words = limited.map((id) => statusName.get(id)).filter(Boolean).map((name) => name.charAt(0).toLowerCase() + name.slice(1))
		const named = Array.isArray(roleNames?.[role]) && roleNames[role].length > 0 ? roleNames[role].join(' / ') : role
		return { id: role, label: words.length > 0 ? `${named}, ${words.join(', ')}` : named, statuses: limited }
	})

	const requiredBy = new Map(statusOrder.map((id) => [id, new Set()]))
	statuses.forEach((status) => {
		const id = idOf(status)
		requiredIn(status).forEach((field) => requiredBy.get(id)?.add(field))
	})
	properties.forEach((property) => {
		const at = idOf(property?.requiredAtStatus)
		if (at !== '' && requiredBy.has(at) && typeof property?.name === 'string') {
			requiredBy.get(at).add(property.name.trim())
		}
	})

	const named = new Set(declared.map((rule) => rule.field))
	requiredBy.forEach((fields) => fields.forEach((field) => named.add(field)))
	const fields = [...order.filter((field) => named.has(field)), ...[...named].filter((field) => !labels.has(field))]

	const stateOf = (field, column) => {
		const own = declared.filter((rule) => rule.field === field && rule.roles.includes(column.id))
		if (own.some((rule) => rule.state === 'hidden')) {
			return 'hidden'
		}
		if (own.some((rule) => rule.state === 'readOnly')) {
			return 'readOnly'
		}
		const scope = column.statuses.length > 0 ? column.statuses : statusOrder
		return scope.some((id) => requiredBy.get(id)?.has(field)) ? 'required' : 'edit'
	}

	const rows = fields.map((field) => ({
		field,
		label: labels.get(field) || field,
		cells: columns.map((column) => ({ state: stateOf(field, column) })),
	}))

	return { columns, rows }
}
