/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for the DMN decision-table settings tab (dmn-decision-tables).
 * No network, no Vue — vitest-testable. The structural validator mirrors the
 * backend DecisionTableService::validateTable() shape checks so a coordinator
 * gets immediate feedback instead of a 400 round-trip.
 */

/**
 * Hit policies the backend engine fully implements (UNIQUE/FIRST/COLLECT) plus
 * the two documented-but-unimplemented ones (PRIORITY/ANY), matching the OR
 * schema enum.
 *
 * @type {string[]}
 */
export const HIT_POLICIES = ['UNIQUE', 'FIRST', 'PRIORITY', 'ANY', 'COLLECT']

/**
 * Input/output field types the expression grammar understands.
 *
 * @type {string[]}
 */
export const FIELD_TYPES = ['string', 'number', 'boolean', 'date']

/**
 * A concise one-line summary of a decision table for the list view.
 *
 * @param {object} table The decision table object
 * @return {string} e.g. "UNIQUE · 2 inputs · 2 outputs · 3 rules"
 * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
 */
export function summariseTable(table) {
	const hitPolicy = (table && table.hitPolicy) || 'UNIQUE'
	const inputs = Array.isArray(table && table.inputs) ? table.inputs.length : 0
	const outputs = Array.isArray(table && table.outputs) ? table.outputs.length : 0
	const rules = Array.isArray(table && table.rules) ? table.rules.length : 0
	return `${hitPolicy} · ${inputs} inputs · ${outputs} outputs · ${rules} rules`
}

/**
 * Structurally validate the JSON portion (inputs/outputs/rules) of a decision
 * table before it is submitted. Mirrors the backend shape checks: fields need
 * a name + a valid type, and every rule's inputEntries/outputEntries counts
 * must align with the declared inputs/outputs.
 *
 * @param {object} parsed The parsed `{inputs, outputs, rules}` object
 * @return {{valid: boolean, errors: string[]}} Validation result
 * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
 */
export function validateTableStructure(parsed) {
	const errors = []

	if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
		return { valid: false, errors: ['Decision definition must be a JSON object'] }
	}

	const inputs = parsed.inputs
	const outputs = parsed.outputs
	const rules = parsed.rules

	if (!Array.isArray(inputs)) {
		errors.push('inputs must be an array')
	} else {
		inputs.forEach((field, i) => validateField(field, `inputs[${i}]`, errors))
	}

	if (!Array.isArray(outputs)) {
		errors.push('outputs must be an array')
	} else {
		outputs.forEach((field, i) => validateField(field, `outputs[${i}]`, errors))
	}

	if (!Array.isArray(rules)) {
		errors.push('rules must be an array')
	} else if (Array.isArray(inputs) && Array.isArray(outputs)) {
		rules.forEach((rule, i) => validateRule(rule, i, inputs.length, outputs.length, errors))
	}

	return { valid: errors.length === 0, errors }
}

/**
 * Validate a single input/output field entry.
 *
 * @param {object} field The field object
 * @param {string} label Positional label for error messages
 * @param {string[]} errors The error accumulator (mutated)
 * @return {void}
 */
function validateField(field, label, errors) {
	if (field === null || typeof field !== 'object' || Array.isArray(field)) {
		errors.push(`${label} must be an object`)
		return
	}
	if (typeof field.name !== 'string' || field.name.trim() === '') {
		errors.push(`${label} requires a non-empty name`)
	}
	if (field.type !== undefined && !FIELD_TYPES.includes(field.type)) {
		errors.push(`${label} has an invalid type "${field.type}"`)
	}
}

/**
 * Validate a single rule row against the declared input/output counts.
 *
 * @param {object} rule The rule object
 * @param {number} index The rule position
 * @param {number} inputCount Declared inputs count
 * @param {number} outputCount Declared outputs count
 * @param {string[]} errors The error accumulator (mutated)
 * @return {void}
 */
function validateRule(rule, index, inputCount, outputCount, errors) {
	if (rule === null || typeof rule !== 'object' || Array.isArray(rule)) {
		errors.push(`rules[${index}] must be an object`)
		return
	}
	const inputEntries = Array.isArray(rule.inputEntries) ? rule.inputEntries : []
	const outputEntries = Array.isArray(rule.outputEntries) ? rule.outputEntries : []
	if (inputEntries.length !== inputCount) {
		errors.push(`rules[${index}] has ${inputEntries.length} inputEntries but ${inputCount} inputs are declared`)
	}
	if (outputEntries.length !== outputCount) {
		errors.push(`rules[${index}] has ${outputEntries.length} outputEntries but ${outputCount} outputs are declared`)
	}
}

/**
 * Parse the JSON textarea content, returning either the parsed structure or a
 * parse error. Keeps the try/catch out of the Vue component.
 *
 * @param {string} raw The textarea content
 * @return {{ok: boolean, value?: object, error?: string}} Parse result
 * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
 */
export function parseDefinitionJson(raw) {
	if (typeof raw !== 'string' || raw.trim() === '') {
		return { ok: false, error: 'Definition JSON is empty' }
	}
	try {
		return { ok: true, value: JSON.parse(raw) }
	} catch (e) {
		return { ok: false, error: 'Invalid JSON: ' + (e && e.message ? e.message : 'parse error') }
	}
}
