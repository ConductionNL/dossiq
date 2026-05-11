/**
 * Advice API service for Procest.
 *
 * Wraps the /api/advice endpoints for advice request (adviesAanvraag)
 * management on cases. Uses @nextcloud/axios so CSRF tokens are attached
 * automatically.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const APP_BASE = 'apps/procest/api/advice'

/**
 * Build the base URL for an advice endpoint.
 *
 * @param {string} path Optional sub-path (id or id/action)
 * @return {string} Fully qualified Nextcloud URL
 */
function url(path = '') {
	const suffix = path ? `/${path}` : ''
	return generateUrl(`/${APP_BASE}${suffix}`)
}

/**
 * Get advice requests for a case.
 *
 * @param {string} caseId Case UUID
 * @return {Promise<Array>} List of advice records
 */
export async function getAdviceForCase(caseId) {
	const response = await axios.get(url(), { params: { case: caseId } })
	return response.data?.results || []
}

/**
 * Create a new advice request.
 *
 * @param {object} data Advice payload (case, adviseur, type, ...)
 * @return {Promise<object>} Created record
 */
export async function createAdvice(data) {
	const response = await axios.post(url(), data)
	return response.data
}

/**
 * Get a single advice request.
 *
 * @param {string} id Advice UUID
 * @return {Promise<object>} Advice record
 */
export async function getAdvice(id) {
	const response = await axios.get(url(id))
	return response.data
}

/**
 * Update / mark received an advice request.
 *
 * @param {string} id   Advice UUID
 * @param {object} data Update payload (adviesDocument, etc.)
 * @return {Promise<object>} Updated record
 */
export async function updateAdvice(id, data) {
	const response = await axios.put(url(id), data)
	return response.data
}

/**
 * Delete an advice request.
 *
 * @param {string} id Advice UUID
 * @return {Promise<object>} Server confirmation
 */
export async function deleteAdvice(id) {
	const response = await axios.delete(url(id))
	return response.data
}

/**
 * Send a manual reminder to the adviseur.
 *
 * @param {string} id Advice UUID
 * @return {Promise<object>} Server confirmation
 */
export async function sendReminder(id) {
	const response = await axios.post(url(`${id}/remind`))
	return response.data
}

export default {
	getAdviceForCase,
	createAdvice,
	getAdvice,
	updateAdvice,
	deleteAdvice,
	sendReminder,
}
