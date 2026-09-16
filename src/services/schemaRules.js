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
// @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md

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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
 * @return {Promise<Array<object>>} The rules.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export async function fetchSchemaRules(schema) {
	const { data } = await axios.get(ruleInventoryUrl(schema))

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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
