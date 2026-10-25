/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's field rights as a matrix of fields by status: per cell what
 * the field is in that status, for whom (DqZaaktypeVelden "Wie wat ziet en
 * invult, per rol en status"). A view over the stored rules, which stay the
 * truth: fieldRoleRules (per role, a Nextcloud group, optionally limited to
 * statuses), the statuses' required field rules and a property's
 * requiredAtStatus.
 *
 * @spec openspec/changes/field-rights-matrix-by-status/specs/case-types/spec.md
 */

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
 * The matrix of fields by status.
 *
 * A row is a field that a role rule names or a status requires, in the order
 * the fields are declared. A cell holds `required` when the status requires
 * the field, and one entry `{ state, roles }` per role rule that holds there
 * (a rule without statuses holds in every status). An empty cell means
 * everyone may change the field.
 *
 * @param {object} input The case type's parts.
 * @param {Array<object>} [input.rules] Its fieldRoleRules.
 * @param {Array<object>} [input.statuses] Its status types, in order.
 * @param {Array<object>} [input.properties] Its property definitions.
 * @return {{columns: Array<{id: string, label: string}>, rows: Array<{field: string, label: string, cells: Array<{required: boolean, roles: Array<{state: string, roles: Array<string>}>}>}>}} The matrix.
 */
export function fieldRightsMatrix({ rules = [], statuses = [], properties = [] } = {}) {
	const columns = statuses
		.map((status) => ({ id: idOf(status), label: String(status?.name ?? '') }))
		.filter((column) => column.id !== '')
	const labels = new Map()
	const order = []
	for (const property of properties) {
		const name = typeof property?.name === 'string' ? property.name.trim() : ''
		if (name !== '' && !labels.has(name)) {
			labels.set(name, String(property.title || name))
			order.push(name)
		}
	}

	const declared = (Array.isArray(rules) ? rules : [])
		.filter((rule) => rule && ROLE_STATES[rule.rule] && typeof rule.field === 'string' && rule.field.trim() !== '')
		.map((rule) => ({ field: rule.field.trim(), state: ROLE_STATES[rule.rule], roles: names(rule.groups), statuses: names(rule.statuses) }))
		.filter((rule) => rule.roles.length > 0)

	const requiredBy = new Map(columns.map((column) => [column.id, new Set()]))
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

	const rows = fields.map((field) => ({
		field,
		label: labels.get(field) || field,
		cells: columns.map((column) => ({
			required: requiredBy.get(column.id).has(field),
			roles: declared
				.filter((rule) => rule.field === field && (rule.statuses.length === 0 || rule.statuses.includes(column.id)))
				.map((rule) => ({ state: rule.state, roles: rule.roles })),
		})),
	}))

	return { columns, rows }
}
