/**
 * Dossiq voorstel besluit-registration API client.
 *
 * Thin wrapper around @nextcloud/axios for the voorstel→besluit delegation
 * endpoint. Registering a besluit on a voorstel raises a decidesk
 * `report-adoption` Decision via the ADR-019 integration registry
 * (procest-delegate-remaining-decisions-to-decidesk); the besluit becomes a
 * projection of the decidesk outcome rather than a dossiq-authored decision
 * object. Fails CLOSED server-side when decidesk is unavailable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Register a besluit on a voorstel by raising a decidesk report-adoption Decision.
 *
 * @param {string} voorstelId Voorstel UUID
 * @param {object} payload    { title, governingBody, explanation }
 * @return {Promise<object>} { voorstelId, decisionRef, status }
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */
export async function registerBesluit(voorstelId, payload) {
	const url = generateUrl(
		'/apps/dossiq/api/voorstellen/{voorstelId}/register-besluit',
		{ voorstelId },
	)
	const response = await axios.post(url, payload)
	return response.data
}
