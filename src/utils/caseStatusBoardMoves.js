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
 * @spec openspec/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
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
	requireMovable(transition, toKey)
	return postTransition(caseId, transition)
}

/**
 * Refuse a move that is not on offer, or that a guard holds.
 *
 * @param {object|null} transition The transition that ends on the status, or null.
 * @param {string} toKey The column (status name).
 * @return {void}
 * @throws {Error} With the sentence the board shows.
 */
function requireMovable(transition, toKey) {
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
}

/**
 * Post the transition, warn about actions that failed, and answer the engine.
 *
 * @param {string} caseId The case id.
 * @param {object} transition The transition.
 * @return {Promise<object>} The engine's answer.
 * @throws {Error} With the engine's refusal, the failure as its cause.
 */
async function postTransition(caseId, transition) {
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
 * The statuses one case can reach, and why a guard holds the others.
 *
 * Each target status is answered once, from the first transition that ends
 * on it, because that is the one `moveCaseStatus` posts. A transition a guard
 * holds stays on the list as not allowed, carrying the guard's sentence, so
 * the board can say what holds the case instead of only that it cannot move.
 *
 * @param {object} query The board's question.
 * @param {object} query.card The case row.
 * @return {Promise<Array<{key: string, allowed: boolean, reason: string}>>} One entry per target statusType id.
 * @spec openspec/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
 */
export async function caseStatusTargets({ card }) {
	const caseId = card?.id ?? card?.['@self']?.id
	const offered = await offeredTransitions(caseId)
	const targets = []
	const seen = new Set()
	for (const transition of offered) {
		const key = String(transition?.toStatus ?? '')
		if (key === '' || seen.has(key)) {
			continue
		}
		seen.add(key)
		const allowed = !transitionIsBlocked(transition)
		targets.push({
			key,
			allowed,
			reason: allowed
				? ''
				: transitionBlockReason(transition)
					|| t('dossiq', 'Something is holding this case here.'),
		})
	}
	return targets
}
