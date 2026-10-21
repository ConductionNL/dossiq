/**
 * Bulk job API wrapper (bulk-actions-report-progress).
 *
 * dossiq starts a bulk act on its own endpoint, which applies the case policy
 * and hands the act to OpenRegister's job. Everything after that (the
 * progress, the per-row outcome, the CSV, the commit, the cancel and the
 * retry) is read straight from OpenRegister by the library's
 * CnBulkJobProgress. dossiq proxies none of it, because a proxy would be a
 * second job model that can disagree with the first.
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

const dossiq = (suffix = '') => generateUrl(`/apps/dossiq${suffix}`)

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
