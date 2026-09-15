/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One menu, built from three server answers, with every refusal already in it.
 *
 * The acts on a case used to sit in three places: four header actions on the
 * page, the transitions the stages widget drew, and a delete behind its own
 * button. Each was gated differently, and a handler found out what they could
 * do by trying.
 *
 * So this merges the three reads the page already makes into one ordered list:
 *
 *  - `/available-transitions`, the moves the lifecycle provider publishes,
 *    each already carrying `guardsPassed` and the guard's own sentence;
 *  - `/lifecycle`, which of suspend, resume, extend and reopen the case allows;
 *  - `/acts`, the role-gated acts, each with the group that would grant it.
 *
 * 🔴 A REFUSED ACT IS IN THE LIST, DISABLED, WITH ITS REASON. Never absent.
 * An act that is simply missing teaches nobody why, and the handler's next
 * move depends entirely on the answer: "you may not archive this" ends the
 * conversation, "archiving needs the archivaris group" starts one.
 *
 * 🔑 IT DERIVES NOTHING THE SERVER ALREADY DECIDED. Every `disabled` and
 * every `reason` here is copied from an answer, not computed. A second
 * derivation would eventually offer a move the write refuses, and the handler
 * would meet that disagreement as a button that fails after they press it.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import { transitionBlockReason, transitionIsBlocked } from './caseLifecycleHelpers.js'

/**
 * The four statutory gestures, in the order the menu shows them.
 *
 * Each names the flag on `/lifecycle` that decides whether it is offered, and
 * the sentence to show when it is not. The sentences are the same ones
 * `refusalMessage` prints for the matching code, so the menu and the dialog
 * behind it cannot say different things about the same refusal.
 *
 * @type {Array<{act: string, flag: string, label: string, reason: string}>}
 */
export const TERM_GESTURES = [
	{
		act: 'suspend',
		flag: 'canSuspend',
		label: 'Suspend',
		reason: 'This case type does not allow suspension.',
	},
	{
		act: 'resume',
		flag: 'canResume',
		label: 'Resume',
		reason: 'This case is not suspended.',
	},
	{
		act: 'extend',
		flag: 'canExtend',
		label: 'Extend term',
		reason: 'This case type does not allow an extension.',
	},
	{
		act: 'reopen',
		flag: 'canReopen',
		label: 'Reopen',
		reason: 'Only a closed case can be reopened.',
	},
]

/**
 * The three ending acts, in the order the menu shows them.
 *
 * @type {Array<{act: string, label: string, explainer: string}>}
 */
export const ENDING_ACTS = [
	{
		act: 'finish',
		label: 'Finish',
		explainer: 'The case reached its result. The result decides what is kept.',
	},
	{
		act: 'abort',
		label: 'Abort',
		explainer: 'An intrekking. There is a result, and it is not a besluit.',
	},
	{
		act: 'archive',
		label: 'Archive',
		explainer: 'The case moves to the retention rule its result type carries.',
	},
]

/**
 * Whether a value read back from JSON means true.
 *
 * @param {boolean|number|string|null|undefined} value The stored value.
 * @return {boolean} True only for the values that mean true.
 */
const yes = (value) => value === true || value === 1 || value === '1' || value === 'true'

