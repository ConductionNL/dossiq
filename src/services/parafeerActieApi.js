// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
/**
 * Parafeeractie read service.
 *
 * The sign-off RUNTIME moved to the decision app (parafering-runtime-to-decidiq):
 * an approver no longer records a parafeeractie through a dossiq endpoint that
 * advances a local chain — they sign in the decision app, and dossiq records
 * the outcome from its conclusion event. What remains here is the READ the case
 * detail shows: the parafeeracties the conclusion recorder wrote, listed
 * straight from OpenRegister's object API. There is no recordAction() any more.
 *
 * All HTTP traffic uses @nextcloud/axios for CSRF + auth interop. Never raw fetch().
 *
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const OR_BASE = 'apps/openregister/api/objects/dossiq/parafeeractie'

/**
 * List all parafeeracties for a voorstel, sorted chronologically.
 *
 * Reads OpenRegister's auto-exposed object API, filtered to the voorstel, so
 * the case detail can show who initialled what when without a dossiq endpoint.
 *
 * @param {string} voorstelId The voorstel UUID.
 * @return {Promise<Array<object>>} The parafeeractie array, oldest first.
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */
export async function listActions(voorstelId) {
	const response = await axios.get(generateUrl(`/${OR_BASE}`), {
		params: { proposal: voorstelId, _limit: 500 },
	})
	const results = Array.isArray(response.data?.results)
		? response.data.results
		: Array.isArray(response.data)
			? response.data
			: []
	return results.slice().sort((a, b) => {
		const aKey = a.createdAt || a.created || a['@self']?.created || ''
		const bKey = b.createdAt || b.created || b['@self']?.created || ''
		return String(aKey).localeCompare(String(bKey))
	})
}

export default {
	listActions,
}
