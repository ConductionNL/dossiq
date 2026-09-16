/**
 * The four clocks on a case, as dossiq answers them.
 *
 * 🔴 EVERY NUMBER HERE IS THE SERVER'S. Nothing in this module computes a
 * progress figure or a days-left count, and that is the point: the case page
 * and the list column read the SAME computation, so they cannot disagree about
 * a value neither of them stores. A browser that derived its own percentage
 * would be a second calculator of the same question, and the second one
 * eventually differs from the first on a case that was paused over a weekend.
 *
 * A failed read answers null rather than an empty list. A case with no clocks
 * and a case that could not be read are opposite facts, and a panel that turned
 * the second into the first would tell a handler there is no deadline.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * GET one dossiq path, answering null when it cannot be read.
 *
 * @param {string} path Path under the Nextcloud root, no leading slash.
 *
 * @return {Promise<object|null>} The body, or null when the read failed.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
async function read(path) {
	try {
		const response = await axios.get(generateUrl(`/${path}`))
		// A non-JSON body arrives as a string, and a string has keys in
		// JavaScript, so `body.terms` on it is undefined rather than an error.
		// That renders as "no clocks" instead of as a failed read.
		if (typeof response?.data !== 'object' || response.data === null) {
			return null
		}
		return response.data
	} catch {
		return null
	}
}

/**
 * Every clock on one case, with the progress and the days left.
 *
 * @param {string} caseId The case uuid.
 *
 * @return {Promise<object|null>} `{case, terms, progress}`, or null when unreadable.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 */
export function fetchCaseTerms(caseId) {
	return read(`apps/dossiq/api/cases/${caseId}/terms`)
}

/**
 * How old the open workload is right now, per status.
 *
 * @return {Promise<object|null>} `{generatedAt, openCases, perStatus}`, or null.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 */
export function fetchOpenWorkloadAge() {
	return read('apps/dossiq/api/termijn/reports/open-workload-age')
}

/**
 * Ask the applicant for what is missing, which suspends the term.
 *
 * One call, because it is one act. A caller that sent the letter and then
 * suspended would be back at the shape Awb 4:5 exists to prevent.
 *
 * @param {string} caseId       The case uuid.
 * @param {object} request      What to ask for.
 * @param {Array}  request.items       The missing items, one line each.
 * @param {string} request.recipient   Who to send it to.
 * @param {number} request.durationDays How long the applicant is given.
 * @param {string} request.rationale   Why the case cannot be decided yet.
 *
 * @return {Promise<object>} `{ok, body}` — `body` carries `{message, error}` on a refusal.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
 */
export async function requestInformation(caseId, request) {
	try {
		const response = await axios.post(
			generateUrl(`/apps/dossiq/api/cases/${caseId}/information-request`),
			request,
		)
		return { ok: true, body: response?.data ?? {} }
	} catch (error) {
		return { ok: false, body: error?.response?.data ?? {} }
	}
}

/**
 * Record the aanvulling, which resumes the term.
 *
 * @param {string} caseId The case uuid.
 * @param {Array}  items  What came in, one line each.
 *
 * @return {Promise<object>} `{ok, body}`.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-pause-extension/spec.md
 */
export async function receiveInformation(caseId, items) {
	try {
		const response = await axios.post(
			generateUrl(
				`/apps/dossiq/api/cases/${caseId}/information-request/received`,
			),
			{ items },
		)
		return { ok: true, body: response?.data ?? {} }
	} catch (error) {
		return { ok: false, body: error?.response?.data ?? {} }
	}
}
