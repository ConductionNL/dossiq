/**
 * The AVG acts a handler takes on a data subject request case.
 *
 * Four endpoints, all of them dossiq's and all of them thin. The erasing, the
 * pseudonymising and the export itself are OpenRegister's
 * (`data-subject-rights-across-the-instance`, openregister#3759 and #3800);
 * dossiq drives them from the case so the acts land on a case file with a
 * handler, a statutory month and a timeline.
 *
 *   POST /apps/dossiq/api/cases/{caseId}/avg/erasure-preview
 *   POST /apps/dossiq/api/cases/{caseId}/avg/erasure-run
 *   POST /apps/dossiq/api/cases/{caseId}/avg/subject-export
 *   GET  /apps/dossiq/api/cases/{caseId}/avg/subject-export
 *
 * 🔴 THE SUBJECT AND THE ERASE MODE ARE NOT PARAMETERS. Both come off the
 * case on the server. A client that could name the subject could count what
 * the instance holds about any person by posting a name, and the case guard
 * would never see it, because the case it named would be one the caller can
 * reach.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * One AVG endpoint of one case.
 *
 * @param {string} caseId The case uuid.
 * @param {string} act    The endpoint segment.
 * @return {string} The absolute url.
 */
function avgUrl(caseId, act) {
	return generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/avg/${act}`)
}

/**
 * What the platform reports an erasure would touch, recorded on the case.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object>} The preview, with `report.counts` and `report.protected`.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
 */
export async function previewErasure(caseId) {
	const { data } = await axios.post(avgUrl(caseId, 'erasure-preview'))

	return data
}

/**
 * Run the erasure this case has approved.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object>} `{destroyed, pseudonymised, withheld, refused, failed, complete}`.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
 */
export async function runErasure(caseId) {
	const { data } = await axios.post(avgUrl(caseId, 'erasure-run'))

	return data
}

/**
 * Ask the platform for this subject's own machine readable export.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object>} The export record.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
 */
export async function requestSubjectExport(caseId) {
	const { data } = await axios.post(avgUrl(caseId, 'subject-export'))

	return data
}

/**
 * Whether this case's export can still be taken, asked of the platform.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<{exportId: string, downloadable: boolean, expiresAt: string, expired: boolean}>} The state.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
 */
export async function fetchSubjectExportState(caseId) {
	const { data } = await axios.get(avgUrl(caseId, 'subject-export'))

	return data
}

/**
 * Where the platform serves the export file itself.
 *
 * OpenRegister refuses the link past the export's seven day life, so the
 * caller asks `fetchSubjectExportState` first and offers this only while
 * `downloadable` is true. Building the link off `expiresAt` alone would offer
 * one for a file that is still being assembled.
 *
 * @param {string} exportId The export uuid.
 * @return {string} The absolute url.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
 */
export function subjectExportDownloadUrl(exportId) {
	return generateUrl(`/apps/openregister/api/gdpr/subject-exports/${encodeURIComponent(exportId)}/download`)
}

/**
 * The sentence behind a refused AVG act, as the server wrote it.
 *
 * Every refusal carries `error` (the rule) and `message` (the sentence its
 * author wrote). Both are shown: the sentence is what the handler reads, and
 * the rule is what distinguishes a stale preview, which is fixed by taking it
 * again, from an unapproved one, which is fixed by asking a colleague.
 *
 * @param {object} error The axios error.
 * @return {{rule: string, message: string}} The refusal.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md
 */
export function refusalOf(error) {
	const body = error?.response?.data ?? {}

	return {
		rule: body.error ?? 'data-subject-request-failed',
		message: body.message ?? 'This could not be completed. Nothing was erased.',
	}
}
