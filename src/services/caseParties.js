/**
 * The parties on a case, in their roles, and what their indicators refuse.
 *
 * 🔴 EVERY LINE BELOW IS A READ, AND IT READS OPENREGISTER DIRECTLY. The party
 * model is OpenRegister's (openregister#3761) and ADR-022 says a leaf app
 * consumes the abstraction rather than wrapping it, the way `caseAccessApi.js`
 * already consumes the permission reads. A dossiq endpoint in front of these
 * would be a second place to keep in step with a contract dossiq does not own.
 *
 *   GET /apps/openregister/api/objects/{register}/{schema}/{id}/parties
 *       The links, grouped by role, with the kinds and roles the case schema
 *       declares and the uuid of the primary party. One read gives the widget
 *       its rows AND its vocabulary, so a role renders under its label rather
 *       than under its key.
 *
 *   GET /apps/openregister/api/parties/{partyUuid}
 *       One party's own record: its kind, its addresses and its indicators.
 *       The listing above carries links, and an indicator lives on the PARTY,
 *       so it is not on a link. This is asked once per party that has a uuid,
 *       which is the parties on the case and not the user or contact links
 *       written before the party model, so a case with no parties makes no
 *       extra call at all.
 *
 *   GET /apps/openregister/api/parties/resolve?address=
 *       The party already holding an inbound address. Asked BEFORE a second
 *       record is created for the same person.
 *
 * WHY A FAILED READ IS `null` AND NEVER AN EMPTY LISTING. A case with nobody
 * on it is a real answer and the widget says so in words. "We could not ask"
 * is a different answer, and rendering it as an empty party list would tell a
 * handler that the gemachtigde they added is gone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/roles-decisions/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { CASE_REGISTER, CASE_SCHEMA } from './caseAccessApi.js'

/**
 * The parties on a case, grouped by role.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object|null>} The listing, or null when it could not be read.
 * @spec openspec/specs/roles-decisions/spec.md
 */
export async function fetchCaseParties(caseId) {
	if (!caseId) {
		return null
	}
	try {
		const { data } = await axios.get(
			generateUrl(
				`/apps/openregister/api/objects/${CASE_REGISTER}/${CASE_SCHEMA}/${encodeURIComponent(caseId)}/parties`,
			),
		)
		return data && typeof data === 'object' ? data : null
	} catch {
		return null
	}
}

/**
 * The party already holding an inbound address, so a second record for the
 * same person is never created.
 *
 * A 404 is an answer, not a failure: nobody holds that address yet. Both come
 * back as null, because the caller does the same thing either way — it creates
 * the party it was going to create. What this call prevents is the case where
 * somebody DOES hold it.
 *
 * @param {string} address An email address, or any address a party may hold.
 * @return {Promise<object|null>} The party holding it, or null.
 * @spec openspec/specs/roles-decisions/spec.md
 */
export async function resolvePartyByAddress(address) {
	const value = String(address || '').trim()
	if (value === '') {
		return null
	}
	try {
		const { data } = await axios.get(
			generateUrl('/apps/openregister/api/parties/resolve'),
			{ params: { address: value } },
		)
		if (!data || typeof data !== 'object') {
			return null
		}
		// The endpoint answers the party itself; an instance that answers a
		// wrapper is read through `party` rather than guessed at.
		const party = data.party || data
		return party && (party.id || party['@self']?.id || party.uuid) ? party : null
	} catch {
		return null
	}
}
