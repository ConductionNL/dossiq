// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Testing a configured connection from the screen that configures it. Served by
// lib/Controller/ConnectionTestController.php.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/dossiq' + path)

/**
 * Probe one configured StUF endpoint.
 *
 * @param {string} endpointId The endpoint's id.
 * @return {Promise<object>} The state, the status, the reason and the moment
 *                           it was measured.
 * @spec openspec/changes/starter-content-and-templates/specs/admin-settings/spec.md
 */
export async function testStufEndpoint(endpointId) {
	const { data } = await axios.post(base('/api/connections/stuf/' + endpointId + '/test'))
	return data
}

export default { testStufEndpoint }
