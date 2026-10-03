// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What a status asks of the fields, as a form and as a sentence.
//
// Two jobs, and they are the same vocabulary seen from two ends. The editor
// writes rules onto a statusType; the case page reads the answer OpenRegister
// already decided for the reader. Neither end evaluates anything: dossiq
// declares and OpenRegister refuses, which is the whole point of pushing this
// onto the schema rather than into a form component.
//
// The condition reuses the three kinds `derivedWhen` already uses. A case type
// with two condition vocabularies has two places to look and two ways to be
// wrong, and the status-declaration change said so in its own residue.
//
// @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md

/**
 * The three things a status can do to a field, in the order the editor lists them.
 *
 * The spelling is the contract with OpenRegister: its resolver reads `hidden`,
 * `readOnly` and `required` off the published block. A rule spelled `readonly`
 * is not refused anywhere, it simply never matches, so the case keeps saving
 * and the administrator keeps believing the field is locked.
 */
export const FIELD_RULES = ['required', 'readOnly', 'hidden']

/**
 * The condition kinds a rule may be written in.
 *
 * `documentPresent` is offered and stored, and is deliberately NOT published as
 * a condition: a document hangs off the case as a related object, and
 * OpenRegister's condition document carries only the object, the previous
 * object, the user and the transition. It is kept so the vocabulary stays the
 * one `derivedWhen` uses rather than a second, smaller one.
 */
export const CONDITION_KINDS = ['fieldPresent', 'fieldEquals', 'documentPresent']

