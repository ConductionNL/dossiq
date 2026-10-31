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

import {
	transitionBlockReason,
	transitionIsBlocked,
} from './caseLifecycleHelpers.js'

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
		label: 'Reopen or restore from the archive',
		reason: 'Only for a finished or archived case.',
	},
]

/**
 * Why Extend term is refused on a case that used every extension its case
 * type allows. The same sentence `refusalMessage` prints for
 * `extension_limit_reached`.
 *
 * @type {string}
 */
export const EXTENSION_LIMIT_REASON =
	'The term of this case was already extended as often as its case type allows.'

/**
 * Whether the case used every extension its case type allows.
 *
 * @param {object} state The `/lifecycle` answer.
 * @return {boolean} False when the case type states no limit.
 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-a-case-type-states-how-often-its-term-may-be-extended-req-tel-001
 */
function extensionsUsedUp(state) {
	const limit = state?.maxExtensions
	if (typeof limit !== 'number' || !Number.isInteger(limit) || limit < 1) {
		return false
	}

	return Number(state?.extensionCount ?? 0) >= limit
}

/**
 * The sentence a refused gesture shows.
 *
 * @param {{act: string, reason: string}} gesture One of TERM_GESTURES.
 * @param {object} state The `/lifecycle` answer.
 * @return {string} The refusal.
 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-a-case-type-states-how-often-its-term-may-be-extended-req-tel-001
 */
function gestureReason(gesture, state) {
	if (gesture.act === 'extend' && extensionsUsedUp(state)) {
		return EXTENSION_LIMIT_REASON
	}

	return gesture.reason
}

/**
 * What an offered gesture says beforehand, '' for the gestures that say nothing.
 *
 * Board DqVolgendeStap: the extension names its limit, its reason and the
 * message the requester gets before the handler chooses it.
 *
 * @param {{act: string}} gesture One of TERM_GESTURES.
 * @param {object|null} state The `/lifecycle` answer.
 * @return {string} The explainer, as an l10n key.
 * @spec openspec/changes/woo-case-screens-and-objections/specs/term-extension-limit/spec.md#requirement-an-extension-answers-whether-the-requester-was-told-req-tel-002
 */
function gestureExplainer(gesture, state) {
	if (gesture.act === 'suspend') {
		return 'The term stands still until the answer is in.'
	}
	if (gesture.act === 'reopen') {
		return 'Only for a finished or archived case.'
	}
	if (gesture.act !== 'extend') {
		return ''
	}

	return state?.maxExtensions === 1
		? 'Once, with a reason. The requester gets a message.'
		: 'With a reason. The requester gets a message.'
}

/**
 * The three ending acts, in the order the menu shows them.
 *
 * @type {Array<{act: string, label: string, explainer: string}>}
 */
export const ENDING_ACTS = [
	{
		act: 'finish',
		label: 'Finish',
		explainer: 'Choose the result type. The end date and the archive outcome are recorded.',
	},
	{
		act: 'abort',
		label: 'Break off',
		explainer: 'The case stops without a decision. Asks for a reason.',
	},
	{
		act: 'archive',
		label: 'Archive it',
		explainer: 'The case moves to the retention rule its result type carries.',
	},
]

/**
 * Whether a value read back from JSON means true.
 *
 * @param {boolean|number|string|null|undefined} value The stored value.
 * @return {boolean} True only for the values that mean true.
 */
function yes(value) {
	return value === true || value === 1 || value === '1' || value === 'true'
}

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
			toStatusName: String(row.toStatusName ?? ''),
			// Copied from the engine's answer, never derived here: whether
			// the move closes the case, and the results it may close with.
			closesCase: row.closesCase === true,
			resultTypes: resultChoices(row.resultTypes),
		}))
		.filter((entry) => entry.id !== '')
}

