/**
 * The attention flag on a case: where it stands, and the two acts that move it.
 *
 *   GET  /apps/dossiq/api/case/{id}/attention
 *   POST /apps/dossiq/api/case/{id}/attention/raise
 *   POST /apps/dossiq/api/case/{id}/attention/clear
 *
 * 🔴 THE WRITES DO NOT GO TO OPENREGISTER, and that is deliberate rather than
 * an omission. `needsAttention` and `attentionFlagHistory` are fields on the
 * case and OpenRegister would take a patch on either straight from here. What
 * the browser cannot do is refuse a clearing that carries no reason, and it
 * cannot be trusted to send a history array with every earlier row still in
 * it. Both rules live in `CaseAttentionFlagService`, so this file sends a
 * sentence and reads back the whole flag.
 *
 * The risk assessment and the marker set have no client here on purpose. Both
 * arrive as properties of the case the page already read: the assessment
 * because OpenRegister filters it out for a reader without the extra
 * permission before dossiq sees it, the markers because they are derived into
 * the save itself.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The attention endpoint of one case.
 *
 * @param {string} id   The case uuid.
 * @param {string} tail The gesture, or the empty string for the read.
 * @return {string} The absolute url.
 */
function attentionUrl(id, tail = '') {
	const suffix = (tail === '') ? '' : `/${tail}`

	return generateUrl(`/apps/dossiq/api/case/${encodeURIComponent(id)}/attention${suffix}`)
}

/**
 * Where the flag on this case stands, and everything it has been through.
 *
 * @param {string} id The case uuid.
 * @return {Promise<object>} The flag, its history and the two counts.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
export async function fetchAttention(id) {
	const response = await axios.get(attentionUrl(id))

	return (response?.data ?? {})
}

/**
 * Raise the flag on this case, with a reason.
 *
 * @param {string} id     The case uuid.
 * @param {string} reason Why this case needs attention.
 * @return {Promise<object>} The flag as it now stands.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
export async function raiseAttention(id, reason) {
	const response = await axios.post(attentionUrl(id, 'raise'), { reason })

	return (response?.data ?? {})
}

/**
 * Clear the flag on this case, with a reason.
 *
 * @param {string} id     The case uuid.
 * @param {string} reason Why it no longer needs attention.
 * @return {Promise<object>} The flag as it now stands.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
export async function clearAttention(id, reason) {
	const response = await axios.post(attentionUrl(id, 'clear'), { reason })

	return (response?.data ?? {})
}
