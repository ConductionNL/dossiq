/**
 * Turning the server's four clocks into rows a handler can read.
 *
 * 🔴 NO ARITHMETIC ON A DATE LIVES HERE. Every number this module handles was
 * computed by dossiq against the organisation's working calendar. What it does
 * is choose a label, a tone and an order, which is presentation and belongs in
 * the browser.
 *
 * The order is deliberate: statutory, planned, internal, phase. A handler asked
 * "when is this due" means the term the citizen was told about, so it goes
 * first; a phase clock is the most local and goes last. An overdue clock keeps
 * its place rather than jumping to the top, because a list that reorders itself
 * as the days pass is a list nobody can scan twice.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

/** The order the clocks are read in on a case page. */
export const KIND_ORDER = ['statutory', 'planned', 'internal', 'phase']

/**
 * The label for one kind, in the reader's language.
 *
 * @param {string}   kind      One of the four kinds.
 * @param {(app: string, text: string, vars?: object) => string} t The `t` binding, passed in so this module needs no global.
 *
 * @return {string} The label.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
export function kindLabel(kind, t) {
	const labels = {
		statutory: t('dossiq', 'Statutory term'),
		planned: t('dossiq', 'Planned end'),
		internal: t('dossiq', 'Internal target'),
		phase: t('dossiq', 'Phase term'),
	}
	return labels[kind] || kind
}

/**
 * What one kind means, in one sentence.
 *
 * @param {string}   kind      One of the four kinds.
 * @param {(app: string, text: string, vars?: object) => string} t The `t` binding.
 *
 * @return {string} The sentence, empty for a kind we do not know.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
export function kindHint(kind, t) {
	const hints = {
		statutory: t('dossiq', 'The date the applicant was told about.'),
		planned: t('dossiq', 'What your team gave itself. The applicant was not told this.'),
		internal: t('dossiq', 'A team target. It never reaches the applicant.'),
		phase: t('dossiq', 'This phase only. It never moves the case term.'),
	}
	return hints[kind] || ''
}

/**
 * How a clock reads right now: on time, due soon, or overdue.
 *
 * `warnWithin` is a presentation threshold and nothing else. The warning a case
 * type declares is raised server-side and reaches a handler as a notification;
 * this only decides whether a row is drawn in the warning colour.
 *
 * @param {object} term         One shaped term from the server.
 * @param {number} [warnWithin] How many days ahead reads as due soon.
 *
 * @return {string} One of `overdue`, `soon`, `ontime`, `unknown`.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 */
export function termTone(term, warnWithin = 5) {
	if (!term || !term.endDate) {
		return 'unknown'
	}
	if (term.overdue === true) {
		return 'overdue'
	}
	if (typeof term.daysLeft === 'number' && term.daysLeft <= warnWithin) {
		return 'soon'
	}
	return 'ontime'
}

/**
 * The days-left count as a sentence.
 *
 * @param {object}   term      One shaped term from the server.
 * @param {(app: string, text: string, vars?: object) => string} t The `t` binding.
 *
 * @return {string} The sentence.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 */
export function daysLeftSentence(term, t) {
	if (!term || typeof term.daysLeft !== 'number' || !term.endDate) {
		return t('dossiq', 'No end date')
	}
	if (term.overdue === true) {
		return t('dossiq', '{days} days over', { days: Math.abs(term.daysLeft) })
	}
	if (term.daysLeft === 0) {
		return t('dossiq', 'Due today')
	}
	return t('dossiq', '{days} days left', { days: term.daysLeft })
}

/**
 * The clocks in reading order, each with a label, a hint and a tone.
 *
 * A clock whose kind the server sends and this module does not know still gets
 * a row, at the end, labelled with the raw kind. Dropping it would hide a
 * deadline because a front end was older than a back end.
 *
 * @param {Array}    terms     The terms as the server answered them.
 * @param {(app: string, text: string, vars?: object) => string} t The `t` binding.
 *
 * @return {Array} The rows to render.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
export function termRows(terms, t) {
	if (!Array.isArray(terms)) {
		return []
	}

	return terms
		.filter((term) => term && typeof term === 'object')
		.map((term) => ({
			...term,
			label: kindLabel(term.kind, t),
			hint: kindHint(term.kind, t),
			tone: termTone(term),
			sentence: daysLeftSentence(term, t),
		}))
		.sort((left, right) => rank(left.kind) - rank(right.kind))
}

/**
 * Whether the case has anything a handler has to act on now.
 *
 * @param {object} progress The progress block the server answered.
 *
 * @return {boolean} True when any clock has run out.
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
 */
export function needsAttention(progress) {
	if (!progress || typeof progress !== 'object') {
		return false
	}
	return (
		progress.statutoryOverdue === true
		|| progress.plannedOverdue === true
		|| progress.phaseOverdue === true
	)
}

/**
 * Where a kind sits in the reading order.
 *
 * @param {string} kind The kind.
 *
 * @return {number} Its position, and the end of the list for one we do not know.
 */
function rank(kind) {
	const index = KIND_ORDER.indexOf(kind)
	return index === -1 ? KIND_ORDER.length : index
}
