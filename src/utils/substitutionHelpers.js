/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for the substitution (vervanging/waarneming) My Work
 * integration — building the "substituted by" lookup map, merging substituted
 * cases into the user's own case list without duplicating, and filtering
 * substituted items out when the show/hide toggle is off.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

/**
 * Build a lookup map of "type:id" -> absentee for substituted cases + tasks.
 *
 * @param {Array} cases Substituted case objects (each may carry _substituted).
 * @param {Array} tasks Substituted task objects (each may carry _substituted).
 * @return {Record<string, string>} Map keyed by `case:<id>` / `task:<id>`.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export function buildSubstitutedMap(cases = [], tasks = []) {
	const map = {}
	for (const c of cases || []) {
		map[`case:${c.id}`] = (c._substituted && c._substituted.absentee) || ''
	}
	for (const tk of tasks || []) {
		map[`task:${tk.id}`] = (tk._substituted && tk._substituted.absentee) || ''
	}
	return map
}

/**
 * Merge substituted cases into the own-case list without duplicating ids.
 *
 * @param {Array} ownCases The user's own cases.
 * @param {Array} substitutedCases Cases routed via active substitution.
 * @return {Array} The combined list (own first, then unseen substituted).
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export function mergeSubstitutedCases(ownCases = [], substitutedCases = []) {
	const seen = new Set((ownCases || []).map((c) => c.id))
	const merged = [...(ownCases || [])]
	for (const c of substitutedCases || []) {
		if (!seen.has(c.id)) {
			seen.add(c.id)
			merged.push(c)
		}
	}
	return merged
}

/**
 * Resolve the absentee a given My Work item is substituted for (or '').
 *
 * @param {Record<string, string>} map The substituted lookup map.
 * @param {{type: string, id: string}} item The My Work item.
 * @return {string} The absentee name, or '' when the item is own work.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export function substitutedFor(map, item) {
	if (!map || !item) {
		return ''
	}
	return map[`${item.type}:${item.id}`] || ''
}

/**
 * Filter substituted items out of a list when substituted work is hidden.
 *
 * @param {Array} items The items to filter.
 * @param {Record<string, string>} map The substituted lookup map.
 * @param {boolean} showSubstituted Whether substituted work is shown.
 * @return {Array} The (possibly) filtered list.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export function applySubstitutedFilter(
	items = [],
	map = {},
	showSubstituted = true,
) {
	if (showSubstituted) {
		return items || []
	}
	return (items || []).filter((i) => !substitutedFor(map, i))
}
