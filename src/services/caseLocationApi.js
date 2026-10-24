/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Saving an address on a case as a `case-location`, and reading the
 * cadastral parcel reference out of a PDOK Locatieserver result.
 *
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The parts of a PDOK `gekoppeld_perceel` reference such as `ZDR00-K-1234`.
 *
 * @param {object|null} result A PDOK lookup result.
 * @return {{kadastraleGemeenteCode: string, sectie: string, perceelnummer: string, label: string}|null} The parcel, or null when the result names none.
 * @spec openspec/specs/case-dashboard-view/spec.md
 */
export function parcelOf(result) {
	const linked = result?.gekoppeld_perceel
	const raw = Array.isArray(linked) ? linked[0] : linked
	if (typeof raw !== 'string') {
		return null
	}
	const match = raw.match(/^([A-Z0-9]+)-([A-Z]+)-(\d+)$/i)
	if (match === null) {
		return null
	}
	return {
		kadastraleGemeenteCode: match[1],
		sectie: match[2],
		perceelnummer: match[3],
		label: raw,
	}
}

/**
 * The `case-location` record for an address chosen from the PDOK search.
 *
 * @param {string} caseId The case the location belongs to.
 * @param {{result: object, coordinates: object|null, address: string}} chosen What the address search (CnAddressSearch) emitted.
 * @return {object} The record to save.
 * @spec openspec/specs/case-dashboard-view/spec.md
 */
export function caseLocationRecord(caseId, chosen) {
	const result = chosen?.result || {}
	const coordinates = chosen?.coordinates || {}
	const parcel = parcelOf(result)
	return {
		case: caseId,
		label: chosen?.address || result.weergavenaam || '',
		formattedAddress: chosen?.address || result.weergavenaam || '',
		latitude: Number.isFinite(coordinates.lat) ? coordinates.lat : null,
		longitude: Number.isFinite(coordinates.lng) ? coordinates.lng : null,
		addressDesignationId: result.nummeraanduiding_id || null,
		parcelId: parcel ? parcel.label : null,
		source: 'pdok',
	}
}

/**
 * Save a location on a case.
 *
 * @param {object} record The `case-location` record.
 * @return {Promise<object>} The saved object.
 * @spec openspec/specs/case-dashboard-view/spec.md
 */
export async function saveCaseLocation(record) {
	const { data } = await axios.post(generateUrl('/apps/openregister/api/objects/dossiq/case-location'), record)
	return data
}
