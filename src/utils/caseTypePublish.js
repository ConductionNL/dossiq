// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What to say when a case-type gesture is refused.
//
// The three refusals a person meets here are genuinely different, and telling
// them apart is the whole job: 403 means their account may not do this, 404
// means the case type is gone, and anything else is a fault they cannot fix
// and should not be shown the internals of. A single "Something went wrong"
// would send an ordinary user hunting for a problem in their own data that
// only an administrator can solve.
//
// Pure, so the mapping is testable without a server, a mount or a dialog. The
// SENTENCES arrive already translated rather than through a translate callback:
// `tests/l10n/check-l10n.js` extracts a translatable string by finding a
// literal inside a `t()` call for this app, and a literal in this file handed
// to a callback named anything else is invisible to it — it would never reach
// l10n/en.json, never reach a translator, and render in English to a Dutch
// reader with every check green.
//
// @spec openspec/specs/zaaktype-versioning/spec.md
// @spec openspec/specs/workflow-import-export/spec.md

/** The English fallbacks, so a missing message renders a sentence. */
const DEFAULT_MESSAGES = {
	signIn: 'Sign in again and retry.',
	forbidden: 'Your account may not do this.',
	missing: 'This case type no longer exists.',
	generic: 'That did not work. Try again, or ask an administrator.',
}

/** The English fallbacks for the import strategies. */
const DEFAULT_STRATEGY_LABELS = {
	skip: 'Keep what is already here',
	merge: 'Merge the bundle into it',
	overwrite: 'Replace it with the bundle',
}

/**
 * The message for a refused publish, import or duplicate.
 *
 * @param {object} error An axios error, or anything at all.
 * @param {object} messages The already-translated sentences: `signIn`,
 *   `forbidden`, `missing` and `generic`.
 * @return {string} A sentence to show the person.
 */
export function publishRefusalMessage(error, messages = {}) {
	const text = { ...DEFAULT_MESSAGES, ...messages }
	const status = Number(error?.response?.status ?? 0)

	if (status === 401) {
		return text.signIn
	}

	if (status === 403) {
		return text.forbidden
	}

	if (status === 404) {
		return text.missing
	}

	// Never the server's own message: it carries paths and driver text.
	return text.generic
}

/**
 * The findings an error response carries, if any.
 *
 * A 422 from the publish endpoint carries the same finding list the validate
 * endpoint answers, because the server validates again: a case type can be
 * edited between the two calls, and the server is the one that decides.
 *
 * @param {object} error An axios error, or anything at all.
 * @return {Array<string>} The findings, empty when there are none.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
export function findingsFrom(error) {
	const findings = error?.response?.data?.findings

	return Array.isArray(findings)
		? findings.filter((f) => typeof f === 'string')
		: []
}

/**
 * The three collision strategies the import endpoint accepts.
 *
 * Skip leads, and is the default the dialog picks: it is the only one of the
 * three that cannot lose work, and a person importing a colleague's bundle
 * rarely means to overwrite what this instance already has on the first try.
 *
 * @param {object} labels The already-translated labels, by strategy id.
 * @return {Array<{id: string, label: string}>} The options, in order.
 */
export function importStrategies(labels = {}) {
	const text = { ...DEFAULT_STRATEGY_LABELS, ...labels }

	return [
		{ id: 'skip', label: text.skip },
		{ id: 'merge', label: text.merge },
		{ id: 'overwrite', label: text.overwrite },
	]
}

/**
 * The id of the case type a copy call created.
 *
 * The copy endpoint answers the new object, and OpenRegister writes an id in
 * either of two places depending on how the row was serialised. Reading only
 * one is how a Duplicate silently leaves you on the original.
 *
 * @param {object} data The copy endpoint's answer.
 * @return {string} The new id, or '' when the answer carries none.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
export function copiedCaseTypeId(data) {
	return String(data?.id ?? data?.['@self']?.id ?? '')
}
