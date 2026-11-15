// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Advice from the decision table a case type names (REQ-LHS-CASE-01): what
 * the dialog asks, the recommendation, and the handler's choice. Every
 * verdict is the server's; these only carry it.
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * What the dialog asks on a case: the table, its inputs with the values its
 * rules use, and the outcomes a handler can choose.
 *
 * @param {string} caseId The case.
 * @return {Promise<object>} `{ available, decisionTable, inputs, outcomes }`.
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
 */
export async function adviceForm(caseId) {
	const { data } = await axios.get(
		generateUrl(`/apps/dossiq/api/decision-advice/case/${encodeURIComponent(caseId)}/form`),
	)
	return data
}

/**
 * Ask the case type's table for a recommendation; the server stores it on
 * the case.
 *
 * @param {string} caseId The case.
 * @param {object} inputs The values per declared input.
 * @return {Promise<object>} The stored advice.
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
 */
export async function recommend(caseId, inputs) {
	const { data } = await axios.post(
		generateUrl('/apps/dossiq/api/decision-advice/recommend'),
		{ caseId, inputs },
	)
	return data
}

/**
 * Record the handler's choice: the recommendation, or another outcome with
 * a reason.
 *
 * @param {string} adviceId The stored advice.
 * @param {string} outcome The chosen outcome.
 * @param {string} reason Why, required when it is not the recommendation.
 * @return {Promise<object>} The decided advice.
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
 */
export async function decide(adviceId, outcome, reason) {
	const { data } = await axios.post(
		generateUrl('/apps/dossiq/api/decision-advice/override'),
		{ adviceId, outcome, reason },
	)
	return data
}

/**
 * The server's refusal as a sentence, else the fallback.
 *
 * @param {Error} error The failed request.
 * @param {string} fallback What to say when the server said nothing.
 * @return {string} The sentence.
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
 */
export function adviceRefusal(error, fallback) {
	const said = error?.response?.data?.error || error?.response?.data?.message
	return typeof said === 'string' && said !== '' ? said : fallback
}
