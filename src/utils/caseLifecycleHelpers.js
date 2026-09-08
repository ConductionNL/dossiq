/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reasoning behind the case page's lifecycle strip, kept out of the
 * components so it can be tested without a DOM.
 *
 * Three questions live here:
 *
 *  - which stages the stepper shows, and in what order (the case type's
 *    status types, which arrive unordered and carry `order` as a number,
 *    a numeric string, or not at all);
 *  - whether a transition closes the case, which decides whether the
 *    confirm dialog asks for a result and whether it may be confirmed;
 *  - what a refusal from the server means in words. The endpoints answer
 *    with a short static code on purpose — a message a server writes is a
 *    message no translator ever sees — so turning the code into a sentence
 *    is the client's job.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

/**
 * Read an object's id whichever shape OpenRegister handed it back in.
 *
 * @param {object} row An object row.
 * @return {string} The id, or the empty string.
 * @spec openspec/specs/case-dashboard-view/spec.md
 */
export function rowId(row) {
	if (!row || typeof row !== 'object') {
		return ''
	}
	return String(row.id ?? row['@self']?.id ?? row.uuid ?? '')
}

/**
 * Order a case type's status types into stepper stages.
 *
 * Sorted by `order` ascending, with the row id as tiebreak so two statuses
 * sharing an order never swap places between renders. A row with no `order`
 * sorts last rather than first: an unnumbered status is one nobody has placed
 * in the process yet, and showing it as step one would misdescribe the case.
 *
 * @param {Array<object>} rows The statusType rows of one case type.
 * @return {Array<{id: string, label: string, subtitle: string}>} The stages.
 * @spec openspec/specs/case-dashboard-view/spec.md
 */
export function toStages(rows) {
	const list = Array.isArray(rows)
		? rows.filter((r) => r && typeof r === 'object')
		: []
	const ordered = [...list].sort((a, b) => {
		const left = Number.isFinite(Number(a.order))
			? Number(a.order)
			: Number.MAX_SAFE_INTEGER
		const right = Number.isFinite(Number(b.order))
			? Number(b.order)
			: Number.MAX_SAFE_INTEGER
		if (left !== right) {
			return left - right
		}
		return rowId(a).localeCompare(rowId(b))
	})

	return ordered.map((row) => ({
		id: rowId(row),
		label: String(row.name ?? row.title ?? ''),
		subtitle: String(row.description ?? ''),
	}))
}

