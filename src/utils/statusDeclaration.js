// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What a status declares, as the interface reads it.
//
// The engine publishes three things beside the transitions it offers: who the
// case is waiting on, how long it has been where it is, and — for a status the
// case type derives rather than offers — what is still missing. All three
// arrive on the `/available-transitions` answer, because the handler asks them
// in the same breath as "what can I do with this case".
//
// Kept out of the components so the shapes can be tested without mounting
// anything, and so the two places that read them (the case panel and the list
// cell) cannot drift into two readings of the same payload.
//
// @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md

/**
 * The three values a status may declare about who the case waits on.
 *
 * `us` is also what an UNDECLARED status reads as, which is why there is no
 * fourth value: a bucket called "not declared" would hold most of the queue on
 * every case type nobody has annotated.
 */
export const WAITING_ON_VALUES = ['us', 'applicant', 'thirdParty']

/**
 * What the reader is told about who the case is waiting on.
 *
 * @param {string} waitingOn The value the engine published.
 * @return {string} The sentence, or the empty string when the case is ours to
 *   move — a case nobody is waiting on needs no line saying so.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function waitingOnLabel(waitingOn) {
	if (waitingOn === 'applicant') {
		return t('dossiq', 'Waiting on the applicant')
	}
	if (waitingOn === 'thirdParty') {
		return t('dossiq', 'Waiting on someone outside the organisation')
	}
	return ''
}

/**
 * How long the case has been in its status, as a reader reads it.
 *
 * Working days, because that is what the number is counted in and rendering it
 * as "35 days" over a figure of 25 working days would be a second measurement
 * nobody asked for.
 *
 * @param {object} dwell The engine's dwell block.
 * @return {string} The sentence, or the empty string when there is nothing to say.
 *
 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
 */
export function dwellLabel(dwell) {
	const days = Number(dwell?.days ?? 0)
	if (!Number.isFinite(days) || days < 1) {
		return ''
	}
	return n(
		'dossiq',
		'{count} working day in this status',
		'{count} working days in this status',
		days,
		{ count: days },
	)
}

/**
 * Whether the case has been in its status longer than the status allows.
 *
 * Read off the engine's answer rather than recomputed from days and maximum:
 * the boundary case (exactly the maximum is not yet a breach) is decided in
 * one place, and a second reading of it here would be a second place for it to
 * be decided differently.
 *
 * @param {object} dwell The engine's dwell block.
 * @return {boolean} True when the status maximum is past.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function isDwellBreached(dwell) {
	return dwell?.breached === true
}

/**
 * What is missing before a derived status becomes true.
 *
 * The whole reason the derivation publishes anything at all. A "Complete" that
 * never arrives, with nothing on the case saying why, sends the handler to ask
 * a colleague rather than to fetch the document.
 *
 * @param {object} derivation The engine's derivation block, or null.
 * @return {{name: string, unmet: Array<string>}|null} The status and what it
 *   is still waiting for, or null when nothing is pending.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function derivationReasons(derivation) {
	if (!derivation || typeof derivation !== 'object') {
		return null
	}

	const unmet = Array.isArray(derivation.unmet)
		? derivation.unmet.map((reason) => String(reason ?? '').trim()).filter(Boolean)
		: []

	if (unmet.length === 0) {
		return null
	}

	return { name: String(derivation.name ?? ''), unmet }
}

/**
 * The moves this case cannot make yet, and what is in the way.
 *
 * A withheld transition is NOT an error and not an empty list: it is a move
 * that exists, with a reason readable in its place. That is the whole of D-1.
 * A handler who sees nothing learns that the list is unreliable; a handler who
 * reads "waiting on the advice request" learns what to do next.
 *
 * @param {Array<object>} withheld The `/available-transitions` answer's `withheld`.
 * @return {Array<{id: string, label: string, reasons: Array<string>}>} The entries worth rendering.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */
export function withheldTransitions(withheld) {
	if (Array.isArray(withheld) === false) {
		return []
	}

	return withheld
		.map((entry) => ({
			id: String(entry?.id ?? ''),
			label: String(entry?.label ?? '').trim(),
			reasons: Array.isArray(entry?.reasons)
				? entry.reasons.map((reason) => String(reason ?? '').trim()).filter(Boolean)
				: [],
		}))
		// An entry with no reason is worse than no entry: it says a move is
		// unavailable and refuses to say why, which is the failure this whole
		// change exists to remove.
		.filter((entry) => entry.reasons.length > 0)
}

/**
 * What a withheld move is waiting on, as one sentence.
 *
 * The FIRST reason, because a handler acts on one thing at a time and the
 * list is in declaration order, which is the order the case type author meant.
 *
 * @param {object} entry One withheld entry.
 * @return {string} The sentence.
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */
export function withheldSentence(entry) {
	const label = String(entry?.label ?? '').trim()
	const reason = String(entry?.reasons?.[0] ?? '').trim()
	if (reason === '') {
		return ''
	}

	if (label === '') {
		return t('dossiq', 'Waiting on {reason}', { reason })
	}

	return t('dossiq', '{move} is waiting on {reason}', { move: label, reason })
}

/**
 * The sentence above the missing things.
 *
 * Names the status, because a handler working a case type with three derived
 * statuses cannot otherwise tell which one is being talked about.
 *
 * @param {string} name The derived status's name.
 * @return {string} The sentence.
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
export function derivationHeading(name) {
	return t('dossiq', 'This case moves to {status} once these are on file', {
		status: name,
	})
}
