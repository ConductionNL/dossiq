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
	const name = optionName(options)
	if (name === '' || !Array.isArray(answers)) {
		return ''
	}
	const value = answerValue(answers, name)
	if (!value.startsWith('[')) {
		return value
	}
	return listText(value)
}

/**
 * The field name a column's options name.
 *
 * @param {{name?: string}|null} options The column's `formatterOptions`.
 * @return {string} The name, or ''.
 */
function optionName(options) {
	return options && typeof options.name === 'string' ? options.name : ''
}

/**
 * The text value of the first answer under a name.
 *
 * @param {Array<object>} answers The case's `properties`.
 * @param {string} name The field name.
 * @return {string} The value, or '' when there is no text answer.
 */
function answerValue(answers, name) {
	const entry = answers.find((answer) => answer && answer.name === name)
	return entry && typeof entry.value === 'string' ? entry.value : ''
}

/**
 * A JSON-encoded list as one line: words joined, records as nothing, and
 * text that is not JSON as itself.
 *
 * @param {string} value The encoded list.
 * @return {string} The text.
 */
function listText(value) {
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