/**
 * The result types a closing move offers, as id and name.
 *
 * @param {Array<object>|undefined} rows The engine's `resultTypes`.
 * @return {Array<{id: string, name: string}>} The choices, without blank ids.
 * @spec openspec/specs/status-transition-engine/spec.md
 */
function resultChoices(rows) {
	return (Array.isArray(rows) ? rows : [])
		.filter(
			(row) => row && typeof row === 'object' && String(row.id ?? '') !== '',
		)
		.map((row) => ({
			id: String(row.id),
			name: String(row.name ?? '') || String(row.id),
		}))
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
		reason: unread
			? ''
			: state[gesture.flag] === true
				? ''
				: gestureReason(gesture, state),
		explainer: gestureExplainer(gesture, unread ? null : state),
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
	const archived = yes(acts?.archived)

	return ENDING_ACTS.filter((ending) => {
		// Archive leaves the list once the case is in the archive, and Restore
		// takes its place (see archiveStateEntries). Two entries both enabled
		// would let a handler archive a case that is already archived, which
		// the platform answers with a shrug rather than a refusal, so nothing
		// on screen would say what had happened. Finish and Abort stay: they
		// are refused by their own guards, each with a sentence.
		return ending.act !== 'archive' || archived === false
	}).map((ending) => {
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
			// Finish and abort close with one of the case type's results, the
			// same list the act refuses against. Archive takes the result the
			// case already has.
			resultTypes:
				ending.act === 'archive' ? [] : resultChoices(acts?.resultTypes),
		}
	})
}

/**
 * Restore, offered on an archived case and on no other.
 *
 * 🔑 THE ENTRY IS READ OFF THE PLATFORM'S MARKER, not off `archiveStatus`.
 * `/acts` answers `archived` from `@self.archived`, which is the same fact the
 * lists exclude on, so the menu and the Cases page cannot disagree about which
 * cases are in the archive. A case imported over ZGW carrying
 * `archiefstatus: gearchiveerd` and no marker is in every working lens, and
 * offering Restore on it would be a button that takes nothing back.
 *
 * It is its own entry rather than an ending act because it is the one act on
 * this menu that UNDOES something.
 *
 * @param {object|null} acts The `/acts` answer.
 * @return {Array<object>} The entries.
 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
 */
