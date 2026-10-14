// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import { translate as t } from '@nextcloud/l10n'

/**
 * The reader's word for an engine task state.
 *
 * The engine keeps its own vocabulary (`Task::STATES` in openregister:
 * available, enabled, active, completed, terminated, disabled), and so does
 * every store and filter here. This is the ONE place that turns a state into
 * words for a reader. The case plan and the task page both use it, so the two
 * cannot name the same state differently.
 *
 * The words are DqTaak's: an active task is "In uitvoering" ("In execution"),
 * which keeps it apart from a case "In behandeling".
 *
 * A switch of literal `t()` calls, so the l10n extractor sees every key. A
 * state nobody wrote a word for is shown as the engine wrote it.
 *
 * @param {string|null|undefined} state The engine state.
 * @return {string} The label, or '' for no state.
 * @spec openspec/specs/task-management/spec.md
 */
export function taskStateLabel(state) {
	switch (String(state ?? '').trim()) {
		case '':
			return ''
		case 'available':
			return t('dossiq', 'Waiting')
		case 'enabled':
			return t('dossiq', 'Ready to start')
		case 'active':
			return t('dossiq', 'In execution')
		case 'completed':
			return t('dossiq', 'Done')
		case 'terminated':
			return t('dossiq', 'Stopped')
		case 'disabled':
			return t('dossiq', 'Skipped')
		default:
			return String(state).trim()
	}
}

/**
 * The badge tone an engine task state is drawn in.
 *
 * DqTaak draws an active task "In uitvoering" in the board's work-in-progress
 * tone (purple), which keeps it apart from a case "In behandeling" in blue. A
 * finished task reads as done (green). Every other state is neutral: waiting,
 * ready, stopped and skipped are facts about the task, not warnings.
 *
 * @param {string|null|undefined} state The engine state.
 * @return {string} A CnStatusBadge variant.
 * @spec openspec/specs/task-management/spec.md
 */
export function taskStateTone(state) {
	switch (String(state ?? '').trim()) {
		case 'active':
			return 'purple'
		case 'completed':
			return 'success'
		default:
			return 'default'
	}
}
