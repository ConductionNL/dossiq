// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * The case a record moved onto. A record a case type imported keeps its own
 * id as the case's `externalReference`, so an old link to the record still
 * finds the case.
 *
 * @spec openspec/specs/dossiq-sociaal-domein-participatiewet/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The id of the case a record moved onto: the case whose `externalReference`
 * is the record's id, else the case the record itself names in `migratedTo`.
 *
 * @param {string} reference The earlier reference, such as the moved record's id.
 * @param {string} [sourceSchema] The schema the record is kept in, to read its `migratedTo`.
 * @return {Promise<string>} The case id, or '' when neither answers or the lookups failed.
 */
export async function caseIdByReference(reference, sourceSchema = '') {
	if (!reference) {
		return ''
	}
	const byCase = await caseIdByExternalReference(reference)
	if (byCase || !sourceSchema) {
		return byCase
	}
	try {
		const { data } = await axios.get(
			generateUrl(`/apps/openregister/api/objects/dossiq/${encodeURIComponent(sourceSchema)}/${encodeURIComponent(reference)}`),
		)
		return String(data?.migratedTo ?? '')
	} catch {
		return ''
	}
}

/**
 * The id of the case whose `externalReference` is this reference.
 *
 * @param {string} reference The earlier reference.
 * @return {Promise<string>} The case id, or ''.
 */
async function caseIdByExternalReference(reference) {
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
