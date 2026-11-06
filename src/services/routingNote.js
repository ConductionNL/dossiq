/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case's routing note in the reader's language.
 *
 * @spec openspec/changes/case-routing-note/specs/kcc-routing/spec.md#requirement-the-team-queue-says-why-a-case-stands-there
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * The sentence of a fan-out note.
 *
 * @param {object} note The note.
 * @return {string} What the submission did.
 */
function intakeSplitLabel(note) {
	const siblings = (note.siblings || []).join(', ')
	const parts = [siblings === ''
		? t('dossiq', 'From submission {submission}.', { submission: note.submission })
		: t('dossiq', 'From submission {submission}, together with {siblings}.', { submission: note.submission, siblings })]
	for (const entry of note.failed || []) {
		parts.push(t('dossiq', 'Destination {destination} failed: {reason}.', entry))
	}
	return parts.join(' ')
}

/**
 * The line a list row reads for a case's routing note.
 *
 * @param {object|null} note The case's `routingNote`.
 * @return {string} The line, or '' without a note.
 */
export function routingNoteLabel(note) {
	if (!note || typeof note !== 'object') {
		return ''
	}
	if (note.kind === 'handover-refused') {
		return t('dossiq', 'Assigning to team {team} refused: {reason}', { team: note.team, reason: note.reason })
	}
	return note.kind === 'intake-split' ? intakeSplitLabel(note) : ''
}
