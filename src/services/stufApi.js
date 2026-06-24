// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Thin frontend API client for the StUF-ZKN/BG outbound gateway. All endpoints
// are documented in lib/Controller/StufController.php and routed by
// appinfo/routes.php (see the "StUF" block).

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/procest' + path)

/**
 * List the configured StUF-ZKN/BG outbound endpoints with their circuit-breaker health.
 *
 * @return {Promise<object>} The endpoint list payload.
 * @spec openspec/specs/stuf-zkn-outbound/spec.md
 */
export async function listEndpoints() {
	const { data } = await axios.get(base('/api/stuf/endpoints'))
	return data
}

/**
 * List StUF SOAP envelope audit-log rows, optionally filtered.
 *
 * @param {object} filters The filter map (endpointId, berichtSoort, direction, status).
 * @return {Promise<object>} The audit-log payload.
 * @spec openspec/specs/stuf-zkn-outbound/spec.md
 */
export async function listMessages(filters = {}) {
	const { data } = await axios.get(base('/api/stuf/messages'), { params: filters })
	return data
}

/**
 * Send a free StUF vrijBericht to the named endpoint (outbound).
 *
 * @param {string} endpointId  The target endpoint id.
 * @param {string} berichtNaam The vrijBericht template name.
 * @param {object} payload     The message payload.
 * @return {Promise<object>} The send result.
 * @spec openspec/specs/stuf-zkn-outbound/spec.md
 */
export async function sendVrijBericht(endpointId, berichtNaam, payload) {
	const { data } = await axios.post(base('/api/stuf/outbound'), {
		endpointId,
		berichtNaam,
		payload,
	})
	return data
}

export default { listEndpoints, listMessages, sendVrijBericht }
