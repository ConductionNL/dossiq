// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The words a status shows the applicant, and the fallback when it has none.
//
// The browser half of `lib/Service/Transitions/StatusPublicLabels.php`. Two
// halves rather than one because the two readers are in two languages: the
// public status page is a Vue component and the portal contribution is PHP.
// What matters is that the RULE is written down once per language and read from
// there, instead of being retyped into a template where nothing can test it.
//
// 🔑 THE LABEL FALLS BACK, THE DESCRIPTION DOES NOT. No public label means "the
// name is fine to show", which is true of most statuses anybody writes. No
// public description means nothing was written for the applicant, and the
// internal `description` is not a stand-in: it says what the phase means to a
// handler, and it routinely names an internal check or a colleague by role.
//
// @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md

/** The case field carrying the label the applicant reads. */
export const CASE_LABEL_FIELD = 'statusPublicLabel'

/** The case field carrying the description the applicant reads. */
export const CASE_DESCRIPTION_FIELD = 'statusPublicDescription'

/**
 * A value as a trimmed string, and '' for anything that is not one.
 *
 * @param {unknown} value The candidate.
 * @return {string} The trimmed string, or ''.
 *
 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
 */
function text(value) {
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * What the applicant reads for this status.
 *
 * @param {object} statusType The stored statusType row.
 * @return {string} The public label, the name when there is none, or ''.
 *
 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
 */
export function publicLabelOf(statusType) {
	const row = statusType || {}

	return text(row.publicLabel) || text(row.name)
}

/**
 * What the applicant is told this status means.
 *
 * @param {object} statusType The stored statusType row.
 * @return {string} The public description, or ''.
 *
 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
 */
export function publicDescriptionOf(statusType) {
	return text((statusType || {}).publicDescription)
}

/**
 * The label a case already carries, if it carries one.
 *
 * A case is the only row the applicant is ever handed: the public status page
 * resolves a token to a case rendered without relations, and the portal
 * projects a case to a field whitelist. The fallback to the name has already
 * run server-side, in the case schema's `statusPublicLabel` calculation, so
 * this reads one field and does not go looking for a statusType that is not
 * there.
 *
 * @param {object} caseRow The case, possibly field-projected.
 * @return {string} The label, or ''.
 *
 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
 */
export function labelOnCase(caseRow) {
	return text((caseRow || {})[CASE_LABEL_FIELD])
}

/**
 * The description a case already carries, if it carries one.
 *
 * @param {object} caseRow The case, possibly field-projected.
 * @return {string} The description, or ''.
 *
 * @spec openspec/changes/citizen-status-labels/specs/case-types/spec.md
 */
export function descriptionOnCase(caseRow) {
	return text((caseRow || {})[CASE_DESCRIPTION_FIELD])
}
