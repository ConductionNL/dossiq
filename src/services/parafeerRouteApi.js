/**
 * Procest parafeerroute engine API client.
 *
 * Thin wrapper around @nextcloud/axios for the voorstel routing engine
 * endpoints (start, complete-step, skip-step, add-step). Uses axios so
 * CSRF headers are attached automatically — never raw fetch().
 *
 * Generic CRUD on parafeerroute objects (list, read, create, update,
 * delete) is served by OpenRegister's auto-exposed
 * /api/objects/<register>/<schema> endpoints and consumed by the
 * manifest-rendered CnIndexPage / CnDetailPage — not this client.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = () => generateUrl('/apps/procest/api/parafeer-route')

/**
 * Start parafering on a voorstel.
 *
 * @param {string} voorstelId Voorstel UUID
 * @return {Promise<object>}
 */
/**
 * @param voorstelId
 * @spec openspec/specs/parafering-actions/spec.md
 */
export async function startParafering(voorstelId) {
	const response = await axios.post(
		`${baseUrl()}/voorstel/${encodeURIComponent(voorstelId)}/start`,
	)
	return response.data
}

/**
 * Complete the active step on a voorstel.
 *
 * @param {string} voorstelId Voorstel UUID
 * @param {object} data       Parafeeractie payload
 * @return {Promise<object>}
 */
/**
 * @param voorstelId
 * @param data
 * @spec openspec/specs/parafering-actions/spec.md
 */
export async function completeStep(voorstelId, data) {
	const response = await axios.post(
		`${baseUrl()}/voorstel/${encodeURIComponent(voorstelId)}/complete-step`,
		data,
	)
	return response.data
}

/**
 * Skip a step on a voorstel.
 *
 * @param {string} voorstelId Voorstel UUID
 * @param {object} data       { step: number, reason: string }
 * @return {Promise<object>}
 */
/**
 * @param voorstelId
 * @param data
 * @spec openspec/specs/parafering-actions/spec.md
 */
export async function skipStep(voorstelId, data) {
	const response = await axios.post(
		`${baseUrl()}/voorstel/${encodeURIComponent(voorstelId)}/skip-step`,
		data,
	)
	return response.data
}

/**
 * Insert an ad-hoc step into the voorstel route snapshot.
 *
 * @param {string} voorstelId Voorstel UUID
 * @param {object} data       { afterStep: number, stepData: object }
 * @return {Promise<object>}
 */
/**
 * @param voorstelId
 * @param data
 * @spec openspec/specs/parafering-actions/spec.md
 */
export async function addStep(voorstelId, data) {
	const response = await axios.post(
		`${baseUrl()}/voorstel/${encodeURIComponent(voorstelId)}/add-step`,
		data,
	)
	return response.data
}

export default {
	startParafering,
	completeStep,
	skipStep,
	addStep,
}