/**
 * The transitions half of the menu.
 *
 * @param {Array<object>} transitions The `/available-transitions` answer.
 * @return {Array<object>} The entries.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
function transitionEntries(transitions) {
	return (Array.isArray(transitions) ? transitions : [])
		.filter((row) => row && typeof row === 'object')
		.map((row) => ({
			kind: 'transition',
			id: String(row.id ?? row.action ?? ''),
			label: String(row.label ?? row.name ?? ''),
			// A guard that refused with no sentence of its own still disables
			// the entry: a button that stayed enabled because nobody wrote a
			// message would send the handler into a refusal instead of telling
			// them beforehand.
			disabled: transitionIsBlocked(row),
			reason: transitionBlockReason(row),
			toStatus: String(row.toStatus ?? ''),
		}))
		.filter((entry) => entry.id !== '')
}

/**
 * The four statutory gestures, offered or refused with the reason.
 *
 * @param {object|null} state The `/lifecycle` answer.
 * @return {Array<object>} The entries.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
function gestureEntries(state) {
	// An unreadable state offers the gestures ENABLED rather than hiding them.
	// The endpoint is the authority either way, and a menu that greyed
	// everything out because its own read failed would look exactly like a
	// case nobody is allowed to touch.
	const unread = !state || typeof state !== 'object'

	return TERM_GESTURES.map((gesture) => ({
		kind: 'gesture',
		id: gesture.act,
		label: gesture.label,
		disabled: unread ? false : state[gesture.flag] !== true,
		reason: unread ? '' : (state[gesture.flag] === true ? '' : gesture.reason),
	}))
}

/**
 * The three role-gated ending acts.
 *
 * @param {object|null} acts The `/acts` answer.
 * @return {Array<object>} The entries.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
function endingEntries(acts) {
	const verdicts = Array.isArray(acts?.acts) ? acts.acts : []

	return ENDING_ACTS.map((ending) => {
		const verdict = verdicts.find((row) => String(row?.act ?? '') === ending.act)

		return {
			kind: 'ending',
			id: ending.act,
			label: ending.label,
			explainer: ending.explainer,
			// An absent verdict disables the act and says so, rather than
			// offering it. These three write archival consequences, and the
			// reading that is safe for a term gesture is not safe here.
			disabled: verdict ? verdict.allowed !== true : true,
			reason: verdict
				? String(verdict.reason ?? '')
				: 'What you may do with this case could not be read.',
			role: verdict ? String(verdict.role ?? '') : '',
		}
	})
}

/**
 * Hold, release and promote, which depend on the case's own state.
 *
 * @param {object|null} acts The `/acts` answer.
 * @return {Array<object>} The entries.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
function stateEntries(acts) {
	const held = yes(acts?.held)
	const draft = yes(acts?.draft)
	const entries = []

	if (held) {
		entries.push({
			kind: 'state',
			id: 'release-hold',
			label: 'Take off hold',
			explainer: 'You pick the case up again before its date.',
			disabled: false,
			reason: '',
		})
	} else {
		entries.push({
			kind: 'state',
			id: 'hold',
			label: 'Hold',
			explainer: 'You park the case until a date. The statutory term keeps running.',
			disabled: false,
			reason: '',
		})
	}

	if (draft) {
		entries.push({
			kind: 'state',
			id: 'promote',
			label: 'Promote to case',
			explainer: 'The term starts now. The case leaves your drafts.',
			disabled: false,
			reason: '',
		})
	}

	return entries
}

/**
 * The whole menu, in the order a handler reads it.
 *
 * Transitions first, because moving the case along is the ordinary act.
 * Then the statutory gestures over the term, then the three ways to end it,
 * then the state acts. Ending last on purpose: the irreversible acts are not
 * the ones a hurried handler should meet at the top of a list.
 *
 * @param {object} answers The three server answers.
 * @param {Array<object>} [answers.transitions] The `/available-transitions` list.
 * @param {object|null} [answers.state] The `/lifecycle` answer.
 * @param {object|null} [answers.acts] The `/acts` answer.
 * @return {Array<object>} The menu entries.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
export function buildActsMenu({ transitions, state, acts } = {}) {
	return [
		...transitionEntries(transitions),
		...gestureEntries(state),
		...endingEntries(acts),
		...stateEntries(acts),
	]
}

/**
 * Which extra input an act asks for before it can be posted.
 *
 * Read off the act rather than off a form, so the dialog cannot ask for a
 * result on an act that does not take one, and cannot skip the wake date on a
 * hold, which the server refuses.
 *
 * @param {object} entry One menu entry.
 * @return {{reason: boolean, result: boolean, until: boolean, days: boolean}} What to ask for.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
export function inputsFor(entry) {
	const id = String(entry?.id ?? '')

	return {
		// Promote is the one act with nothing to justify: it starts a term
		// rather than changing one, and asking for a reason would be a field
		// nobody can fill in honestly.
		reason: id !== 'promote',
		result: id === 'finish' || id === 'abort',
		until: id === 'hold',
		days: id === 'suspend',
	}
}

/**
 * The endpoint one menu entry posts to.
 *
 * @param {object} entry One menu entry.
 * @return {string} The path after `/api/case/{id}/`, empty for a transition.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
export function endpointFor(entry) {
	if (!entry || entry.kind === 'transition') {
		return ''
	}

	return String(entry.id ?? '')
}