function archiveStateEntries(acts) {
	if (!yes(acts?.archived)) {
		return []
	}

	const verdicts = Array.isArray(acts?.acts) ? acts.acts : []
	const verdict = verdicts.find((row) => String(row?.act ?? '') === 'archive')

	return [
		{
			kind: 'state',
			id: 'unarchive',
			label: 'Reopen or restore from the archive',
			explainer: 'Only for a finished or archived case.',
			// Restoring asks the same role archiving does, so the verdict on
			// `archive` is the verdict on this. An absent verdict disables it
			// and says so, exactly as the ending acts do: this one writes an
			// archival consequence too.
			disabled: verdict ? verdict.allowed !== true : true,
			reason: verdict
				? String(verdict.reason ?? '')
				: 'What you may do with this case could not be read.',
			role: verdict ? String(verdict.role ?? '') : '',
		},
	]
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
			explainer: 'Park it internally, the term keeps running.',
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
 * The order of the acts after the transitions, as board DqVolgendeStap draws
 * them: finishing straight after the moves, then the acts on the term and on
 * the handler's own time, then the acts that end or undo.
 *
 * @type {Array<string>}
 */
export const ACT_ORDER = [
	'finish',
	'suspend',
	'extend',
	'hold',
	'release-hold',
	'promote',
	'resume',
	'abort',
	'archive',
	'unarchive',
	'reopen',
]

/**
 * The icon each act shows in the menu, by act id; a transition shows an arrow.
 *
 * @type {Object<string, string>}
 */
export const ACT_ICONS = {
	transition: 'ArrowRight',
	finish: 'FlagOutline',
	suspend: 'PauseCircleOutline',
	extend: 'CalendarClock',
	hold: 'TimerSandEmpty',
	'release-hold': 'TimerSandEmpty',
	promote: 'ArrowUpBoldCircle',
	resume: 'Play',
	abort: 'StopCircleOutline',
	archive: 'ArchiveOutline',
	unarchive: 'Restore',
	reopen: 'History',
}

/**
 * The icon of one menu entry, '' when it has none.
 *
 * @param {object} entry One menu entry.
 * @return {string} A registered icon name.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
export function iconFor(entry) {
	if (entry?.kind === 'transition') {
		return ACT_ICONS.transition
	}
	if (entry?.kind === 'always') {
		return String(entry.icon ?? '')
	}

	return ACT_ICONS[String(entry?.id ?? '')] ?? ''
}

/**
 * The whole menu, in the order a handler reads it.
 *
 * Transitions first, because moving the case along is the ordinary act, then
 * the acts the case type allows in every phase, then the rest in ACT_ORDER.
 *
 * @param {object} answers The three server answers.
 * @param {Array<object>} [answers.transitions] The `/available-transitions` list.
 * @param {object|null} [answers.state] The `/lifecycle` answer.
 * @param {object|null} [answers.acts] The `/acts` answer, carrying both the
 *   role-gated ending acts and the case type's always-available ones.
 * @return {Array<object>} The menu entries.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
export function buildActsMenu({ transitions, state, acts } = {}) {
	const rank = (entry) => {
		const at = ACT_ORDER.indexOf(entry.id)
		return at === -1 ? ACT_ORDER.length : at
	}
	// Reopen and Restore are one entry, as board DqVolgendeStap draws it:
	// an archived case refuses every write until it is restored, so on an
	// archived case the entry restores, and otherwise it reopens.
	const restore = archiveStateEntries(acts)
	const gestures = gestureEntries(state)
		.filter((entry) => restore.length === 0 || entry.id !== 'reopen')
	const acting = [
		...gestures,
		...endingEntries(acts),
		...stateEntries(acts),
		...restore,
	].sort((a, b) => rank(a) - rank(b))

	return [
		...transitionEntries(transitions),
		...alwaysAvailableEntries(acts),
		...acting,
	]
}

/**
 * The acts the case type allows in EVERY phase, marked as such.
 *
 * Second in the list, straight after the phase's own moves, because that is
 * the reading order a handler expects: what this phase is asking of me, then
 * what I can always do. The ending acts stay last.
 *
 * 🔴 THEY ARE NOT PHASES. An always-available act never enters the phase
 * strip, the progress figure or a term, and it is kept out of all three by
 * being its own declared list on the case type rather than a status. `kind`
 * is what a surface reads to mark it, and nothing downstream treats it as a
 * transition.
 *
 * @param {object|null} acts The `/acts` answer.
 * @return {Array<object>} The entries.
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
function alwaysAvailableEntries(acts) {
	const declared = Array.isArray(acts?.alwaysAvailable) ? acts.alwaysAvailable : []

	return declared
		.filter((act) => String(act?.id ?? '').trim() !== '')
		.map((act) => ({
			kind: 'always',
			id: String(act.id).trim(),
			label: String(act.label ?? act.id).trim(),
			explainer: String(act.description ?? '').trim(),
			icon: String(act.icon ?? '').trim(),
			// Shown and disabled with the guard's own sentence, never hidden:
			// an act that vanishes tells the reader the system cannot do it,
			// where one shown with its reason tells them who to ask.
			disabled: act.available === false,
			reason: String(act.reason ?? '').trim(),
		}))
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
		// A transition into a closing status asks for its result too, when
		// the case type has results to pick from. Without this the close was
		// refused with "pick a result" by a form that offered nothing to pick.
		result:
			id === 'finish'
			|| id === 'abort'
			|| (entry?.kind === 'transition'
				&& entry?.closesCase === true
				&& Array.isArray(entry?.resultTypes)
				&& entry.resultTypes.length > 0),
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
