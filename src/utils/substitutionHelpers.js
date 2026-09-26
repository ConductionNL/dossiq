/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for the substitution (vervanging/waarneming) My work
 * integration: building the "substituted for" lookup map, merging substituted
 * work into the reader's own list without duplicating, filtering substituted
 * items out when the show/hide toggle is off, and remembering that toggle.
 *
 * Every export here is called from `src/views/MyWorkCards.vue` or
 * `src/views/widgets/MyWorkWidget.vue`. That is asserted mechanically by
 * `tests/vitest/substitutedWorkOnMyWork.spec.js`, because these helpers shipped
 * with no call site at all: the file was written, reviewed and merged, and
 * nothing imported it for two months while the spec scenario it serves carried
 * an `@e2e exclude` saying so.
 *
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */

/** Where the toggle state is remembered, per browser profile. */
const TOGGLE_KEY = 'dossiq:my-work:show-substituted'

/**
 * The identity of an OpenRegister row, however the API spelled it.
 *
 * A row read straight off the object store carries `id`; one serialised with
 * its metadata block carries `@self.id` instead. Both mean the same object,
 * and a map keyed on only one of the two silently marks nothing.
 *
 * @param {object} row The case or task row.
 * @return {string} The id, or '' when the row carries none.
 */
function idOf(row) {
	if (!row) {
		return ''
	}
	return String(row.id || (row['@self'] && row['@self'].id) || '')
}

/**
 * Build a lookup map of "type:id" -> substitution context.
 *
 * The value is the `_substituted` block the resolver annotates each routed row
 * with: the absentee it belongs to, the substitution's id, and the day the
 * routing stops (`until`), which is the substitution's own end date unless an
 * approved humaniq leave set a later one.
 *
 * @param {Array} cases Substituted case objects (each may carry _substituted).
 * @param {Array} tasks Substituted task objects (each may carry _substituted).
 * @return {Record<string, object>} Map keyed by `case:<id>` / `task:<id>`.
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
export function buildSubstitutedMap(cases = [], tasks = []) {
	const map = {}
	for (const c of cases || []) {
		const id = idOf(c)
		if (id) {
			map[`case:${id}`] = c._substituted || {}
		}
	}
	for (const tk of tasks || []) {
		const id = idOf(tk)
		if (id) {
			map[`task:${id}`] = tk._substituted || {}
		}
	}
	return map
}

/**
 * Stamp the list key onto each routed row so the helpers below can address it.
 *
 * The resolver answers cases and tasks as they are stored, and neither carries
 * the `type` half of the map key. Adding it here keeps that knowledge in one
 * place rather than in each view's template.
 *
 * @param {Array} rows The routed rows.
 * @param {string} type Either `case` or `task`.
 * @return {Array} The same rows, each with `type` and a resolved `id`.
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
export function asSubstitutedItems(rows = [], type = 'case') {
	return (rows || []).map((row) => ({ ...row, type, id: idOf(row) }))
}

/**
 * Merge substituted work into the reader's own list without duplicating ids.
 *
 * Id-keyed and item-agnostic: the cases index merges cases, the dashboard tile
 * merges tasks, and neither can introduce a second dedupe rule.
 *
 * @param {Array} ownItems The user's own cases or tasks.
 * @param {Array} substitutedItems Items routed via an active substitution.
 * @return {Array} The combined list (own first, then unseen substituted).
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
export function mergeSubstitutedCases(ownItems = [], substitutedItems = []) {
	const seen = new Set((ownItems || []).map((c) => idOf(c)))
	const merged = [...(ownItems || [])]
	for (const c of substitutedItems || []) {
		const id = idOf(c)
		if (!seen.has(id)) {
			seen.add(id)
			merged.push(c)
		}
	}
	return merged
}

/**
 * Resolve the absentee a given My work item is substituted for (or '').
 *
 * @param {Record<string, object>} map The substituted lookup map.
 * @param {{type: string, id: string}} item The My work item.
 * @return {string} The absentee's user id, or '' when the item is own work.
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
export function substitutedFor(map, item) {
	if (!map || !item) {
		return ''
	}
	const entry = map[`${item.type}:${item.id}`]
	return (entry && entry.absentee) || ''
}

/**
 * Resolve the day the routing of a given item stops (or '').
 *
 * Second half of the marker the requirement asks for: it names the absentee
 * and the end date, so a reader knows both whose work this is and how long it
 * stays on their list.
 *
 * @param {Record<string, object>} map The substituted lookup map.
 * @param {{type: string, id: string}} item The My work item.
 * @return {string} The end date (`YYYY-MM-DD`), or '' when unknown.
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
export function substitutedUntil(map, item) {
	if (!map || !item) {
		return ''
	}
	const entry = map[`${item.type}:${item.id}`]
	return (entry && (entry.until || entry.endDate)) || ''
}

/**
 * Filter substituted items out of a list when substituted work is hidden.
 *
 * @param {Array} items The items to filter.
 * @param {Record<string, object>} map The substituted lookup map.
 * @param {boolean} showSubstituted Whether substituted work is shown.
 * @return {Array} The (possibly) filtered list.
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
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

/**
 * Read the remembered toggle state.
 *
 * Storage can be absent, full or blocked (a private window, cleared site
 * data), and it throws rather than answering in every one of those cases. The
 * default is "shown": a reader who has never touched the toggle should see the
 * work that was routed to them, not silently miss it.
 *
 * @return {boolean} Whether substituted work is shown.
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
export function readShowSubstituted() {
	try {
		return window.localStorage.getItem(TOGGLE_KEY) !== 'false'
	} catch {
		return true
	}
}

/**
 * Remember the toggle state for the next visit.
 *
 * @param {boolean} showSubstituted Whether substituted work is shown.
 * @return {void}
 * @spec openspec/changes/substituted-work-reaches-my-work/specs/handler-vervanging-waarneming/spec.md
 */
export function writeShowSubstituted(showSubstituted) {
	try {
		window.localStorage.setItem(TOGGLE_KEY, showSubstituted ? 'true' : 'false')
	} catch {
		// A remembered preference is a convenience; losing it changes nothing
		// about what the page shows this visit.
	}
}
