// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
/**
 * Besluitvorming API service.
 *
 * Wraps the procest /api/besluitvorming endpoints (agenda compilation,
 * DROP/LVBB publication, mandaat validation). All HTTP traffic uses
 * @nextcloud/axios for CSRF + auth interop. Never use raw fetch().
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/procest/api/besluitvorming' + path)

/**
 * Add a case to an agenda with a classification and order.
 *
 * @param {string} caseId The case UUID.
 * @param {string} behandeling 'hamerstuk' | 'bespreekstuk'.
 * @param {number} order The agenda order position.
 * @return {Promise<object>} The updated agenda item.
 */
export async function addToAgenda(caseId, behandeling, order) {
	const response = await axios.post(base('/cases/' + caseId + '/agenda'), { behandeling, order })
	return response.data
}

/**
 * Confirm an agenda for a list of cases on a meeting date.
 *
 * @param {string} vergaderingId The vergadering case UUID.
 * @param {Array<string>} caseIds The ordered case UUIDs.
 * @param {string} meetingDate ISO yyyy-mm-dd meeting date.
 * @return {Promise<object>} The confirmation summary.
 */
export async function confirmAgenda(vergaderingId, caseIds, meetingDate) {
	const response = await axios.put(base('/cases/' + vergaderingId + '/agenda'), { caseIds, meetingDate })
	return response.data
}

/**
 * Generate the agenda document (hamerstukken first).
 *
 * @param {Array<string>} caseIds The case UUIDs on the agenda.
 * @return {Promise<object>} The ordered items and optional document id.
 */
export async function generateAgenda(caseIds) {
	const response = await axios.post(base('/agenda/generate'), { caseIds })
	return response.data
}

/**
 * Trigger (retry) DROP/LVBB publication for a case.
 *
 * @param {string} caseId The case UUID.
 * @return {Promise<object>} The publication result.
 */
export async function publishBesluit(caseId) {
	const response = await axios.post(base('/cases/' + caseId + '/publish'), {})
	return response.data
}

/**
 * Validate the signing official's mandate for a case.
 *
 * @param {string} caseId The case UUID.
 * @param {string} [signingUserId] Optional signing user UID.
 * @return {Promise<object>} The validation result.
 */
export async function mandaatCheck(caseId, signingUserId) {
	const params = signingUserId ? { signingUserId } : {}
	const response = await axios.get(base('/cases/' + caseId + '/mandaat-check'), { params })
	return response.data
}
