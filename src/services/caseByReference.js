// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * The case a record moved onto. A record a case type imported keeps its own
 * id as the case's `externalReference`, so an old link to the record still
 * finds the case.
 *
 * @spec openspec/changes/re-integration-page-retires/specs/dossiq-sociaal-domein-participatiewet/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The id of the case whose `externalReference` is this reference.
 *
 * @param {string} reference The earlier reference, such as the moved record's id.
 * @return {Promise<string>} The case id, or '' when none carries it or the lookup failed.
 */
export async function caseIdByReference(reference) {
	if (!reference) {
		return ''
	}
	try {
		const { data } = await axios.get(
			generateUrl('/apps/openregister/api/objects/dossiq/case'),
			{ params: { externalReference: reference, _limit: 1 } },
		)
		const row = Array.isArray(data?.results) ? data.results[0] : null
		return String(row?.id ?? row?.['@self']?.id ?? '')
	} catch {
		return ''
	}
}
