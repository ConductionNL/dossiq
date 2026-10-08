/**
 * Bulk job API wrapper (bulk-actions-report-progress).
 *
 * dossiq starts a bulk act on its own endpoint, which applies the case policy
 * and hands the act to OpenRegister's job. Everything after that is read
 * straight from OpenRegister: the progress, the per-row outcome, the CSV, the
 * commit, the cancel and the retry. dossiq proxies none of it, because a proxy
 * would be a second job model that can disagree with the first.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The dossiq action that moves cases to another status. */
export const ACTION_TRANSITION = 'dossiq:transition-cases'

/** The dossiq action that suspends, resumes or extends a term. */
export const ACTION_LIFECYCLE = 'dossiq:lifecycle-cases'

/** The dossiq action that gives cases to another handler. */
export const ACTION_REASSIGN = 'dossiq:reassign-cases'

/** The dossiq action that writes one field across cases. */
export const ACTION_SET_ATTRIBUTE = 'dossiq:set-case-attribute'

/** The job states that mean the job is still moving. */
export const ACTIVE_STATES = ['previewed', 'running', 'cancelling']

/** The job states that mean the job has stopped for good. */
export const FINAL_STATES = ['completed', 'failed', 'cancelled']

const dossiq = (suffix = '') => generateUrl(`/apps/dossiq${suffix}`)
const openregister = (suffix = '') => generateUrl(`/apps/openregister${suffix}`)

/**
 * Start a bulk act over cases and read back what it would do.
 *
 * Nothing is written by this call. The job comes back `previewed`, with a
 * member per case saying what the act would do to it, and a coordinator
 * commits it once they have read the skip list.
 *
 * @param {object} request              The act.
 * @param {string} request.action       One of the ACTION_* ids.
 * @param {object} request.parameters   What the action needs.
 * @param {object} request.selection    `{ids: [...]}` or `{query: {...}}`.
 * @param {string} [request.justification] The reason the coordinator typed.
 *
 * @return {Promise<object>} The previewed job.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export async function previewBulkJob({
	action,
	parameters,
	selection,
	justification,
}) {
	const { data } = await axios.post(dossiq('/api/cases/bulk-jobs'), {
		action,
		parameters: parameters || {},
		selection,
		justification: justification || '',
	})
	return data
}

/**
 * Read a job's current position.
 *
 * @param {string|number} jobId The job id.
 *
 * @return {Promise<object>} The job, with `processed`, `total` and `counts`.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export async function fetchBulkJob(jobId) {
	const { data } = await axios.get(
		openregister(`/api/bulk-jobs/${encodeURIComponent(jobId)}`),
	)
	return data
}

/**
 * Read a page of a job's rows, optionally only the rows with one outcome.
 *
 * The skip list is this call with `outcome: 'skipped'`, and the refusals are
 * the same call with `refused`. They are asked for separately on purpose:
 * "12 skipped" and "3 you may not write" are two different things a handler
 * does two different things about.
 *
 * @param {string|number} jobId      The job id.
 * @param {object}        [options]  Paging and filtering.
 * @param {string}        [options.outcome] applied, skipped, refused or failed.
 * @param {number}        [options.limit]   Page size.
 * @param {number}        [options.offset]  Page offset.
 *
 * @return {Promise<{results: Array, total: number}>} The rows and how many there are.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export async function fetchBulkJobMembers(
	jobId,
	{ outcome, limit = 50, offset = 0 } = {},
) {
	const params = { limit, offset }
	if (outcome) {
		params.outcome = outcome
	}

	const { data } = await axios.get(
		openregister(`/api/bulk-jobs/${encodeURIComponent(jobId)}/members`),
		{ params },
	)
	return {
		results: (data && data.results) || [],
		total: Number((data && data.total) || 0),
	}
}

/**
 * Commit a previewed job. This is the call that writes.
 *
 * @param {string|number} jobId           The job id.
 * @param {string}        [justification] A reason given at commit time.
 *
 * @return {Promise<object>} The running job.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export async function commitBulkJob(jobId, justification) {
	const { data } = await axios.post(
		openregister(`/api/bulk-jobs/${encodeURIComponent(jobId)}/commit`),
		justification ? { justification } : {},
	)
	return data
}

/**
 * Stop a running job before the next case.
 *
 * @param {string|number} jobId The job id.
 *
 * @return {Promise<object>} The job, cancelling or cancelled.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export async function cancelBulkJob(jobId) {
	const { data } = await axios.post(
		openregister(`/api/bulk-jobs/${encodeURIComponent(jobId)}/cancel`),
	)
	return data
}

/**
 * Run the cases a stopped job did not reach.
 *
 * @param {string|number} jobId The job id.
 *
 * @return {Promise<object>} The running job.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export async function retryBulkJob(jobId) {
	const { data } = await axios.post(
		openregister(`/api/bulk-jobs/${encodeURIComponent(jobId)}/retry`),
	)
	return data
}

/**
 * Where the report of a job downloads from.
 *
 * A URL rather than a fetch: the browser has to navigate to it for the file to
 * land in the downloads folder, and an axios read of a CSV gives you a string
 * in memory and no file.
 *
 * @param {string|number} jobId The job id.
 *
 * @return {string} The download URL.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function bulkJobReportUrl(jobId) {
	return openregister(`/api/bulk-jobs/${encodeURIComponent(jobId)}/download`)
}

/**
 * Whether a job has stopped for good.
 *
 * @param {object} job The job.
 *
 * @return {boolean} True when nothing more will happen to it.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function isFinished(job) {
	return FINAL_STATES.includes(String((job && job.state) || ''))
}

/**
 * Read a refusal out of an axios error, keeping its reason.
 *
 * A refusal is a 422 with a `reason` naming the rule. Everything else is a
 * failure, and the two are told apart here so a dialog can say "the selection
 * spans two versions of Bezwaar" instead of "something went wrong".
 *
 * @param {object} error The axios error.
 *
 * @return {{reason: string, message: string, details: object}} What was refused, and why.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export function readRefusal(error) {
	const body = (error && error.response && error.response.data) || {}

	return {
		reason: String(body.reason || ''),
		message: String(body.error || ''),
		details: body.details || {},
	}
}

/**
 * How many cases match a set of filters.
 *
 * Asked before a handler is offered the whole result, because offering "select
 * all 400" without knowing there are 400 is exactly the surprise the scope
 * affordance exists to prevent. An unreadable count answers 0, which withholds
 * the offer rather than guessing at it.
 *
 * ⚠️ The objects endpoint wants BARE filter keys. Spelling them `filter[x]`
 * makes it answer the empty set, confidently and without an error, and the
 * page would then say every search matches nothing.
 *
 * @param {object} filters The list's current filters.
 *
 * @return {Promise<number>} How many cases match.
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
export async function countMatchingCases(filters) {
	try {
		const { data } = await axios.get(
			generateUrl('/apps/openregister/api/objects/dossiq/case'),
			{ params: { ...(filters || {}), _limit: 1 } },
		)

		return Number(data?.total || 0)
	} catch {
		return 0
	}
}
