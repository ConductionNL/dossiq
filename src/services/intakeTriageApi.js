/**
 * The intake declaration and the three acts an intake worker performs.
 *
 * A thin client over `IntakeTriageController`. Five endpoints, no store: the
 * declaration is configuration a form reads once per case type, and the three
 * acts each end in a redirect or a refetch the caller already does.
 *
 *   GET  /apps/dossiq/api/intake/case-types/{caseTypeId}/requirements
 *   POST /apps/dossiq/api/cases/{caseId}/refuse
 *   GET  /apps/dossiq/api/intake/triage
 *   POST /apps/dossiq/api/intake/triage/{entryId}/sleep
 *   POST /apps/dossiq/api/intake/fan-out
 *
 * 🔴 A FAILED DECLARATION READ ANSWERS AN EMPTY DECLARATION MARKED `unreadable`,
 * AND THAT IS SAFE ONLY BECAUSE THE SERVER REFUSES ANYWAY. The form then asks
 * for nothing and the save is refused with the field named, which is worse
 * prose than asking first but is not a case created without its channel.
 * Failing the other way, blocking the form when the endpoint is unreachable,
 * would stop a gemeente opening cases because one read timed out. The flag is
 * on the answer rather than in a console line, because a caller can render a
 * flag and nobody reads a console.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { normaliseDeclaration } from '../utils/intakeRequirements.js'

/**
 * What one case type asks for before a case of it exists.
 *
 * @param {string} caseTypeId The case type uuid.
 * @return {Promise<object>} The normalised declaration, never null.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */
export async function fetchIntakeRequirements(caseTypeId) {
	if (!caseTypeId) {
		return normaliseDeclaration({})
	}

	try {
		const response = await axios.get(
			generateUrl(
				`/apps/dossiq/api/intake/case-types/${encodeURIComponent(caseTypeId)}/requirements`,
			),
		)

		return normaliseDeclaration(response?.data)
	} catch {
		return { ...normaliseDeclaration({}), unreadable: true }
	}
}

/**
 * Refuse a case at intake, to the destination its case type declares.
 *
 * Rejects on a refusal rather than swallowing it: whether the case went to
 * Juridische Zaken is exactly what the caller has to tell the handler.
 *
 * @param {string} caseId The case uuid.
 * @param {string} reason Why it is refused.
 * @return {Promise<object>} The refusal record.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
export async function refuseCase(caseId, reason) {
	const response = await axios.post(
		generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/refuse`),
		{ reason },
	)

	return response?.data || {}
}

/**
 * The triage queue, with its sleeping items already taken out.
 *
 * @return {Promise<object>} `{results, sleeping}`.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
export async function fetchTriageQueue() {
	const response = await axios.get(generateUrl('/apps/dossiq/api/intake/triage'))

	return response?.data || { results: [], sleeping: 0 }
}

/**
 * Put one triage item to sleep until a date.
 *
 * @param {string} entryId The intake log entry.
 * @param {string} until   The date it comes back, as YYYY-MM-DD.
 * @param {string} reason  Why nothing is done until then.
 * @return {Promise<object>} The sleep record.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
export async function sleepTriageItem(entryId, until, reason) {
	const response = await axios.post(
		generateUrl(
			`/apps/dossiq/api/intake/triage/${encodeURIComponent(entryId)}/sleep`,
		),
		{ until, reason },
	)

	return response?.data || {}
}

/**
 * Open one case per destination an intake form declares.
 *
 * @param {string} caseTypeId   The intake case type the form maps to.
 * @param {object} submission   The submitted values every case starts from.
 * @param {string} submissionId The submission's own identifier.
 * @return {Promise<object>} `{created, failed, relationHasNoInverse}`.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
export async function fanOutSubmission(caseTypeId, submission, submissionId) {
	const response = await axios.post(
		generateUrl('/apps/dossiq/api/intake/fan-out'),
		{ caseTypeId, submission, submissionId },
	)

	return response?.data || { created: [], failed: [] }
}
