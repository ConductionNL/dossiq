/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case type's conditional rules (caseType.rules) for the Rules tab: the
 * fields a condition can name, the conversion between the stored condition
 * vocabulary and the library's condition editor, and a rule as an
 * "Als … / dan …" row. Dossiq evaluates no rule; the case type's publish
 * projects them onto the engines that own the actions.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

import { conditionSentence } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The editor field that stands for the case's status. */
export const STATUS_FIELD = 'status'

/** The prefix of an editor field that stands for a document type. */
export const DOCUMENT_FIELD_PREFIX = 'document:'

const PROPERTY_TYPES = {
	number: 'number',
	integer: 'integer',
	boolean: 'boolean',
	date: 'date',
	datetime: 'date',
}

/**
 * The fields a condition of this case type can name.
 *
 * Its own property definitions, its status and its document types (a
 * document can only be "filled in").
 *
 * @param {object} catalogue The case type's catalogue.
 * @param {Array<object>} [catalogue.propertyDefinitions] Its property definitions.
 * @param {Array<object>} [catalogue.statusTypes] Its statuses.
 * @param {Array<object>} [catalogue.documentTypes] Its document types.
 * @return {Array<{id: string, label: string, type: string, options?: Array, format?: string}>}
 */
export function ruleFields({ propertyDefinitions = [], statusTypes = [], documentTypes = [] } = {}) {
	const fields = propertyDefinitions
		.filter((p) => p && p.name)
		.map((p) => {
			const field = { id: p.name, label: p.title || p.label || wordsOf(p.name), type: PROPERTY_TYPES[p.propertyType] || 'string' }
			if (Array.isArray(p.enumValues) && p.enumValues.length > 0) {
				field.options = p.enumValues.map((v) => ({ id: v, label: String(v) }))
			}
			if (/kosten|bedrag|amount|price|prijs/i.test(p.name) && field.type === 'number') {
				field.format = 'currency'
			}
			return field
		})
	if (statusTypes.length > 0) {
		fields.push({ id: STATUS_FIELD, label: t('dossiq', 'status'), type: 'enum', options: statusTypes.map((s) => ({ id: s.id, label: s.name || s.title || s.id })) })
	}
	for (const d of documentTypes) {
		if (d && d.id) {
			fields.push({ id: DOCUMENT_FIELD_PREFIX + d.id, label: t('dossiq', 'Document {name}', { name: d.name || d.title || d.id }), type: 'presence' })
		}
	}
	return fields
}

/**
 * The case type's effective property definitions and statuses, inherited ones included.
 *
 * A derived case type (Omgevingsvergunning regulier) declares few rows of its
 * own; the blueprint merges its parents' in, which is what a rule's
 * conditions may name.
 *
 * @param {string} caseTypeId The case type.
 * @return {Promise<{propertyDefinitions: Array<object>, statusTypes: Array<object>}|null>} Null when unreadable.
 */
export async function fetchBlueprintCatalogue(caseTypeId) {
	try {
		const { data } = await axios.get(generateUrl(`/apps/dossiq/api/case-types/${encodeURIComponent(caseTypeId)}/blueprint`))
		if (!Array.isArray(data?.propertyDefinitions) || !Array.isArray(data?.statusTypes)) {
			return null
		}
		return { propertyDefinitions: data.propertyDefinitions, statusTypes: data.statusTypes }
	} catch {
		return null
	}
}

/**
 * The case type's role type names per Nextcloud group (`ncGroupId`), so a
 * rule that names a group can be labelled with the roles it stands for. An
 * empty map when the role types cannot be read.
 *
 * @param {string} caseTypeId The case type.
 * @return {Promise<Object<string, Array<string>>>} The names per group.
 * @spec openspec/specs/case-types/spec.md
 */
export async function fetchRoleNamesByGroup(caseTypeId) {
	try {
		const { data } = await axios.get(generateUrl('/apps/openregister/api/objects/dossiq/roleType'), { params: { caseType: caseTypeId, _limit: 100 } })
		const names = {}
		for (const roleType of Array.isArray(data?.results) ? data.results : []) {
			const group = typeof roleType?.ncGroupId === 'string' ? roleType.ncGroupId : ''
			const name = typeof roleType?.name === 'string' ? roleType.name.trim() : ''
			if (group !== '' && name !== '') {
				names[group] = [...(names[group] || []), name]
			}
		}
		return names
	} catch {
		return {}
	}
}

/**
 * A stored condition as an editor row.
 *
 * @param {object} condition `{ kind, field?, op?, value?, status?, documentType? }`.
 * @return {{field: string, operator: string, value?: unknown}}
 */
