/**
 * A refused search, said where it was typed.
 *
 * OpenRegister reads `AND`, `OR`, `NOT`, brackets, `"phrases"` and a leading
 * or trailing `*` in `_search`, and refuses a term it cannot parse with
 * `400 {error, position, term}` rather than evaluating it as a literal string.
 * That refusal is the whole point: a literal fallback returns zero rows and
 * looks exactly like a search that found nothing.
 *
 * It then looks exactly like one on our side too, because
 * `useObjectStore.fetchCollection()` records the failure on
 * `objectStore.errors[type]` and returns `[]`, and the list renders its empty
 * state over it. This module turns that back into a sentence.
 *
 * 🔴 THE POSITION IS RECOVERED FROM THE MESSAGE, NOT READ FROM THE PAYLOAD.
 * `parseResponseError()` in @conduction/nextcloud-vue keeps `body.error` and
 * drops `position` and `term`, the same way `normalizeFacets()` drops a
 * facet's `missing` bucket (ConductionNL/nextcloud-vue#1176). The message
 * format is fixed by `SearchTermSyntaxException::__construct()` as
 * `<reason> at position <n>.`, so it is readable; when it is not, the reason
 * is shown on its own rather than nothing. `details.position` is read first,
 * so the day the library carries it through, this stops guessing.
 *
 * The term needs no payload at all. The reader typed it into the box.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */

/** The status OpenRegister refuses a malformed search term with. */
export const SEARCH_REFUSAL_STATUS = 400

/** `<reason> at position <n>.`, the shape the refusal message is built in. */
const POSITION_IN_MESSAGE = /\bat position (\d+)\.?\s*$/

/**
 * The 1-based position the refusal points at, or null.
 *
 * @param {object} error An ApiError from the object store.
 *
 * @return {number|null} The position, or null when it cannot be read.
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */
function positionOf(error) {
	const carried = error?.details?.position ?? error?.position
	if (Number.isInteger(carried) === true && carried > 0) {
		return carried
	}

	const match = POSITION_IN_MESSAGE.exec(String(error?.message || ''))
	if (match === null) {
		return null
	}

	const parsed = Number(match[1])

	return parsed > 0 ? parsed : null
}

/**
 * A refused search, or null when this failure is not one.
 *
 * A fetch that carried no term cannot have been refused for its grammar, so
 * an unrelated 400 on a list without a search box stays a plain error.
 *
 * @param {object}  refusal       What is known about the failure.
 * @param {object}  [refusal.error] The ApiError the object store recorded.
 * @param {string}  [refusal.term]  The term the reader typed.
 *
 * @return {object|null} `{message, position, term, before, at, after}`, or null.
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */
export function readSearchRefusal({ error, term } = {}) {
	const typed = typeof term === 'string' ? term : ''
	if (error === null || error === undefined || typed.trim() === '') {
		return null
	}

	if (Number(error.status) !== SEARCH_REFUSAL_STATUS) {
		return null
	}

	const position = positionOf(error)
	const index =
		position === null ? typed.length : Math.min(position - 1, typed.length)

	return {
		message: String(error.message || ''),
		position,
		term: typed,
		before: typed.slice(0, index),
		at: position === null ? '' : typed.slice(index, index + 1),
		after: position === null ? '' : typed.slice(index + 1),
	}
}

/**
 * The sentence a reader gets above their own term.
 *
 * @param {object} refusal What `readSearchRefusal` returned.
 * @param {(app: string, text: string, vars?: object) => string} translate A `t`-shaped translator.
 *
 * @return {string} The sentence.
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */
export function searchRefusalHeadline(refusal, translate) {
	const t = translate || ((app, text) => text)

	if (refusal === null || refusal === undefined) {
		return ''
	}

	if (refusal.position === null) {
		return t('dossiq', 'We could not read this search.')
	}

	return t('dossiq', 'We could not read this search from character {position}.', {
		position: refusal.position,
	})
}
