import { getRequestToken } from '@nextcloud/auth'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reports what a person did with each AI suggestion on a case, for the AI
 * oversight trail (EU AI Act art. 14). Every suggestion that is shown ends in
 * exactly one report: accepted, modified or rejected when the person acts on
 * it, and ignored when they do not. An ignored suggestion says why: a newer
 * suggestion replaced it (superseded), its surface was closed (closed), or it
 * stayed open past its lifetime (expired).
 *
 * dossiq's record-action endpoint writes the local audit entry and hands the
 * decision to hermiq; without hermiq it keeps only the local entry.
 *
 * @spec openspec/specs/ai-oversight-dispatch/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** How long a shown suggestion waits for a decision before it expires. */
export const SUGGESTION_LIFETIME_MS = 30 * 60 * 1000

/**
 * Post one decision to dossiq.
 *
 * @param {object} body The record-action body.
 * @param {object} options Options.
 * @param {boolean} options.leaving True while the page unloads: the request
 *   then uses `fetch` with `keepalive`, which outlives the page.
 * @return {Promise<void>}
 * @spec openspec/specs/ai-oversight-dispatch/spec.md
 */
export async function postDecision(body, { leaving = false } = {}) {
	const url = generateUrl('/apps/dossiq/api/ai/record-action')
	if (leaving === true) {
		await fetch(url, {
			method: 'POST',
			keepalive: true,
			headers: {
				'Content-Type': 'application/json',
				requesttoken: getRequestToken() ?? '',
			},
			body: JSON.stringify(body),
		})
		return
	}

	await axios.post(url, body)
}

/**
 * Track the suggestions shown on one case.
 *
 * A suggestion is shown under a slot key (one feature, or the questions); a
 * newer suggestion in the same slot supersedes an undecided older one.
 *
 * @param {object} options Options.
 * @param {string} options.caseId The case uuid.
 * @param {function(object, object): Promise<void>} [options.report] Sends one decision; defaults to postDecision.
 * @param {number} [options.lifetimeMs] Lifetime of an undecided suggestion.
 * @param {function(function(): void, number): number} [options.setTimer] Timer factory, for tests.
 * @param {function(number): void} [options.clearTimer] Timer cancel, for tests.
 * @return {object} The tracker: show, decide, dismiss, closeAll, pending.
 * @spec openspec/specs/ai-oversight-dispatch/spec.md
 */
export function createSuggestionTracker({
	caseId,
	report = postDecision,
	lifetimeMs = SUGGESTION_LIFETIME_MS,
	setTimer = setTimeout,
	clearTimer = clearTimeout,
}) {
	const pending = new Map()
	let counter = 0

	/**
	 * Send the report for one slot's suggestion and forget it.
	 *
	 * @param {string} slot The slot key.
	 * @param {object} decision `{userAction, unusedBecause?, actualValue?, reason?}`.
	 * @param {object} options Passed to report.
	 * @return {Promise<void>}
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 */
	function settle(slot, decision, options = {}) {
		const shown = pending.get(slot)
		if (shown === undefined) {
			return Promise.resolve()
		}

		pending.delete(slot)
		clearTimer(shown.timer)
		const body = {
			caseId,
			type: shown.type,
			suggestion: shown.text,
			prompt: shown.prompt ?? '',
			model: shown.model ?? '',
			documentId: shown.documentId ?? '',
			suggestionId: shown.id,
			...decision,
		}

		return Promise.resolve(report(body, options)).catch(() => {
			// The person's work must not fail because the oversight trail
			// could not be written; the server logs its own failures.
		})
	}

	return {
		/**
		 * Register a suggestion as shown in a slot.
		 *
		 * @param {string} slot The slot key.
		 * @param {object} suggestion `{type, text, prompt?, model?, documentId?}`.
		 * @return {string} The suggestion id.
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		show(slot, suggestion) {
			settle(slot, { userAction: 'ignored', unusedBecause: 'superseded' })
			counter += 1
			const id = `${Date.now().toString(36)}-${counter}`
			const timer = setTimer(() => {
				settle(slot, { userAction: 'ignored', unusedBecause: 'expired' })
			}, lifetimeMs)
			pending.set(slot, { ...suggestion, id, timer })
			return id
		},

		/**
		 * Report the person's decision on a slot's suggestion.
		 *
		 * @param {string} slot The slot key.
		 * @param {string} userAction accepted, modified or rejected.
		 * @param {object} [extra] `{actualValue?, reason?}`.
		 * @return {Promise<void>}
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		decide(slot, userAction, extra = {}) {
			return settle(slot, { userAction, ...extra })
		},

		/**
		 * Report a slot's undecided suggestion as ignored, for a reason.
		 *
		 * @param {string} slot The slot key.
		 * @param {string} because closed, expired or superseded.
		 * @return {Promise<void>}
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		dismiss(slot, because) {
			return settle(slot, { userAction: 'ignored', unusedBecause: because })
		},

		/**
		 * Report every undecided suggestion as closed.
		 *
		 * @param {object} [options] `{leaving}` while the page unloads.
		 * @return {Promise<void>}
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		closeAll(options = {}) {
			const slots = [...pending.keys()]
			return Promise.all(
				slots.map((slot) =>
					settle(
						slot,
						{ userAction: 'ignored', unusedBecause: 'closed' },
						options,
					),
				),
			).then(() => undefined)
		},

		/**
		 * Whether a slot holds an undecided suggestion.
		 *
		 * @param {string} slot The slot key.
		 * @return {boolean} True while undecided.
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		pending(slot) {
			return pending.has(slot)
		},
	}
}
