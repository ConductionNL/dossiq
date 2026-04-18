// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/advice')

/**
 * Get advice requests for a case.
 *
 * @param {string} caseId The case UUID
 * @return {Promise<object>} API response with advice list
 * @spec openspec/changes/advice-management/tasks.md#task-8
 */
export async function getAdviceForCase(caseId) {
	const response = await axios.get(baseUrl, { params: { case: caseId } })
	return response.data
}

/**
 * Create a new advice request.
 *
 * @param {object} data The advice request data
 * @return {Promise<object>} API response with created advice
 * @spec openspec/changes/advice-management/tasks.md#task-8
 */
export async function createAdvice(data) {
	const response = await axios.post(baseUrl, data)
	return response.data
}

/**
 * Get a single advice request.
 *
 * @param {string} id The advice request UUID
 * @return {Promise<object>} API response with advice data
 * @spec openspec/changes/advice-management/tasks.md#task-8
 */
export async function getAdvice(id) {
	const response = await axios.get(`${baseUrl}/${id}`)
	return response.data
}

/**
 * Update an advice request (mark as received).
 *
 * @param {string} id The advice request UUID
 * @param {object} data The update data
 * @return {Promise<object>} API response with updated advice
 * @spec openspec/changes/advice-management/tasks.md#task-8
 */
export async function updateAdvice(id, data) {
	const response = await axios.put(`${baseUrl}/${id}`, data)
	return response.data
}

/**
 * Delete an advice request.
 *
 * @param {string} id The advice request UUID
 * @return {Promise<object>} API response
 * @spec openspec/changes/advice-management/tasks.md#task-8
 */
export async function deleteAdvice(id) {
	const response = await axios.delete(`${baseUrl}/${id}`)
	return response.data
}

/**
 * Send a reminder for an advice request.
 *
 * @param {string} id The advice request UUID
 * @return {Promise<object>} API response
 * @spec openspec/changes/advice-management/tasks.md#task-8
 */
export async function sendReminder(id) {
	const response = await axios.post(`${baseUrl}/${id}/remind`)
	return response.data
}
