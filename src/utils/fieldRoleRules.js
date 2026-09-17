// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What a case type's roles may see and change, as a sentence beside a gap.
//
// The sibling of src/utils/statusFieldRules.js. That one is about the status a
// case is in; this one is about the person reading it. Both read an answer
// OpenRegister already decided and neither decides anything: the rule that
// withheld the field ran on the render path, and a second evaluator here would
// eventually disagree with the one the reader actually met.
//
// 🔴 A ROW SAYS WHETHER IT APPLIES TO THE READER, AND IT DOES NOT WORK THAT OUT.
// The declaration names groups; the browser does not know which groups the
// reader is in, and asking would be a third place the answer lives. So a row is
// marked as applying when `@self.fieldRules` on the case names the same field
// under the same kind. That list is OpenRegister's own answer for this reader
// in this state, which is the only authority on the question.
//
// @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md

/**
 * The two things a role rule can do to a field.
 *
 * The spelling is the contract: OpenRegister keys both the lifecycle block and
 * its published answer on `hidden` and `readOnly`. A row spelled `readonly`
 * matches nothing and is simply never explained.
 */
export const ROLE_RULES = ['hidden', 'readOnly']

/**
 * The reader's word for each rule.
 *
 * @return {object} The labels, by rule id.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
export function roleRuleLabels() {
	return {
		hidden: t('dossiq', 'Not shown'),
		readOnly: t('dossiq', 'Cannot be changed'),
	}
}

/**
 * The declared rules as rows a panel can render.
 *
 * A row naming no field, or a rule this app does not know, is dropped rather
 * than rendered as a blank line: the case type is edited by hand, and a blank
 * row is what somebody leaves behind when they change their mind.
 *
 * @param {Array<object>|null} declared The case type's `fieldRoleRules`.
 * @param {object|null}        decided  The case's `@self.fieldRules`.
 *
 * @return {Array<object>} The rows.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
export function fieldRoleRows(declared, decided) {
	if (Array.isArray(declared) === false) {
		return []
	}

	return declared
		.filter(
			(rule) =>
				rule
				&& ROLE_RULES.includes(rule.rule)
				&& typeof rule.field === 'string'
				&& rule.field.trim() !== '',
		)
		.map((rule) => {
			const field = rule.field.trim()
			return {
				field,
				rule: rule.rule,
				groups: names(rule.groups),
				heldBy: names(rule.heldBy),
				reason: String(rule.reason ?? '').trim(),
				appliesToMe: appliesToMe(field, rule.rule, decided),
			}
		})
}

/**
 * Whether OpenRegister applied this rule to the reader looking at the panel.
 *
 * Answers false when the case carries no decision at all, which is an older
 * OpenRegister rather than a reader who kept everything. Saying "this does not
 * apply to you" on an instance that never answered would be the panel inventing
 * the one thing it is here to report.
 *
 * @param {string}      field The field the rule is about.
 * @param {string}      rule  Which of the two.
 * @param {object|null} decided The case's `@self.fieldRules`.
 *
 * @return {boolean} True when the reader met this rule.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
function appliesToMe(field, rule, decided) {
	const applied = decided?.[rule]
	if (Array.isArray(applied) === false) {
		return false
	}

	return applied.map((name) => String(name)).includes(field)
}

/**
 * A declared list of group names, with the blanks and the repeats gone.
 *
 * @param {unknown} value The declared list.
 *
 * @return {Array<string>} The names.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
function names(value) {
	if (Array.isArray(value) === false) {
		return []
	}

	const found = []
	value.forEach((entry) => {
		const name = String(entry ?? '').trim()
		if (name !== '' && found.includes(name) === false) {
			found.push(name)
		}
	})

	return found
}

/**
 * One row as the sentence the panel shows beside the field.
 *
 * Written as a whole sentence per case rather than assembled from fragments:
 * a translator given "Not shown to" and a list has no way to reorder them, and
 * Dutch puts the group first.
 *
 * @param {object} row One row from `fieldRoleRows`.
 *
 * @return {string} The sentence.
 *
 * @spec openspec/changes/field-rules-declared/specs/security-hardening/spec.md
 */
export function roleRuleSentence(row) {
	const groups = row?.groups?.join(', ') ?? ''
	const heldBy = row?.heldBy?.join(', ') ?? ''

	if (row?.rule === 'hidden' && heldBy !== '') {
		return t('dossiq', '{groups} do not see this field. {heldBy} do.', {
			groups,
			heldBy,
		})
	}

	if (row?.rule === 'hidden') {
		return t('dossiq', '{groups} do not see this field.', { groups })
	}

	if (heldBy !== '') {
		return t('dossiq', '{groups} cannot change this field. {heldBy} can.', {
			groups,
			heldBy,
		})
	}

	return t('dossiq', '{groups} cannot change this field.', { groups })
}
