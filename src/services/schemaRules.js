// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The rules OpenRegister holds for a schema, read rather than kept.
//
// dossiq builds no rules engine and no second run log (ADR-022: one engine in
// the platform layer, a leaf declares). Three endpoints answer everything the
// case-type editor shows: the closed sets, the inventory in evaluation order,
// and a dry run that writes nothing.
//
// 🔴 NEVER KEY ON A RULE'S POSITION. The order is a property of the pipeline,
// not of a rule, and it moves the day a kind is added. The id is
// `<kind>:<schemaSlug>:<key>` and is derived from three facts every time, so it
// survives reads, restarts and machines. The handover says this in as many
// words, and it is the one instruction it repeats.
//
// The kind labels come from the vocabulary endpoint and not from a list here. A
// consumer that copies the list renders a blank the day the engine adds a kind;
// a consumer that reads the endpoint renders a kind it has never seen.
//
// @spec openspec/specs/status-transition-engine/spec.md

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Where OpenRegister publishes the three closed sets.
 *
 * @type {string}
 */
export const RULE_VOCABULARY_URL = generateUrl(
	'/apps/openregister/api/rules/vocabulary',
)

/**
 * The inventory of one schema's rules, in the order they are evaluated.
 *
 * @param {string} schema The schema slug.
 * @return {string} The url.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function ruleInventoryUrl(schema) {
	return generateUrl(
		`/apps/openregister/api/schemas/${encodeURIComponent(schema)}/rules`,
	)
}

/**
 * The dry run of one rule.
 *
 * @param {string} schema The schema slug.
 * @param {string} ruleId The rule id, `<kind>:<schemaSlug>:<key>`.
 * @return {string} The url.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function ruleEvaluateUrl(schema, ruleId) {
	return generateUrl(
		`/apps/openregister/api/schemas/${encodeURIComponent(schema)}/rules/${encodeURIComponent(ruleId)}/evaluate`,
	)
}

/**
 * Whether an answer is the vocabulary rather than an error page.
 *
 * @param {unknown} data The answer.
 * @return {boolean} True when it carries the three sets.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function isRuleVocabulary(data) {
	return (
		!!data
		&& Array.isArray(data.kinds)
		&& Array.isArray(data.verdicts)
		&& Array.isArray(data.actions)
	)
}

/**
 * The kinds the engine publishes, keyed by kind, with their description.
 *
 * @param {object} vocabulary The vocabulary answer.
 * @return {object} The descriptors, by kind.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function kindsByName(vocabulary) {
	const kinds = {}
	if (isRuleVocabulary(vocabulary) === false) {
		return kinds
	}

	vocabulary.kinds.forEach((kind) => {
		const name = String(kind?.kind ?? '')
		if (name !== '') {
			kinds[name] = kind
		}
	})

	return kinds
}

/**
 * Read the vocabulary.
 *
 * A refusal answers the empty sets rather than raising. The vocabulary is open
 * to any signed-in caller, so a refusal here means the instance predates the
 * engine, and the tab falls back to showing each rule's own kind string.
 *
 * @return {Promise<object>} The vocabulary, possibly empty.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export async function fetchRuleVocabulary() {
	try {
		const { data } = await axios.get(RULE_VOCABULARY_URL)
		return isRuleVocabulary(data)
			? data
			: { kinds: [], verdicts: [], actions: [] }
	} catch {
		return { kinds: [], verdicts: [], actions: [] }
	}
}

/**
 * Read one schema's rules, in the order the engine evaluates them.
 *
 * The inventory is an admin read: it exposes the conditions that decide who may
 * move a case on. A 403 is therefore an ordinary answer for a handler who
 * opened the case-type page, not a fault, and it comes back as the empty list.
 *
 * @param {string} schema The schema slug.
 * @param {object} [scope] Property => value: only that scope's rules and the unscoped ones (`scope[caseType]=`).
 * @return {Promise<Array<object>>} The rules.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export async function fetchSchemaRules(schema, scope = {}) {
	const params = {}
	for (const [property, value] of Object.entries(scope || {})) {
		params[`scope[${property}]`] = value
	}
	const { data } = await axios.get(ruleInventoryUrl(schema), Object.keys(params).length > 0 ? { params } : undefined)

	return Array.isArray(data?.rules) ? data.rules : []
}

/**
 * Try one rule against one stored object, writing nothing.
 *
 * @param {string} schema The schema slug.
 * @param {string} ruleId The rule id.
 * @param {string} register The register slug the object lives in.
 * @param {string} objectId The object to try it against.
 * @return {Promise<object>} The trial result, carrying `trace`.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export async function evaluateRule(schema, ruleId, register, objectId) {
	const { data } = await axios.post(ruleEvaluateUrl(schema, ruleId), {
		register,
		objectId,
	})

	return data || {}
}

/**
 * The trace of a trial, in the shape the tab renders.
 *
 * `operand` and `operandValue` are rendered beside the verdict and not hidden
 * behind it: they are what turns "why did this rule not fire" into a fact.
 *
 * @param {object} result The trial result.
 * @return {{verdict: string, operand: string, operandValue: string, message: string}} The trace.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function traceOf(result) {
	const trace = result?.trace ?? {}

	return {
		verdict: String(trace.verdict ?? ''),
		operand: String(trace.operand ?? ''),
		operandValue: String(trace.operandValue ?? ''),
		message: String(trace.message ?? result?.error?.message ?? ''),
	}
}

/**
 * The properties of one schema, to name a rule by the field it writes.
 * Nothing on a refusal: the rows then fall back to the rule's own words.
 *
 * @param {string} schema The schema slug.
 * @return {Promise<object>} The properties by key.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-the-rules-tab-lists-rules-as-rows
 */
