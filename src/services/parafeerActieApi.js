// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
/**
 * Parafeer Actie API service.
 *
 * Wraps the dossiq /api/parafeer-actie endpoints. All HTTP traffic uses
 * @nextcloud/axios for CSRF + auth interop. Never use raw fetch().
 *
 * @spec openspec/changes/parafering-actions/tasks.md#T06
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const ENDPOINT = generateUrl('/apps/dossiq/api/parafeer-actie')

/**
 * Record a parafering action for a voorstel's current step.
 *
 * @param {object}  data            The action payload.
 * @param {string}  data.proposal   The voorstel UUID (required).
 * @param {string}  data.action     One of: 'advised', 'parafered', 'accorded', 'returned'.
 * @param {string} [data.comment]   Optional comment (mandatory when action='returned').
 * @param {string} [data.advice]    Advice text (mandatory when action='advised').
 * @param {string} [data.onBehalfOf] Principal UID when acting as delegate.
 * @param {string} [data.mandate]   Mandate reference when acting as delegate.
 * @return {Promise<object>} The created parafeeractie and updated voorstel.
 */
/**
 * @param data
 * @spec openspec/specs/parafering-actions/spec.md
 */
export async function recordAction(data) {
	const response = await axios.post(ENDPOINT, data)
	return response.data
}

/**
 * List all parafeeracties for a voorstel, sorted chronologically.
 *
 * @param {string} voorstelId The voorstel UUID.
 * @return {Promise<Array<object>>} The parafeeractie array.
 */
/**
 * @param voorstelId
 * @spec openspec/specs/parafering-actions/spec.md
 */
export async function listActions(voorstelId) {
	const response = await axios.get(ENDPOINT, { params: { proposal: voorstelId } })
	return Array.isArray(response.data) ? response.data : []
}

export default {
	recordAction,
	listActions,
}
