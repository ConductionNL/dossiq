/**
 * Procest parafeerroute API client.
 *
 * Thin wrapper around @nextcloud/axios for the parafeerroute admin CRUD and
 * voorstel routing engine endpoints. Uses axios so CSRF headers are attached
 * automatically — never raw fetch().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = () => generateUrl('/apps/procest/api/parafeer-route')

/**
 * List parafeerroutes, optionally filtered.
 *
 * @param {object} [filters] Optional caseType/voorstelType filters
 * @return {Promise<Array>} The list of routes
 */
export async function listRoutes(filters = {}) {
	const response = await axios.get(baseUrl(), { params: filters })
	const data = response.data
	if (Array.isArray(data)) return data
	if (Array.isArray(data?.results)) return data.results
	return []
}

/**
 * Fetch a single parafeerroute.
 *
 * @param {string} id Route UUID
 * @return {Promise<object>}
 */
export async function getRoute(id) {
	const response = await axios.get(`${baseUrl()}/${encodeURIComponent(id)}`)
	return response.data
}

/**
 * Create a parafeerroute.
 *
 * @param {object} data Route fields
 * @return {Promise<object>}
 */
export async function createRoute(data) {
	const response = await axios.post(baseUrl(), data)
	return response.data
}

/**
 * Update a parafeerroute.
 *
 * @param {string} id   Route UUID
 * @param {object} data Fields to merge
 * @return {Promise<object>}
 */
export async function updateRoute(id, data) {
	const response = await axios.put(`${baseUrl()}/${encodeURIComponent(id)}`, data)
	return response.data
}

/**
 * Delete a parafeerroute.
 *
 * @param {string} id Route UUID
 * @return {Promise<object>}
 */
export async function deleteRoute(id) {
	const response = await axios.delete(`${baseUrl()}/${encodeURIComponent(id)}`)
	return response.data
}

/**
 * Start parafering on a voorstel.
 *
 * @param {string} voorstelId Voorstel UUID
 * @return {Promise<object>}
 */
export async function startParafering(voorstelId) {
	const response = await axios.post(`${baseUrl()}/voorstel/${encodeURIComponent(voorstelId)}/start`)
	return response.data
}

/**
 * Complete the active step on a voorstel.
 *
 * @param {string} voorstelId Voorstel UUID
 * @param {object} data       Parafeeractie payload
 * @return {Promise<object>}
 */
export async function completeStep(voorstelId, data) {
	const response = await axios.post(`${baseUrl()}/voorstel/${encodeURIComponent(voorstelId)}/complete-step`, data)
	return response.data
}

/**
 * Skip a step on a voorstel.
 *
 * @param {string} voorstelId Voorstel UUID
 * @param {object} data       { step: number, reason: string }
 * @return {Promise<object>}
 */
export async function skipStep(voorstelId, data) {
	const response = await axios.post(`${baseUrl()}/voorstel/${encodeURIComponent(voorstelId)}/skip-step`, data)
	return response.data
}

/**
 * Insert an ad-hoc step into the voorstel route snapshot.
 *
 * @param {string} voorstelId Voorstel UUID
 * @param {object} data       { afterStep: number, stepData: object }
 * @return {Promise<object>}
 */
export async function addStep(voorstelId, data) {
	const response = await axios.post(`${baseUrl()}/voorstel/${encodeURIComponent(voorstelId)}/add-step`, data)
	return response.data
}

export default {
	listRoutes,
	getRoute,
	createRoute,
	updateRoute,
	deleteRoute,
	startParafering,
	completeStep,
	skipStep,
	addStep,
}
