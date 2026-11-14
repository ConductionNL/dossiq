/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The record a RecordStepsDialog writes, worked out from the action's steps.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
 */

const DAY_MS = 86400000

/**
 * @param {string|number|boolean|null|undefined} value A field's value.
 * @return {boolean} Whether the field holds nothing.
 */
function isEmpty(value) {
	return value === undefined || value === null || value === ''
}

/**
 * @param {object} step A step.
 * @param {object} values The wizard's values.
 * @return {Array<object>} The step's fields that apply: a field with `when`
 *   only while the named field holds one of `when.in`.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
 */
export function stepFields(step, values) {
	return (step.fields ?? []).filter((field) => !field.when || field.when.in.includes(values[field.when.field]))
}

/**
 * @param {object} step A step.
 * @param {object} values The wizard's values.
 * @return {object|null} The first required field left empty.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
 */
export function firstMissingField(step, values) {
	return stepFields(step, values).find((field) => field.required && isEmpty(values[field.key])) ?? null
}

/**
 * @param {Array<object>} steps The steps.
 * @return {object} The fields' `default` values.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
 */
export function defaultValues(steps) {
	return Object.fromEntries(steps.flatMap((step) => step.fields ?? [])
		.filter((field) => field.default !== undefined)
		.map((field) => [field.key, field.default]))
}

/**
 * @param {object|undefined} source The record to start from.
 * @param {Record<string, string>} map The wizard's field for each of its fields.
 * @param {Array<object>} steps The steps.
 * @return {object} The values, without one a select does not offer.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
 */
export function prefillValues(source, map, steps) {
	const fields = new Map(steps.flatMap((step) => step.fields ?? []).map((field) => [field.key, field]))
	const values = {}
	Object.entries(map ?? {}).forEach(([from, key]) => {
		const value = source?.[from]
		const options = fields.get(key)?.options
		if (!isEmpty(value) && (!options || options.some((option) => option.value === value))) {
			values[key] = value
		}
	})
	return values
}

/**
 * @param {object} values The wizard's values.
 * @param {Array<object>} steps The steps.
 * @param {string} caseId The case.
 * @return {object} The record: the case and every applying field that is not `transient`.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
 */
export function recordFromValues(values, steps, caseId) {
	const record = { case: caseId }
	steps.flatMap((step) => stepFields(step, values)).forEach((field) => {
		if (!field.transient && !isEmpty(values[field.key])) {
			record[field.key] = field.type === 'number' ? Number(values[field.key]) : values[field.key]
		}
	})
	return record
}

/**
 * @param {object|null} task The action's task: `{ title, dueInDaysField? }`.
 * @param {object} values The wizard's values.
 * @param {string} caseId The case.
 * @param {number} now The current time in ms.
 * @return {object|null} The engine task, or null when the action asks for none.
 *
 * @spec openspec/specs/enforcement-lhs/spec.md#requirement-enforcement-wizard
 */
export function followUpTask(task, values, caseId, now = Date.now()) {
	if (!task) {
		return null
	}
	const days = Number(values[task.dueInDaysField])
	const due = days > 0 ? new Date(now + days * DAY_MS).toISOString().slice(0, 10) : ''
	return {
		case: caseId,
		title: task.title,
		...(due ? { dueDate: `${due}T23:59:59+00:00` } : {}),
	}
}
