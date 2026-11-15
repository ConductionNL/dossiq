/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The step a case moves to next, read from its case type's statuses.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
 */

import { getUserLocale, resolveTranslatable } from './i18nResolver.js'

/**
 * The status after the current one, numbered as a step.
 *
 * @param {Array<object>|null} statusTypes The case type's statuses, `{ id, name, order }`, in any order; a name may be language-tagged (`{ nl, en }`).
 * @param {string} currentId The id of the status the case is in.
 * @return {{number: number, name: string}|null} The next step, or null when there is none.
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-is-decided-from-its-assessments-in-one-dialog-req-das-003
 */
export function nextStepOf(statusTypes, currentId) {
	if (!Array.isArray(statusTypes) || !currentId) {
		return null
	}
	const ordered = statusTypes
		.map((status, index) => ({ status, index }))
		.sort((a, b) => (Number(a.status?.order ?? a.index) - Number(b.status?.order ?? b.index)) || (a.index - b.index))
		.map((entry) => entry.status)
	const at = ordered.findIndex((status) => String(status?.id ?? '') === String(currentId))
	if (at === -1 || at === ordered.length - 1) {
		return null
	}

	return { number: at + 2, name: resolveTranslatable(ordered[at + 1]?.name ?? '', getUserLocale()).text }
}
