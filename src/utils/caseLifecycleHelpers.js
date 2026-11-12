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
 *  - what body a transition POST carries, shared by the lifecycle menu and
 *    the workflow board so both post the same move;
 *  - what a refusal from the server means in words. The endpoints answer
 *    with a short static code on purpose — a message a server writes is a
 *    message no translator ever sees — so turning the code into a sentence
 *    is the client's job.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 * @spec openspec/specs/case-dashboard-view/spec.md
 */

import { stateFieldRefusal } from './statusFieldRules.js'

/**
 * The given translate function, or one that hands the key back unchanged.
 *
 * @param {(key: string) => string} translate The bound t(), or anything else.
 * @return {(key: string) => string} A translate function.
 */
function translatorFor(translate) {
	return typeof translate === 'function' ? translate : (s) => s
}

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
	return String(firstIdOf(row))
}

/**
 * The first id an object row carries: its own, its `@self` one, its uuid.
 *
 * @param {object} row An object row.
 * @return {string|number} The id as found, or the empty string.
 */
function firstIdOf(row) {
	return row.id ?? row['@self']?.id ?? row.uuid ?? ''
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
 * Whether the requester was told about an extension, as one sentence.
 *
 * The extension stands either way. A notice that did not go out is said with
 * its reason, so the handler can tell the requester another way before the
 * first term ends.
 *
 * @param {object|null} body The extend answer (`{..., notice: {noticeStatus, noticeReason}}`).
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {{told: boolean, text: string}|null} The sentence, or null when the answer carries no notice.
 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-an-extension-answers-whether-the-requester-was-told-req-tel-002
 */
export function extensionNotice(body, translate) {
	const t = translatorFor(translate)
	const notice = body?.notice
	if (isMissingNotice(notice)) {
		return null
	}

	if (noticeWasSent(notice)) {
		return {
			told: true,
			text: t('The term is extended. The requester was told.'),
		}
	}

	const text = t('The term is extended. The requester was not told.')
	return { told: false, text: text + noticeReasonSuffix(notice, t) }
}

/**
 * Whether the requester was told that the archived case is completed, as one sentence.
 *
 * @param {object|null} body The archive answer (`{..., notice: {noticeStatus, noticeReason}}`).
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {{told: boolean, text: string}|null} The sentence, or null when the answer carries no notice.
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */
export function archiveNotice(body, translate) {
	const t = translatorFor(translate)
	const notice = body?.notice
	if (isMissingNotice(notice)) {
		return null
	}

	if (noticeWasSent(notice)) {
		return { told: true, text: t('The case is archived. The requester was told.') }
	}

	const text = t('The case is archived. The requester was not told.')
	return { told: false, text: text + noticeReasonSuffix(notice, t) }
}

/**
 * Whether an answer's notice is absent or not an object.
 *
 * @param {object|null|undefined} notice The answer's `notice`.
 * @return {boolean} True when there is no notice to speak of.
 */
function isMissingNotice(notice) {
	return !notice || typeof notice !== 'object'
}

/**
 * Whether a notice went out to the requester.
 *
 * @param {object} notice The answer's `notice`.
 * @return {boolean} True when its status is `sent`.
 */
function noticeWasSent(notice) {
	return String(notice.noticeStatus ?? '') === 'sent'
}

/**
 * The reason a notice did not go out, as a suffix to the not-told sentence.
 *
 * The reasons dossiq writes itself are catalogue keys; a reason another
 * sender wrote passes through t() unchanged.
 *
 * @param {object} notice The answer's `notice`.
 * @param {(key: string) => string} t The translate function.
 * @return {string} ' ' plus the translated reason, or '' when there is none.
 */
function noticeReasonSuffix(notice, t) {
	const reason = String(notice.noticeReason ?? '').trim()
	return reason === '' ? '' : ' ' + t(reason)
}

// The translated sentence per refusal code. Each entry calls t() itself
// so the sentence stays a literal the string extraction can read.
const REFUSAL_SENTENCES = new Map([
	['result_type_required', (t) => t('Pick a result before closing this case.')],
	['suspension_not_allowed', (t) => t('This case type does not allow suspension.')],
	['extension_not_allowed', (t) => t('This case type does not allow an extension.')],
	['extension_limit_reached', (t) => t(
		'The term of this case was already extended as often as its case type allows.',
	)],
	['extension_period_not_configured', (t) => t('This case type states no extension period.')],
	['extension_period_unreadable', (t) => t('This case type states no extension period.')],
	['already_suspended', (t) => t('This case is already suspended.')],
	['not_suspended', (t) => t('This case is not suspended.')],
	['case_not_closed', (t) => t('Only a closed case can be reopened.')],
	['initial_status_not_configured', (t) => t('This case type has no first status to reopen into.')],
	['initial_status_not_of_case_type', (t) => t('This case type has no first status to reopen into.')],
	['case_not_found', (t) => t('This case could not be found.')],
	['reason_required', (t) => t('Give a reason first.')],
	['pause_reason_not_declared', (t) => t('This case type has no such pause reason. Pick one of its reasons.')],
	// A gesture the server could not save answers this code with an
	// English `error` beside it. The code is what is translated, so a
	// Dutch handler does not read "The case could not be changed." in
	// English (dutch-copy-on-the-case-page, finding F4).
	['change_failed', (t) => t('The case could not be changed.')],
	// The three approval refusals (REQ-DEC-01). The sentence naming the
	// approval and the people it waits on rides on the blocked act itself,
	// where there is room for it; these are what a handler sees when they
	// posted the act anyway and the write path refused it.
	['approval_outstanding', (t) => t('This act waits for an approval that is still open.')],
	['approval_rejected', (t) => t('The approval for this act was refused.')],
	['approval_unreadable', (t) => t(
		'We could not reach the approval service, so this act stays closed.',
	)],
])

/**
 * Turn a server refusal into a sentence.
 *
 * @param {object} body The refusal body ({message, error, code, failedGuards, errors}).
 * @param {(key: string) => string} translate The bound t(), taking one string.
 * @return {string} What to show the handler.
 * @spec openspec/specs/status-transition-engine/spec.md
 * @spec openspec/specs/frontend-locale-completeness/spec.md
 */
export function refusalMessage(body, translate) {
	const t = translatorFor(translate)

	// A state's field rules are the platform's refusal, and it arrives one
	// level down under `errors` with the sentence beside the code. It is read
	// FIRST because the outer `error` on that same body is the exception text,
	// so reading the body the old way would show a handler the machinery
	// instead of the field they have to go and fill in. Never re-translated:
	// the sentence is either the one the administrator wrote on the rule, in
	// their own language, or OpenRegister's own naming the field and the state.
	const stateField = stateFieldRefusal(body)
	if (stateField?.message) {
		return stateField.message
	}

	const named = failedGuardNames(body)
	if (named.length > 0) {
		return named.join(' ')
	}

	const sentence = REFUSAL_SENTENCES.get(String(body?.code ?? ''))
	return sentence ? sentence(t) : authoredRefusal(body, t)
}

/**
 * The names of the failed guards on a refusal, in order, empty ones dropped.
 *
 * @param {object} body The refusal body.
 * @return {Array<string>} The guard sentences.
 */
function failedGuardNames(body) {
	const guards = Array.isArray(body?.failedGuards) ? body.failedGuards : []
	// `failureMessage` is the key GuardRegistry::evaluateAll writes. Reading
	// `message` — which nothing sets — degraded every guard refusal to its
	// bare type name ("requiredDocument"), so the one sentence the handler
	// needed was the one sentence that never rendered.
	return guards
		.map((guard) =>
			String(guard?.failureMessage ?? guard?.message ?? guard?.type ?? ''),
		)
		.filter(Boolean)
}

/**
 * The sentence a refusal authored itself, for a code without its own.
 *
 * @param {object} body The refusal body.
 * @param {(key: string) => string} t The translate function.
 * @return {string} What to show the handler.
 */
function authoredRefusal(body, t) {
	// `message` before `error`: since refusals-carry-a-status, `error`
	// is a kebab-case rule slug meant for code, and `message` is the
	// sentence the refusal authored. Reading `error` first would put
	// "transition-from-status-mismatch" in front of a handler.
	return String(
		body?.message ?? body?.error ?? t('The case could not be changed.'),
	)
}

/**
 * Why a transition on offer cannot be taken yet.
 *
 * The engine offers a transition whose guards failed rather than hiding it,
 * because a button that is simply absent tells the handler nothing about what
 * to do next. The reason belongs ON the button: `guardsPassed` says whether it
 * can be pressed, `failedGuards` says why not, and the first failure is the
 * one worth reading — a handler acts on one thing at a time.
 *
 * @param {object} transition One entry of the available-transitions answer.
 * @return {string} The reason, or the empty string when it can be taken.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function transitionBlockReason(transition) {
	if (!transition || typeof transition !== 'object') {
		return ''
	}
	// Only an explicit false blocks: an answer that predates `guardsPassed`
	// (or a caller that hands over a bare transition) must keep working.
	if (transition.guardsPassed !== false) {
		return ''
	}
	const named = blockingGuardNames(transition)
	return named.length > 0 ? named[0] : ''
}

/**
 * The names of a transition's failed guards, the unnamed ones left out.
 *
 * @param {object} transition One entry of the available-transitions answer.
 * @return {Array<string>} The names, in the engine's order.
 */
function blockingGuardNames(transition) {
	const guards = Array.isArray(transition.failedGuards)
		? transition.failedGuards
		: []
	return guards.map(blockingGuardName).filter(Boolean)
}

/**
 * A failed guard's name: its message, else its type.
 *
 * @param {object|null} guard One failed guard.
 * @return {string} The trimmed name, or the empty string.
 */
function blockingGuardName(guard) {
	return String(guard?.failureMessage ?? guard?.type ?? '').trim()
}

/**
 * Whether a transition on offer may be pressed.
 *
 * Kept apart from the reason because the two can disagree: a guard can fail
 * with no message of its own, and a button that stayed enabled because nobody
 * wrote a sentence would send the handler into a refusal instead of telling
 * them beforehand.
 *
 * @param {object} transition One entry of the available-transitions answer.
 * @return {boolean} True when a guard is holding the case.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function transitionIsBlocked(transition) {
	return transition?.guardsPassed === false
}

/**
 * The offered transition that lands the case on a given status.
 *
 * The case page names a transition directly: the handler pressed its button,
 * so its id is already in hand. A board move names only a destination column,
 * so the id has to be looked up in the engine's own answer before the move can
 * be posted. Matching on `toStatus` is what makes the two paths the same
 * gesture: the board does not decide what a move means, it asks which of the
 * moves already on offer ends where the card was dropped.
 *
 * `getAvailableTransitions` has already dropped whatever the caller's role
 * hides and already filtered on the case's current status, so an answer with
 * no match means the move is not on offer, never that the lookup was wrong.
 *
 * @param {Array<object>} transitions The `/available-transitions` answer's `transitions`.
 * @param {string} toStatusId The statusType id the card was dropped on.
 * @return {object|null} The matching transition, or null when none is offered.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
export function findTransitionToStatus(transitions, toStatusId) {
	const wanted = String(toStatusId ?? '')
	if (wanted === '' || Array.isArray(transitions) === false) {
		return null
	}
	return transitions.find((transition) => landsOn(transition, wanted)) ?? null
}

/**
 * Whether an offered transition ends on the wanted status.
 *
 * @param {object|null} transition One entry of the `transitions` answer.
 * @param {string} wanted The statusType id, as a string.
 * @return {boolean} True when it is an object whose `toStatus` matches.
 */
function landsOn(transition, wanted) {
	return Boolean(transition)
		&& typeof transition === 'object'
		&& String(transition.toStatus ?? '') === wanted
}
