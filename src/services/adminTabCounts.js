/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The numbers beside the admin page's Integrations and Map layers tabs
 * (board DqInstellingenAlgemeen): how many connections dossiq has in
 * integriq's registry and how many map layers are set up.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The OpenRegister lists each counted tab reads, with the filter it applies. */
const SOURCES = {
	integrations: { path: '/apps/openregister/api/objects/integriq/app_connection', params: { app: 'dossiq' } },
	'map-layers': { path: '/apps/openregister/api/objects/dossiq/wmsLayer', params: {} },
}

/**
 * Count one list: OpenRegister reports the total beside the first page.
 *
 * @param {{path: string, params: object}} source The list.
 * @return {Promise<number|null>} The total, or null when it could not be read.
 */
async function countOf(source) {
	try {
		const { data } = await axios.get(generateUrl(source.path), { params: { ...source.params, _limit: 1 } })
		const total = Number(data?.total)
		return Number.isInteger(total) && total >= 0 ? total : null
	} catch {
		return null
	}
}

/**
 * The tab counts. A list that could not be read has no count, so its tab
 * shows no number rather than a wrong one.
 *
 * @return {Promise<Record<string, number>>} The counts by tab id.
 * @spec openspec/specs/admin-settings/spec.md
 */
export async function fetchAdminTabCounts() {
	const ids = Object.keys(SOURCES)
	const totals = await Promise.all(ids.map((id) => countOf(SOURCES[id])))
	const counts = {}
	ids.forEach((id, index) => {
		if (totals[index] !== null) {
			counts[id] = totals[index]
		}
	})
	return counts
}
