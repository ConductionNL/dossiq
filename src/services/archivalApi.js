/**
 * OpenRegister archival client for dossiq.
 *
 * The archiving process lives in openregister (decision D7). dossiq reads what
 * openregister decided; it derives nothing here. A reviewer's pending entries
 * and their answers are the library's decision-list widget on My Work.
 *
 *   POST /apps/openregister/api/archival/objects/{id}/nomination/recompute
 *        — recompute a nomination, archivist or admin, reason required.
 *   GET  /apps/openregister/api/settings/archival
 *        — the archival settings, including reviewReminderFrequency.
 *
 * Nothing in this file swallows a failure into an empty list. An outage and a
 * reviewer with nothing to sign off look identical from the browser, and only
 * one of them is somebody's problem, so a failed read throws and the caller
 * draws an error with a retry.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const API = '/apps/openregister/api'

/**
 * Ask openregister to derive this record's nomination again.
 *
 * @param {string} objectId The record uuid.
 * @param {string} reason Why the recomputation was asked for, required.
 * @return {Promise<object>} The nomination openregister wrote.
 * @throws {Error} When openregister refuses.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
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
 * @spec openspec/specs/archief-edepot-handover/spec.md
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
 * @spec openspec/specs/archief-edepot-handover/spec.md
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
 * @spec openspec/specs/archief-edepot-handover/spec.md
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
 * @spec openspec/specs/archief-edepot-handover/spec.md
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
