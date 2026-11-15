/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case's address text for a list line. A case carries no address of its
 * own: the address is a `case-location` record pointing at the case. Each
 * case is looked up once per page load; the answer lands in a reactive map,
 * so the line that asked re-renders with it.
 *
 * @spec openspec/specs/zakenkaart-map-list/spec.md#requirement-a-map-list-row-reads-address-case-number-and-names-its-type-and-status
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'

const addresses = reactive({})
const started = new Set()

/**
 * The address of a case's first location, '' until it arrives or when the
 * case has none.
 *
 * @param {unknown} caseId The case's id.
 * @return {string} The address text.
 * @spec openspec/specs/zakenkaart-map-list/spec.md#requirement-a-map-list-row-reads-address-case-number-and-names-its-type-and-status
 */
export function caseAddress(caseId) {
	if (typeof caseId !== 'string' || caseId === '') {
		return ''
	}
	if (!started.has(caseId)) {
		started.add(caseId)
		axios.get(generateUrl('/apps/openregister/api/objects/dossiq/case-location'), { params: { case: caseId, _limit: 1 } })
			.then(({ data }) => {
				const first = (data && Array.isArray(data.results) ? data.results : [])[0]
				addresses[caseId] = first ? String(first.label || first.formattedAddress || '') : ''
			})
			.catch(() => {
				started.delete(caseId)
			})
	}
	return addresses[caseId] || ''
}
