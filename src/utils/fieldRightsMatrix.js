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
	const fieldLabels = labelsOf(properties)
	const declared = declaredRoleRules(rules)
	const columns = roleColumns(declared, { statusOrder, statusName, roleNames })
	const requiredBy = requiredByStatus({ statuses, properties, statusOrder })

	const matrix = { statusOrder, declared, requiredBy }
	const rows = matrixFields(fieldLabels, matrix).map((field) => ({
		field,
		label: fieldLabels.labels.get(field) || field,
		cells: columns.map((column) => ({ state: cellState(matrix, { field, column }) })),
	}))

	return { columns, rows }
}

/**
 * The label of each declared field, and the order they are declared in.
 *
 * @param {Array<object>} properties The property definitions.
 * @return {{labels: Map<string, string>, order: Array<string>}} The labels and the order.
 */
function labelsOf(properties) {
	const labels = new Map()
	const order = []
	for (const property of properties) {
		const name = propertyName(property)
		if (name !== '' && !labels.has(name)) {
			// A field's title is translatable, so the store answers a language map.
			const title = resolveTranslatable(property.title ?? '').text
			labels.set(name, title || name)
			order.push(name)
		}
	}
	return { labels, order }
}

/**
 * A property's trimmed name, or '' when it has none.
 *
 * @param {object} property A property definition.
 * @return {string} The name.
 */
function propertyName(property) {
	return typeof property?.name === 'string' ? property.name.trim() : ''
}

/**
 * The hidden and read-only role rules that name a field and a role.
 *
 * @param {unknown} rules The fieldRoleRules.
 * @return {Array<{field: string, state: string, roles: Array<string>, statuses: Array<string>}>} The rules.
 */
function declaredRoleRules(rules) {
	return (Array.isArray(rules) ? rules : [])
		.filter((rule) => rule && ROLE_STATES[rule.rule] && typeof rule.field === 'string' && rule.field.trim() !== '')
		.map((rule) => ({ field: rule.field.trim(), state: ROLE_STATES[rule.rule], roles: names(rule.groups), statuses: names(rule.statuses) }))
		.filter((rule) => rule.roles.length > 0)
}

/**
 * One column per role the rules name, with the statuses its rules are
 * limited to, in status order.
 *
 * @param {Array<object>} declared The declared role rules.
 * @param {{statusOrder: Array<string>, statusName: Map<string, string>, roleNames: object}} statusContext The status order and names, and the role names per group.
 * @return {Array<{id: string, label: string, statuses: Array<string>}>} The columns.
 */
function roleColumns(declared, statusContext) {
	const roleStatuses = new Map()
	for (const rule of declared) {
		for (const role of rule.roles) {
			const limit = roleStatuses.get(role) || new Set()
			rule.statuses.forEach((status) => limit.add(status))
			roleStatuses.set(role, limit)
		}
	}
	return [...roleStatuses].map((entry) => roleColumn(entry, statusContext))
}

/**
 * The column of one role, labelled with its name and its statuses
 * ("Intaker, ontvangen").
 *
 * @param {Array} entry The role and the set of statuses it is limited to.
 * @param {{statusOrder: Array<string>, statusName: Map<string, string>, roleNames: object}} statusContext The status order and names, and the role names per group.
 * @return {{id: string, label: string, statuses: Array<string>}} The column.
 */
function roleColumn(entry, statusContext) {
	const [role, limit] = entry
	const { statusOrder, statusName, roleNames } = statusContext
	const limited = statusOrder.filter((id) => limit.has(id))
	const words = limited.map((id) => statusName.get(id)).filter(Boolean).map((name) => name.charAt(0).toLowerCase() + name.slice(1))
	const named = Array.isArray(roleNames?.[role]) && roleNames[role].length > 0 ? roleNames[role].join(' / ') : role
	return { id: role, label: words.length > 0 ? `${named}, ${words.join(', ')}` : named, statuses: limited }
}

/**
 * Per status, the fields it requires: from its field rules and from each
 * property's requiredAtStatus.
 *
 * @param {{statuses: Array<object>, properties: Array<object>, statusOrder: Array<string>}} parts The case type's statuses and properties, and the status order.
 * @return {Map<string, Set<string>>} The required fields per status id.
 */
function requiredByStatus(parts) {
	const requiredBy = new Map(parts.statusOrder.map((id) => [id, new Set()]))
	parts.statuses.forEach((status) => {
		const id = idOf(status)
		requiredIn(status).forEach((field) => requiredBy.get(id)?.add(field))
	})
	parts.properties.forEach((property) => {
		const at = idOf(property?.requiredAtStatus)
		if (at !== '' && requiredBy.has(at) && typeof property?.name === 'string') {
			requiredBy.get(at).add(property.name.trim())
		}
	})
	return requiredBy
}

/**
 * The fields the matrix has a row for: those a role rule names or a status
 * requires, declared ones first in declaration order.
 *
 * @param {{labels: Map<string, string>, order: Array<string>}} fieldLabels The declared fields.
 * @param {{declared: Array<object>, requiredBy: Map<string, Set<string>>}} matrix The rules and requirements.
 * @return {Array<string>} The fields.
 */
function matrixFields(fieldLabels, matrix) {
	const named = new Set(matrix.declared.map((rule) => rule.field))
	matrix.requiredBy.forEach((fields) => fields.forEach((field) => named.add(field)))
	return [...fieldLabels.order.filter((field) => named.has(field)), ...[...named].filter((field) => !fieldLabels.labels.has(field))]
}

/**
 * What one field is for one role column: hidden or read-only when a rule
 * of that role says so, else required when one of the column's statuses
 * (every status, for a role without a limit) requires it, else edit.
 *
 * @param {{statusOrder: Array<string>, declared: Array<object>, requiredBy: Map<string, Set<string>>}} matrix The rules and requirements.
 * @param {{field: string, column: object}} cell The field and the column.
 * @return {string} The state.
 */
function cellState(matrix, cell) {
	const own = matrix.declared.filter((rule) => rule.field === cell.field && rule.roles.includes(cell.column.id))
	if (own.some((rule) => rule.state === 'hidden')) {
		return 'hidden'
	}
	if (own.some((rule) => rule.state === 'readOnly')) {
		return 'readOnly'
	}
	const scope = cell.column.statuses.length > 0 ? cell.column.statuses : matrix.statusOrder
	return scope.some((id) => matrix.requiredBy.get(id)?.has(cell.field)) ? 'required' : 'edit'
}
