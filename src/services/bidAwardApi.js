// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The calls behind proposing a case's award: read the case's scoring sheet
 * and walk one transition of a bid's declared lifecycle on OpenRegister.
 * The server decides every state; these only carry the request.
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the scoring sheet of a case: its bids with total, state and lot, and
 * the refusal when the case cannot be scored.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<{submissions: Array<object>, refusal: string}>}
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */
export async function readScoringSheet(caseId) {
	const { data } = await axios.get(generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/scoring`))
	return {
		submissions: Array.isArray(data?.submissions) ? data.submissions : [],
		refusal: data?.refusal || '',
	}
}

/**
 * Walk one transition of a bid's declared lifecycle.
 *
 * @param {string} bidId The bid uuid.
 * @param {string} action The transition, such as proposeAward or releaseAward.
 * @return {Promise<object>}
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */
export function walkBidTransition(bidId, action) {
	return axios.post(generateUrl(`/apps/openregister/api/objects/${encodeURIComponent(bidId)}/transition`), { action })
}

/**
 * The message a refused call carries, else the fallback.
 *
 * @param {Error} e The axios error.
 * @param {string} fallback What to say when the server said nothing.
 * @return {string}
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */
export function refusalOf(e, fallback) {
	return e?.response?.data?.message || e?.response?.data?.error || fallback
}
