/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a transition answer says about the work that came with the move.
 *
 * The engine moves the case before any automatic action runs, so a 200 does
 * not mean everything happened. When an action failed, the answer carries
 * `status: "partial"` and lists the failures in `failedActions`. The case-page
 * dialog, the workflow board and the bulk dialog all read that list here, so
 * the handler gets one sentence for it, wherever the move was made.
 *
 * @spec openspec/changes/transition-reports-failed-actions/specs/status-transition-engine/spec.md
 */

import { translatePlural as n } from '@nextcloud/l10n'

/**
 * The actions a single-case transition answer says did not run.
 *
 * @param {object} body The transition response body.
 * @return {Array<{type: string, error: string}>} The failures, empty when none.
 * @spec openspec/changes/transition-reports-failed-actions/specs/status-transition-engine/spec.md
 */
export function failedActionsOf(body) {
	return Array.isArray(body?.failedActions) ? body.failedActions : []
}

/**
 * The warning for a case that moved without all of its actions.
 *
 * @param {object} body The transition response body.
 * @return {string} The sentence to show, or the empty string when every action ran.
 * @spec openspec/changes/transition-reports-failed-actions/specs/status-transition-engine/spec.md
 */
export function failedActionsWarning(body) {
	const count = failedActionsOf(body).length
	if (count === 0) {
		return ''
	}

	return n(
		'dossiq',
		'You moved the case, but {count} automatic action did not run. Its status record shows which.',
		'You moved the case, but {count} automatic actions did not run. Its status record shows which.',
		count,
		{ count },
	)
}

/**
 * How many cases in a bulk answer moved without all of their actions.
 *
 * The bulk service keeps such a case `succeeded` and carries its
 * `failedActions` beside the status, so a case counts here only when it
 * succeeded and that list is not empty.
 *
 * @param {{[key: string]: {status: string, failedActions?: Array}}} results The per-case results map.
 * @return {number} The number of cases.
 * @spec openspec/changes/transition-reports-failed-actions/specs/case-bulk-status-transition/spec.md
 */
export function casesMissingActions(results) {
	const map = results && typeof results === 'object' ? results : {}
	return Object.values(map).filter(
		(entry) =>
			entry?.status === 'succeeded' && failedActionsOf(entry).length > 0,
	).length
}

/**
 * The bulk dialog's line for the cases that moved without all of their actions.
 *
 * @param {{[key: string]: {status: string, failedActions?: Array}}} results The per-case results map.
 * @return {string} The sentence to show, or the empty string when every case got its actions.
 * @spec openspec/changes/transition-reports-failed-actions/specs/case-bulk-status-transition/spec.md
 */
export function bulkFailedActionsNotice(results) {
	const count = casesMissingActions(results)
	if (count === 0) {
		return ''
	}

	return n(
		'dossiq',
		'{count} case moved without all of its automatic actions. Its status record shows which.',
		'{count} cases moved without all of their automatic actions. Their status records show which.',
		count,
		{ count },
	)
}
