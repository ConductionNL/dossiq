// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * One case-type field as list text. A case answers its case type's fields in
 * `properties[]` as `{propertyDefinition, name, value}`, every value text and
 * a list JSON-encoded; a list column names the field it shows by `name` in
 * its `formatterOptions`.
 *
 * @spec openspec/specs/dossiq-sociaal-domein-participatiewet/spec.md
 */

/**
 * The text of the answer a case holds under one name.
 *
 * A list of words reads joined; a list of records has no one-line reading
 * and reads as nothing, as does a missing answer.
 *
 * @param {Array<object>|null} answers The case's `properties`.
 * @param {{name?: string}} [options] The column's `formatterOptions`.
 * @return {string} The text, or ''.
 */
export function caseAnswerText(answers, options = {}) {
	const name = options && typeof options.name === 'string' ? options.name : ''
	if (name === '' || !Array.isArray(answers)) {
		return ''
	}
	const entry = answers.find((answer) => answer && answer.name === name)
	const value = entry && typeof entry.value === 'string' ? entry.value : ''
	if (!value.startsWith('[')) {
		return value
	}
	let list
	try {
		list = JSON.parse(value)
	} catch {
		return value
	}
	if (!Array.isArray(list) || list.some((item) => item === null || typeof item === 'object')) {
		return ''
	}
	return list.join(', ')
}
