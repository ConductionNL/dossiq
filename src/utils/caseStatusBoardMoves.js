// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The two named handlers the Cases board view runs (manifest
 * `config.board.moveHandler` and `moveTargetsHandler`): a status move goes
 * through the status-transition engine, the same write path the case page
 * uses, and the board asks the engine where a case may go.
 *
 * The library resolves a drop on a merged column to the statusType row of the
 * case's own case type and hands it over as `value`, so these handlers only
 * deal in concrete statusType ids.
 *
 * @spec openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
 */

import axios from '@nextcloud/axios'
import { showWarning } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	buildTransitionPayload,
	findTransitionToStatus,
	refusalMessage,
	transitionBlockReason,
	transitionIsBlocked,
} from './caseLifecycleHelpers.js'
import { failedActionsWarning } from './transitionOutcome.js'

/**
 * The transitions the engine offers one case.
 *
 * @param {string} caseId The case id.
 * @return {Promise<Array<object>>} The offer.
 */
async function offeredTransitions(caseId) {
	const { data } = await axios.get(
		generateUrl(
			`/apps/dossiq/api/case/${encodeURIComponent(caseId)}/available-transitions`,
		),
	)
	return Array.isArray(data?.transitions) ? data.transitions : []
}

/**
 * Move a case to a status through the transition that ends there.
 *
 * Throws with the sentence a handler acts on when the move is not on offer,
 * a guard holds the case, or the engine refuses; the board shows it and puts
 * the card back.
 *
 * @param {object} move The board's move.
 * @param {object} move.card The case row.
 * @param {string} move.value The statusType id of the case's own case type.
 * @param {string} move.toKey The column (status name).
 * @return {Promise<object>} The engine's answer.
 */
export async function moveCaseStatus({ card, value, toKey }) {
	const caseId = card?.id ?? card?.['@self']?.id
	const transition = findTransitionToStatus(
		await offeredTransitions(caseId),
		value,
	)
	if (transition === null) {
		throw new Error(
			t('dossiq', 'You cannot move this case to {status} from here.', {
				status: toKey,
			}),
		)
	}
	if (transitionIsBlocked(transition)) {
		throw new Error(
			transitionBlockReason(transition)
				|| t('dossiq', 'Something is holding this case here.'),
		)
	}
	try {
		const { data } = await axios.post(
			generateUrl(
				`/apps/dossiq/api/case/${encodeURIComponent(caseId)}/transition`,
			),
			buildTransitionPayload({ transitionId: transition.id }),
		)
		const warning = failedActionsWarning(data)
		if (warning !== '') {
			showWarning(warning)
		}
		return data
	} catch (error) {
		throw new Error(
			refusalMessage(error?.response?.data ?? {}, (s) => t('dossiq', s)),
			{ cause: error },
		)
	}
}

/**
 * The statuses one case can reach: the targets of the transitions on offer
 * that no guard holds.
 *
 * @param {object} query The board's question.
 * @param {object} query.card The case row.
 * @return {Promise<Array<string>>} statusType ids.
 */
export async function caseStatusTargets({ card }) {
	const caseId = card?.id ?? card?.['@self']?.id
	const offered = await offeredTransitions(caseId)
	return offered
		.filter((transition) => transition && !transitionIsBlocked(transition))
		.map((transition) => String(transition.toStatus ?? ''))
		.filter((id) => id !== '')
}
