/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a transition answer says about the work that came with the move.
 *
 * The engine moves the case before any automatic action runs, so a 200 does
 * not mean everything happened. When an action failed, the answer carries
 * `status: "partial"` and lists the failures in `failedActions`. The case-page
 * dialog and the workflow board both read that list here, so the handler gets
 * one sentence for it, wherever the move was made.
 *
 * The bulk pair that lived here is gone with the bulk loop. A case that moved
 * without its actions is now a row on the job, carrying the same note as its
 * reason (see lib/BulkAction/TransitionCasesAction::missedActions).
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
