/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case's DSO routes: forward a case that came in through the DSO to
 * another competent authority, or start a collaboration with one. The server
 * checks the caller may change the case and records the outcome on it.
 *
 * @spec openspec/changes/vth-cases-run-end-to-end
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The URL of one of a case's DSO routes.
 *
 * @param {string} caseId The case.
 * @param {string} action The route's last segment.
 * @return {string} The URL.
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
export function dsoCaseUrl(caseId, action) {
	return generateUrl('/apps/dossiq/api/dso/cases/{caseId}/{action}', { caseId, action })
}

/**
 * Forward a case to another competent authority.
 *
 * @param {string} caseId The case.
 * @param {string} authority The authority it goes to.
 * @param {string} reason Why.
 * @return {Promise<object>} The server's answer.
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
export async function forwardCase(caseId, authority, reason) {
	const { data } = await axios.post(dsoCaseUrl(caseId, 'doorsturen'), {
		targetBevoegdGezag: authority,
		reason,
	})
	return data
}

/**
 * Start a collaboration on a case with another competent authority.
 *
 * @param {string} caseId The case.
 * @param {string} authority The authority asked to collaborate.
 * @param {string} rationale Why.
 * @return {Promise<object>} The collaboration request the server made.
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
export async function startCollaboration(caseId, authority, rationale) {
	const { data } = await axios.post(dsoCaseUrl(caseId, 'samenwerking'), {
		requestedCompetentAuthority: authority,
		rationale,
	})
	return data
}

/**
 * The sentence a refused or failed call shows.
 *
 * @param {object} error The axios error.
 * @return {string} The sentence.
 * @spec openspec/changes/vth-cases-run-end-to-end
 */
export function dsoErrorOf(error) {
	const status = error?.response?.status
	if (status === 403) {
		return t('dossiq', 'You may not change this case.')
	}
	if (status === 404) {
		return t('dossiq', 'This case could not be found.')
	}
	return t('dossiq', 'The DSO could not be reached. Nothing was sent; try again later.')
}