export function toEditorCondition(condition) {
	switch (condition?.kind) {
	case 'fieldCompare': return { field: condition.field, operator: condition.op || 'eq', value: condition.value }
	case 'fieldEquals': return { field: condition.field, operator: 'eq', value: condition.value }
	case 'fieldPresent': return { field: condition.field, operator: 'present' }
	case 'statusIs': return { field: STATUS_FIELD, operator: 'eq', value: condition.status }
	case 'documentPresent': return { field: DOCUMENT_FIELD_PREFIX + condition.documentType, operator: 'present' }
	default: return { field: '', operator: 'eq', value: '' }
	}
}

/**
 * An editor row as a stored condition, or null for an unfinished row.
 *
 * @param {{field: string, operator: string, value?: unknown}} row The editor row.
 * @return {object|null}
 */
export function fromEditorCondition(row) {
	const field = String(row?.field || '')
	if (field === '') {
		return null
	}
	if (field.startsWith(DOCUMENT_FIELD_PREFIX)) {
		return { kind: 'documentPresent', documentType: field.slice(DOCUMENT_FIELD_PREFIX.length) }
	}
	if (field === STATUS_FIELD && row.operator === 'eq') {
		return { kind: 'statusIs', status: String(row.value ?? '') }
	}
	if (row.operator === 'present') {
		return { kind: 'fieldPresent', field }
	}
	return { kind: 'fieldCompare', field, op: row.operator || 'eq', value: row.value }
}

/**
 * What one action does, after "dan".
 *
 * @param {object} action The action.
 * @param {object} names `{ templates, variants, documentTypes, fields }` id => name maps.
 * @return {string}
 */
export function actionSentence(action, names = {}) {
	const name = (map, id) => (map && map[id]) || id || ''
	switch (action?.action) {
	case 'addTask': return t('dossiq', 'task {title} is added', { title: action.title || name(names.tasks, action.taskType) })
	case 'chooseVariant': return t('dossiq', 'variant {variant} is chosen', { variant: name(names.variants, action.variant) })
	case 'sendMessage': return t('dossiq', 'message {template} is sent', { template: name(names.templates, action.emailTemplate) })
	case 'requireDocument': return t('dossiq', 'document {document} is required', { document: name(names.documentTypes, action.documentType) })
	case 'setField': return t('dossiq', '{field} becomes {value}', { field: name(names.fields, action.field), value: String(action.value ?? '') })
	default: return String(action?.action || '')
	}
}

/**
 * A rule as an "Als … / dan …" row.
 *
 * The moment comes first when it is a status ("Als status Geweigerd wordt"),
 * then the conditions joined with "en".
 *
 * @param {object} rule The stored rule.
 * @param {Array<object>} fields The fields from `ruleFields`.
 * @param {object} names Name maps for `actionSentence` and statuses (`statuses`).
 * @return {{id: string, title: string, line: string, pill: string}}
 */
export function caseTypeRuleRow(rule, fields, names = {}) {
	const clauses = []
	if (rule?.when?.event === 'statusEntered') {
		clauses.push(t('dossiq', 'status {status} is entered', { status: (names.statuses && names.statuses[rule.when.status]) || rule.when.status || '' }))
	} else if (rule?.when?.event === 'created') {
		clauses.push(t('dossiq', 'a case is created'))
	}
	for (const condition of rule?.conditions || []) {
		clauses.push(conditionSentence(toEditorCondition(condition), fields))
	}
	const actions = (rule?.actions || []).map((a) => actionSentence(a, names))
	return {
		id: String(rule?.id ?? ''),
		title: clauses.length > 0
			? t('dossiq', 'If {conditions}', { conditions: clauses.join(t('dossiq', ' and ')) })
			: t('dossiq', 'Always'),
		line: t('dossiq', 'then {actions}', { actions: actions.join(t('dossiq', ' and ')) }),
		pill: rule?.enabled === false ? 'off' : 'on',
	}
}

/**
 * A new rule id, unique among the case type's rules.
 *
 * @param {Array<object>} rules The existing rules.
 * @return {string}
 */
export function newRuleId(rules = []) {
	const taken = new Set(rules.map((r) => r?.id))
	let n = rules.length + 1
	while (taken.has('rule-' + n)) {
		n++
	}
	return 'rule-' + n
}

/**
 * A camelCase or snake_case name in words.
 *
 * @param {string} name The name.
 * @return {string}
 */
function wordsOf(name) {
	const words = String(name).replace(/[_-]+/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase().trim()
	return words.charAt(0).toUpperCase() + words.slice(1)
}
