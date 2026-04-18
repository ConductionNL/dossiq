// SPDX-License-Identifier: EUPL-1.2
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/advice')

/**
 * Get all advice requests for a case.
 *
 * @param {string} caseId The case UUID
 * @return {Promise<Object>} Response data with advice requests
 */
export async function getAdviceForCase(caseId) {
	const response = await axios.get(baseUrl, { params: { caseId } })
	return response.data
}

/**
 * Create a new advice request.
 *
 * @param {Object} data The advice request data
 * @return {Promise<Object>} The created advice request
 */
export async function createAdvice(data) {
	const response = await axios.post(baseUrl, data)
	return response.data
}

/**
 * Get a single advice request.
 *
 * @param {string} id The advice UUID
 * @return {Promise<Object>} The advice request
 */
export async function getAdvice(id) {
	const response = await axios.get(`${baseUrl}/${id}`)
	return response.data
}

/**
 * Update an advice request.
 *
 * @param {string} id The advice UUID
 * @param {Object} data The update data
 * @return {Promise<Object>} The updated advice request
 */
export async function updateAdvice(id, data) {
	const response = await axios.put(`${baseUrl}/${id}`, data)
	return response.data
}

/**
 * Delete an advice request.
 *
 * @param {string} id The advice UUID
 * @return {Promise<Object>} Response data
 */
export async function deleteAdvice(id) {
	const response = await axios.delete(`${baseUrl}/${id}`)
	return response.data
}

/**
 * Send a reminder notification for an advice request.
 *
 * @param {string} id The advice UUID
 * @return {Promise<Object>} Response data
 */
export async function sendReminder(id) {
	const response = await axios.post(`${baseUrl}/${id}/remind`)
	return response.data
}
