/**
 * OpenRegister archival client for dossiq.
 *
 * The archiving process lives in openregister (decision D7). dossiq reads what
 * openregister decided and relays a reviewer's answer; it derives nothing here.
 *
 *   GET  /apps/openregister/api/archival/reviews/pending
 *        — the signed-in person's own undecided destruction list entries.
 *   POST /apps/openregister/api/archival/destruction-lists/{list}/entries/{entry}/decision
 *        — destroy, retain or transfer, with a reason.
 *   POST /apps/openregister/api/archival/objects/{id}/nomination/recompute
 *        — recompute a nomination, archivist or admin, reason required.
 *   GET  /apps/openregister/api/settings/archival
 *        — the archival settings, including reviewReminderFrequency.
 *
 * 🔑 THE WORKLIST IS NOT FILTERED HERE. `/archival/reviews/pending` reads the
 * session user id, so there is no id in the request to tamper with. Narrowing a
 * wider list in the browser would be a different, weaker thing wearing the same
 * label, and it is deliberately not done.
 *
 * Nothing in this file swallows a failure into an empty list. An outage and a
 * reviewer with nothing to sign off look identical from the browser, and only
 * one of them is somebody's problem, so a failed read throws and the caller
 * draws an error with a retry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const API = '/apps/openregister/api'

/**
 * The three answers a reviewer may give, as openregister spells them.
 *
 * A fourth answer is refused with a 400, so the list is closed on purpose.
 *
 * @type {string[]}
 */
export const ANSWERS = ['destroy', 'retain', 'transfer']

/**
 * The signed-in person's own pending archival reviews.
 *
 * @return {Promise<Array<object>>} The caller's undecided entries.
 * @throws {Error} When openregister cannot be read.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
export async function pendingReviews() {
	const { data } = await axios.get(generateUrl(`${API}/archival/reviews/pending`))

	const results = data?.results ?? data?.entries ?? []

	return Array.isArray(results) ? results : []
}

/**
 * Answer one destruction list entry.
 *
 * `retain` carries the new archiefactiedatum, which openregister requires and
 * refuses the decision without. It is passed through rather than defaulted: a
 * date this app invented would be recorded as the reviewer's.
 *
 * @param {object} decision The answer.
 * @param {string} decision.listId The destruction list uuid.
 * @param {string} decision.entryId The record's uuid, which is the entry id.
 * @param {string} decision.answer One of destroy, retain or transfer.
 * @param {string} decision.reason Why, required by openregister.
 * @param {string} [decision.newArchiefactiedatum] The new date, for retain.
 * @return {Promise<object>} The recorded decision.
 * @throws {Error} When openregister refuses the answer.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
export async function decide({
	listId,
	entryId,
	answer,
	reason,
	newArchiefactiedatum = null,
}) {
	const body = { answer, reason }
	if (newArchiefactiedatum) {
		body.newArchiefactiedatum = newArchiefactiedatum
	}

	const { data } = await axios.post(
		generateUrl(
			`${API}/archival/destruction-lists/${listId}/entries/${entryId}/decision`,
		),
		body,
	)

	return data
}

/**
 * Ask openregister to derive this record's nomination again.
 *
 * @param {string} objectId The record uuid.
 * @param {string} reason Why the recomputation was asked for, required.
 * @return {Promise<object>} The nomination openregister wrote.
 * @throws {Error} When openregister refuses.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
export async function recomputeNomination(objectId, reason) {
	const { data } = await axios.post(
		generateUrl(`${API}/archival/objects/${objectId}/nomination/recompute`),
		{ reason },
	)

	return data
}

/**
 * openregister's archival settings.
 *
 * @return {Promise<object>} The settings, including reviewReminderFrequency.
 * @throws {Error} When openregister cannot be read.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
export async function archivalSettings() {
	const { data } = await axios.get(generateUrl(`${API}/settings/archival`))

	return data ?? {}
}

/**
 * Write openregister's review reminder frequency.
 *
 * dossiq keeps no copy: a second store of the same setting is one an
 * administrator can change without changing when anybody is reminded.
 *
 * @param {string} frequency An ISO 8601 duration, for example P7D.
 * @return {Promise<object>} The settings as openregister stored them.
 * @throws {Error} When openregister refuses the value.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
export async function saveReviewReminderFrequency(frequency) {
	const { data } = await axios.post(generateUrl(`${API}/settings/archival`), {
		reviewReminderFrequency: frequency,
	})

	return data ?? {}
}

/**
 * Is this an ISO 8601 duration openregister will accept?
 *
 * Checked before the request so a typo reads as a refused field rather than as
 * a server error, and so an empty box never silently writes nothing.
 *
 * @param {string} value The candidate.
 * @return {boolean} True when it is a duration.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
export function isIsoDuration(value) {
	return /^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/.test(
		String(value ?? ''),
	)
}

/**
 * The retention block openregister publishes on a case.
 *
 * `@self._retention` is metadata attached on the render path, not a stored
 * property, so it is read off the object rather than asked for as a field.
 *
 * Returned beside it are `@self.archived`, the platform's archive marker from
 * openregister#3772, and the case's own `archiveStatus`. Both describe the same
 * event and only one of them is the platform's, so the panel shows the marker
 * and says when the case's own field disagrees with it.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object>} `retention`, `archived` and `archiveStatus`.
 * @throws {Error} When the case cannot be read.
 *
 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
 */
export async function caseRetention(caseId) {
	const { data } = await axios.get(
		generateUrl(`${API}/objects/dossiq/case/${caseId}`),
	)

	const self = data?.['@self'] ?? {}

	return {
		retention: self._retention ?? null,
		archived: self.archived ?? null,
		archiveStatus: String(data?.archiveStatus ?? ''),
	}
}
