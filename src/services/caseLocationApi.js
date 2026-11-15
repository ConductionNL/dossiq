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
 * The `case-location` record for an address chosen from the PDOK search, or
 * typed in while the search does not answer. A chosen address carries its BAG
 * address designation, so it is saved as `bag` and the BAG check applies; a
 * typed one has none and is saved as `free`.
 *
 * @param {string} caseId The case the location belongs to.
 * @param {{result: object|null, coordinates: object|null, address: string, manual?: object}} chosen What the address search (CnAddressSearch) emitted.
 * @return {object} The record to save.
 * @spec openspec/specs/case-dashboard-view/spec.md
 */
export function caseLocationRecord(caseId, chosen) {
	const result = chosen?.result || {}
	const coordinates = chosen?.coordinates || {}
	const address = addressText(chosen, result)
	return {
		case: caseId,
		label: address,
		formattedAddress: address,
		latitude: finiteOrNull(coordinates.lat),
		longitude: finiteOrNull(coordinates.lng),
		...bagFields(result),
	}
}

/**
 * The address as the reader saw it: what the search emitted, else the
 * result's own name.
 *
 * @param {object|null|undefined} chosen What the address search emitted.
 * @param {object} result The PDOK lookup result.
 * @return {string} The address, or ''.
 */
function addressText(chosen, result) {
	return chosen?.address || result.weergavenaam || ''
}

/**
 * A coordinate, or null when it is not a finite number.
 *
 * @param {unknown} value The coordinate.
 * @return {number|null} The coordinate.
 */
function finiteOrNull(value) {
	return Number.isFinite(value) ? value : null
}

/**
 * The BAG fields of a location: its address designation and parcel, and
 * whether it is a BAG address at all.
 *
 * @param {object} result The PDOK lookup result.
 * @return {{addressDesignationId: string|null, parcelId: string|null, source: string}} The fields.
 */
function bagFields(result) {
	const parcel = parcelOf(result)
	return {
		addressDesignationId: result.nummeraanduiding_id || null,
		parcelId: parcel ? parcel.label : null,
		source: result.nummeraanduiding_id ? 'bag' : 'free',
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