export async function fetchSchemaProperties(schema) {
	try {
		const { data } = await axios.get(generateUrl(`/apps/openregister/api/schemas/${encodeURIComponent(schema)}`))
		return data && typeof data.properties === 'object' && data.properties !== null ? data.properties : {}
	} catch {
		return {}
	}
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/**
 * "daysOverdue" as "Days overdue".
 *
 * @param {string} key A property key.
 * @return {string} The words.
 */
function wordsOf(key) {
	const words = String(key).replace(/([a-z0-9])([A-Z])/g, '$1 $2').toLowerCase()
	return words.charAt(0).toUpperCase() + words.slice(1)
}

/**
 * The first sentence of a description.
 *
 * @param {string} text The description.
 * @return {string} Its first sentence.
 */
function firstSentence(text) {
	const s = String(text || '').trim()
	const end = s.search(/[.!?](\s|$)/)
	return end === -1 ? s : s.slice(0, end + 1)
}

/**
 * The rules as rows a reader understands: titled by the field a rule writes
 * (its schema title, else its key in words), a line saying what it does (the
 * field's first sentence, else the kind's description), and a pill: a
 * calculation is `calculated`, any other rule `on` or `off`. A rule known only
 * by an id is titled by its kind. Titles and lines are untranslated.
 *
 * @param {Array<object>} rules The inventory rows.
 * @param {object} properties The schema properties by key.
 * @param {object} kinds The vocabulary by kind.
 * @return {Array<{id: string, title: string, line: string, pill: string}>} The rows.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-the-rules-tab-lists-rules-as-rows
 */
export function ruleRows(rules, properties = {}, kinds = {}) {
	return (Array.isArray(rules) ? rules : []).map((rule) => ruleRow(rule, { properties, kinds }))
}

/**
 * One rule as a row.
 *
 * @param {object} rule The inventory row.
 * @param {{properties: object, kinds: object}} vocabulary The schema properties by key and the vocabulary by kind.
 * @return {{id: string, title: string, line: string, pill: string}} The row.
 */
function ruleRow(rule, vocabulary) {
	const key = String(rule?.key ?? '')
	const prop = propertyOf(vocabulary.properties, key)
	const fallback = fallbackText(ruleLabel(rule, key), kindTextOf(rule, vocabulary.kinds))
	return {
		id: String(rule?.id ?? ''),
		title: propertyTitle(prop) || fallback.title,
		line: firstSentence(prop.description) || fallback.line,
		pill: rulePill(rule),
	}
}

/**
 * What a rule is called: its label, else its key, else its id.
 *
 * @param {object} rule The inventory row.
 * @param {string} key Its key.
 * @return {string} The label.
 */
function ruleLabel(rule, key) {
	return String(rule?.label || key || rule?.id || '')
}

/**
 * The schema property a rule writes.
 *
 * @param {object|null} properties The schema properties by key.
 * @param {string} key The rule's key.
 * @return {object} The property, or an empty one.
 */
function propertyOf(properties, key) {
	return (properties && properties[key]) || {}
}

/**
 * The description of a rule's kind, else the kind itself.
 *
 * @param {object} rule The inventory row.
 * @param {object|null} kinds The vocabulary by kind.
 * @return {string} The text.
 */
function kindTextOf(rule, kinds) {
	return String(kinds?.[rule?.kind]?.description || rule?.kind || '')
}

/**
 * A property's own title, or '' when it has none.
 *
 * @param {object} prop The property.
 * @return {string} The title.
 */
function propertyTitle(prop) {
	return typeof prop.title === 'string' && prop.title !== '' ? prop.title : ''
}

/**
 * The title and line of a rule whose property says nothing: a rule named by
 * an id is titled by its kind and has no line; any other is titled by its
 * label in words and lined by its kind.
 *
 * @param {string} label The rule's label.
 * @param {string} kindText The kind's text.
 * @return {{title: string, line: string}} The fallback.
 */
function fallbackText(label, kindText) {
	if (UUID.test(label)) {
		return { title: kindText, line: '' }
	}
	return { title: wordsOf(label), line: kindText }
}

/**
 * The pill of a rule: calculated, off or on.
 *
 * @param {object} rule The inventory row.
 * @return {string} The pill.
 */
function rulePill(rule) {
	if (rule?.kind === 'calculation') {
		return 'calculated'
	}
	return rule?.enabled === false ? 'off' : 'on'
}