/**
 * An empty rule row.
 *
 * @param {string} rule Which of the three, defaulting to required.
 * @return {object} The row.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function fieldRule(rule = 'required') {
	return {
		rule: FIELD_RULES.includes(rule) ? rule : 'required',
		field: '',
		groups: [],
		condition: null,
		message: '',
	}
}

/**
 * One condition row, empty.
 *
 * @param {string} kind Which question is asked.
 * @return {object} The condition.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function ruleCondition(kind = 'fieldPresent') {
	return {
		kind: CONDITION_KINDS.includes(kind) ? kind : 'fieldPresent',
		field: '',
		value: '',
		documentType: '',
	}
}

/**
 * A stored condition as the form holds it, or null when there is none.
 *
 * @param {unknown} condition The stored condition.
 * @return {object|null} The condition.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function normaliseCondition(condition) {
	if (!condition || typeof condition !== 'object') {
		return null
	}

	const kind = CONDITION_KINDS.includes(condition.kind) ? condition.kind : ''
	if (kind === '') {
		return null
	}

	return {
		kind,
		field: String(condition.field ?? ''),
		value: String(condition.value ?? ''),
		documentType: String(condition.documentType ?? ''),
	}
}

/**
 * The rules worth saving.
 *
 * A row naming no field is dropped rather than saved. OpenRegister refuses a
 * whole schema save over a rule naming a field it cannot find, and a blank row
 * is what an author leaves behind every time they open the editor and change
 * their mind. Refusing the publish over that would be the editor punishing
 * somebody for hesitating.
 *
 * @param {unknown} rules The rules as the form holds them.
 * @return {Array<object>} The rows worth saving.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function pruneFieldRules(rules) {
	if (Array.isArray(rules) === false) {
		return []
	}

	return rules
		.filter(
			(row) =>
				row
				&& FIELD_RULES.includes(row.rule)
				&& typeof row.field === 'string'
				&& row.field.trim() !== '',
		)
		.map((row) => {
			const saved = {
				rule: row.rule,
				field: row.field.trim(),
				groups: normaliseGroups(row.groups),
				message: String(row.message ?? '').trim(),
			}
			const condition = normaliseCondition(row.condition)
			if (condition) {
				saved.condition = condition
			}
			return saved
		})
}

/**
 * The group names a row carries, with the blanks and the repeats gone.
 *
 * @param {unknown} groups The groups as the form holds them.
 * @return {Array<string>} The names.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function normaliseGroups(groups) {
	if (Array.isArray(groups) === false) {
		return []
	}

	const names = []
	groups.forEach((group) => {
		const name = String(group ?? '').trim()
		if (name !== '' && names.includes(name) === false) {
			names.push(name)
		}
	})

	return names
}

/**
 * The reader's word for each rule.
 *
 * @return {object} The labels, by rule id.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function fieldRuleLabels() {
	return {
		required: t('dossiq', 'Must be filled in'),
		readOnly: t('dossiq', 'Cannot be changed'),
		hidden: t('dossiq', 'Not shown'),
	}
}

/**
 * The reader's word for each condition kind.
 *
 * @return {object} The labels, by kind.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function conditionKindLabels() {
	return {
		fieldPresent: t('dossiq', 'a field is filled in'),
		fieldEquals: t('dossiq', 'a field has a value'),
		documentPresent: t('dossiq', 'a document is on the case'),
	}
}

/**
 * What OpenRegister decided about the fields of one case, for this reader.
 *
 * Read straight off the object. `@self.fieldRules` is computed per user and per
 * state on the render path, so what arrives is the answer and not the
 * declaration. Recomputing it here would be a second evaluator, which is
 * exactly what this change exists to avoid: two evaluators disagree, and the
 * one on screen is the one that is wrong.
 *
 * @param {object} caseObject The case as OpenRegister answered it.
 * @return {{state: string, hidden: Array<string>, readOnly: Array<string>, required: Array<string>}} The answer.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function fieldRulesOf(caseObject) {
	const published = caseObject?.['@self']?.fieldRules

	return {
		state: String(published?.state ?? ''),
		hidden: asNames(published?.hidden),
		readOnly: asNames(published?.readOnly),
		required: asNames(published?.required),
	}
}

/**
 * A published list as a list of property names.
 *
 * @param {unknown} names The published list.
 * @return {Array<string>} The names.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
function asNames(names) {
	if (Array.isArray(names) === false) {
		return []
	}

	return names.map((name) => String(name)).filter((name) => name !== '')
}

/**
 * Whether the published answer says anything at all.
 *
 * @param {object} rules The answer, as `fieldRulesOf` shapes it.
 * @return {boolean} True when at least one list has a name in it.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function hasFieldRules(rules) {
	return (
		(rules?.required?.length ?? 0) > 0
		|| (rules?.readOnly?.length ?? 0) > 0
		|| (rules?.hidden?.length ?? 0) > 0
	)
}

/**
 * The refusal codes OpenRegister answers when a state's field rules stop a save.
 *
 * Read from the answer rather than kept as a switch with a sentence per code:
 * the refusal already carries the sentence, either the one the administrator
 * wrote on the rule or OpenRegister's own naming the field and the state.
 * Translating it again here would mean a status whose author wrote a Dutch
 * sentence showing an English one instead.
 */
export const STATE_FIELD_REFUSAL_CODES = [
	'state-field-required',
	'state-field-read-only',
	'state-field-hidden',
	'lifecycle-state-entry-refused',
	'lifecycle-state-exit-refused',
]

/**
 * What a state's field rules said, when they are what refused this write.
 *
 * OpenRegister answers a refused save with 422 and
 * `{error, errors: {code, field, state, message}}`, so the code is one level
 * down and the sentence sits beside it. A reader that only looks at `error`
 * gets the exception text and loses the field the handler has to go and fill
 * in.
 *
 * @param {object} body The response body, or anything at all.
 * @return {{code: string, field: string, state: string, message: string}|null} The refusal.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function stateFieldRefusal(body) {
	const errors = body?.errors ?? body
	const code = String(errors?.code ?? '')
	if (STATE_FIELD_REFUSAL_CODES.includes(code) === false) {
		return null
	}

	return {
		code,
		field: String(errors?.field ?? errors?.clause ?? ''),
		state: String(errors?.state ?? ''),
		message: String(errors?.message ?? ''),
	}
}