/**
 * Whether the status a transition targets closes the case.
 *
 * @param {Array<object>} statusRows The case type's statusType rows.
 * @param {string} toStatus The target status id.
 * @return {boolean} True when that status carries isFinal.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function isClosingTransition(statusRows, toStatus) {
	if (!toStatus) {
		return false
	}
	const target = (Array.isArray(statusRows) ? statusRows : []).find(
		(row) => rowId(row) === String(toStatus),
	)
	if (!target) {
		return false
	}
	return (
		target.isFinal === true
		|| target.isFinal === 1
		|| target.isFinal === '1'
		|| target.isFinal === 'true'
	)
}

/**
 * Whether the confirm button may be pressed.
 *
 * A closing transition on a case type that offers result types cannot be
 * confirmed until one is picked — the server refuses it anyway, and a button
 * that submits a request it knows will be refused is a worse answer than a
 * disabled one.
 *
 * @param {object} params The dialog's current state.
 * @param {boolean} params.closing Whether the target status is final.
 * @param {Array<object>} params.resultTypes The result types on offer.
 * @param {string} params.resultTypeId The picked result type, if any.
 * @param {boolean} params.busy Whether a request is already in flight.
 * @return {boolean} True when the transition may be confirmed.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function canConfirmTransition({ closing, resultTypes, resultTypeId, busy }) {
	if (busy === true) {
		return false
	}
	if (closing !== true) {
		return true
	}
	if (!Array.isArray(resultTypes) || resultTypes.length === 0) {
		return true
	}
	return Boolean(resultTypeId)
}

/**
 * The body a transition POST carries.
 *
 * Empty optional fields are left out rather than sent as empty strings: the
 * engine reads "absent" as "not given" and an empty string as a given empty
 * value, and for the result type those are two different answers.
 *
 * @param {object} params The confirmed dialog.
 * @param {string} params.transitionId The transition to take.
 * @param {string} [params.comment] The handler's comment.
 * @param {string} [params.resultTypeId] The result type for a closing move.
 * @return {object} The request body.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function buildTransitionPayload({ transitionId, comment, resultTypeId }) {
	const payload = { transitionId: String(transitionId ?? '') }
	if (comment) {
		payload.comment = String(comment)
	}
	if (resultTypeId) {
		payload.resultTypeId = String(resultTypeId)
	}
	return payload
}

/**
 * The lifecycle actions the case's state allows, in menu order.
 *
 * The server decides; this only reads its answer. An unreadable state offers
 * nothing, which is the safe reading of "the case did not say".
 *
 * @param {object} state The `/lifecycle` response.
 * @return {Array<string>} The action ids to offer.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function offeredLifecycleActions(state) {
	if (!state || typeof state !== 'object') {
		return []
	}
	const offered = []
	if (state.canSuspend === true) {
		offered.push('suspend')
	}
	if (state.canResume === true) {
		offered.push('resume')
	}
	if (state.canExtend === true) {
		offered.push('extend')
	}
	if (state.canReopen === true) {
		offered.push('reopen')
	}
	return offered
}

/**
 * Whether one lifecycle gesture is honest to offer on this case.
 *
 * The dialog asks before it posts. The reason is not politeness: the four
 * gestures sit in a menu that cannot read the case type — an `open-modal`
 * action's `visibleWhen` sees the case record, and `suspensionAllowed` lives
 * on the case TYPE — so the menu offers what the record alone can justify and
 * this is where the case type gets its say.
 *
 * A state that could not be read refuses nothing. The endpoint is the
 * authority on the gesture either way, and a dialog that blocks on its own
 * failed request would hide a gesture the case does allow.
 *
 * @param {string} action One of suspend, resume, extend, reopen.
 * @param {object|null} state The `/lifecycle` response, or null when unread.
 * @return {string} The refusal code, or the empty string when allowed.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function lifecycleRefusalCode(action, state) {
	if (!state || typeof state !== 'object') {
		return ''
	}
	if (offeredLifecycleActions(state).includes(String(action))) {
		return ''
	}
	switch (String(action)) {
		case 'suspend':
			return state.suspended === true
				? 'already_suspended'
				: 'suspension_not_allowed'
		case 'resume':
			return 'not_suspended'
		case 'extend':
			return 'extension_not_allowed'
		case 'reopen':
			return 'case_not_closed'
		default:
			return ''
	}
}

/**
 * Turn a server refusal into a sentence.
 *
 * @param {object} body The refusal body ({error, code, failedGuards}).
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {string} What to show the handler.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function refusalMessage(body, translate) {
	const t = typeof translate === 'function' ? translate : (s) => s
	const guards = Array.isArray(body?.failedGuards) ? body.failedGuards : []
	if (guards.length > 0) {
		// `failureMessage` is the key GuardRegistry::evaluateAll writes. Reading
		// `message` — which nothing sets — degraded every guard refusal to its
		// bare type name ("requiredDocument"), so the one sentence the handler
		// needed was the one sentence that never rendered.
		const named = guards
			.map((guard) =>
				String(guard?.failureMessage ?? guard?.message ?? guard?.type ?? ''),
			)
			.filter(Boolean)
		if (named.length > 0) {
			return named.join(' ')
		}
	}

	switch (String(body?.code ?? '')) {
		case 'result_type_required':
			return t('Pick a result before closing this case.')
		case 'suspension_not_allowed':
			return t('This case type does not allow suspension.')
		case 'extension_not_allowed':
			return t('This case type does not allow an extension.')
		case 'extension_period_not_configured':
		case 'extension_period_unreadable':
			return t('This case type states no extension period.')
		case 'already_suspended':
			return t('This case is already suspended.')
		case 'not_suspended':
			return t('This case is not suspended.')
		case 'case_not_closed':
			return t('Only a closed case can be reopened.')
		case 'initial_status_not_configured':
		case 'initial_status_not_of_case_type':
			return t('This case type has no first status to reopen into.')
		case 'case_not_found':
			return t('This case could not be found.')
		case 'reason_required':
			return t('Give a reason first.')
		default:
			return String(body?.error ?? t('The case could not be changed.'))
	}
}
