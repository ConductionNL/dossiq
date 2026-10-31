/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What one lifecycle act asks for, as input declarations for the library's
 * CnTransitionInputFields: a result template and a result for an ending
 * act, a reason, a return date, or a number of days, and around them the
 * act's own layout (two columns, a summary of the case, a notice).
 *
 * Which fields an act asks is `inputsFor` (caseActsMenu.js). How an act lays
 * them out is `ACT_LAYOUTS` below, keyed by act id; an act without an entry
 * gets the plain layout. Every label is a literal `t()` call, so the l10n
 * extractor finds it.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

import { translate as t } from '@nextcloud/l10n'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import { inputsFor } from './caseActsMenu.js'
import { contentTemplateOption } from './templatePicker.js'

/**
 * The layout of the acts that have one of their own.
 *
 * - `columns: 2` sets the inputs side by side (board DqVolgendeStap).
 * - `pauseReasons` offers the case type's pause reasons before the free text.
 * - `reasonWidget` is the reason's field: one line beside the days, else a text area.
 * - `summary` and `notice` show the case and what the act makes of it; a
 *   notice's `icon` names a CnIcon (board DqArchiveren draws a lock).
 * - `reasonNote` replaces the line under the reason; '' draws none (DqVolgendeStap).
 * - `notifyRequester` adds a ticked checkbox that tells the requester afterwards.
 * - `title` names the dialog while the act is open; `confirmIcon` sits on its confirm button.
 *
 * @return {object} The layouts by act id.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
function actLayouts() {
	return {
		suspend: {
			columns: 2,
			pauseReasons: true,
			reasonWidget: 'text',
			reasonNote: '',
		},
		archive: {
			title: t('dossiq', 'Archive this case'),
			confirmIcon: ArchiveOutline,
			reasonNote: t('dossiq', 'Goes into the history of the case. To bring it back, use Restore from the archive under Next step.'),
			notifyRequester: true,
			summary: {
				when: 'kase',
				testid: 'case-act-archive',
				card: {
					icon: FolderOutline,
					title: 'kase.title',
					meta: ['kase.identifier', 'kase.initiatorDisplayName'],
					badge: 'kase.resultPublicLabel',
				},
				title: t('dossiq', 'Retention under the case type'),
				facts: [
					{ label: t('dossiq', 'Archive nomination'), path: 'kase.archiveNomination', fallback: t('dossiq', 'Not set') },
					{ label: t('dossiq', 'Destruction date'), path: 'kase.archiveActionDate', fallback: t('dossiq', 'Not set') },
					{ label: t('dossiq', 'Archive status afterwards'), fallback: t('dossiq', 'Archived') },
				],
			},
			notice: {
				type: 'info',
				icon: 'LockOutline',
				heading: t('dossiq', 'Read only afterwards'),
				text: t('dossiq', 'Once archived, the documents, tasks and messages of this case can no longer be changed. The case stays findable until its archive action date.'),
			},
		},
	}
}

/**
 * The form one act opens: its input declarations and its layout.
 *
 * The values these inputs write are `reason`, `resultTypeId`, `until`,
 * `days` and `pauseReason`; the context they read is `{ kase, pauseReasons,
 * resultTypes }`.
 *
 * @param {object|null} entry The chosen act, a menu entry.
 * @param {object} [scope] What scopes the result templates.
 * @param {string} [scope.caseType] The case type.
 * @param {string} [scope.caseId] The case id.
 * @param {string} [scope.requester] The requester's name, for the notify checkbox.
 * @return {{inputs: Array<object>, columns: number, summary: object|null, notice: object|null, title: string, confirmIcon: object|null}} The form.
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
export function actForm(entry, { caseType = '', caseId = '', requester = '' } = {}) {
	if (!entry) {
		return { inputs: [], columns: 1, summary: null, notice: null, title: '', confirmIcon: null }
	}
	const asks = inputsFor(entry)
	const layout = actLayouts()[String(entry.id ?? '')] ?? {}
	const inputs = []

	if (asks.result) {
		// A template presets the outcome text and never writes over it.
		inputs.push({
			field: 'resultTemplate',
			widget: 'template',
			url: '/apps/dossiq/api/content-templates/result',
			params: { caseType, case: caseId },
			mapOption: contentTemplateOption,
			label: t('dossiq', 'Result template'),
			placeholder: t('dossiq', 'Start from a template'),
			applyTo: 'reason',
			testid: 'template-picker',
		})
	}
	if (asks.reason && layout.pauseReasons) {
		// The case type's reasons, then Other for free text. Without
		// declared reasons the picker is hidden and the text alone is asked.
		inputs.push({
			field: 'pauseReason',
			optionsSource: 'pauseReasons',
			optionValue: 'key',
			optionLabel: 'name',
			required: true,
			label: t('dossiq', 'Reason'),
			other: { label: t('dossiq', 'Other'), input: 'reason' },
			testid: 'case-act-pause-reason-picker',
		})
	}
	if (asks.reason) {
		inputs.push({
			field: 'reason',
			widget: layout.reasonWidget ?? 'textarea',
			required: true,
			label: t('dossiq', 'Reason'),
			labelOutside: true,
			id: 'case-act-reason',
			note: layout.reasonNote ?? t('dossiq', 'Goes into the history of the case.'),
			rows: 2,
			testid: 'case-act-reason-input',
		})
	}
	if (asks.result) {
		// A closing move or an ending act picks from the case type's own
		// result types. There is no free field: a typed name is not an id,
		// and a case type with no results needs none.
		inputs.push({
			field: 'resultTypeId',
			optionsSource: 'resultTypes',
			optionValue: 'id',
			optionLabel: 'name',
			required: true,
			label: t('dossiq', 'Result'),
			testid: 'case-act-result-picker',
		})
	}
	if (asks.until) {
		inputs.push({
			field: 'until',
			widget: 'date',
			required: true,
			label: t('dossiq', 'The case comes back on'),
			testid: 'case-act-until-input',
		})
	}
	if (asks.days) {
		inputs.push({
			field: 'days',
			widget: 'number',
			label: t('dossiq', 'Days the applicant is given'),
			labelOutside: true,
			id: 'case-act-days',
			// The days the applicant gets are the recovery period of Awb 4:5;
			// a picked pause reason names its own basis instead.
			description: t('dossiq', 'Awb 4:5, recovery period'),
			helperFrom: { input: 'pauseReason', key: 'legalBasis' },
			prefillFrom: { input: 'pauseReason', key: 'defaultDays' },
			dateField: {
				label: t('dossiq', 'The case comes back on'),
				note: t('dossiq', 'You pick the case up earlier if the answer comes in earlier.'),
				testid: 'case-act-comes-back',
			},
			testid: 'case-act-days-input',
		})
	}

	if (layout.notifyRequester) {
		inputs.push({
			field: 'notifyRequester',
			widget: 'checkbox',
			default: true,
			required: false,
			label: requester === ''
				? t('dossiq', 'Let the requester know the case is completed')
				: t('dossiq', 'Let {initiatorDisplayName} know the case is completed', { initiatorDisplayName: requester }),
			testid: 'case-act-notify-requester',
		})
	}

	return {
		inputs,
		columns: layout.columns ?? 1,
		summary: layout.summary ?? null,
		notice: layout.notice ?? null,
		title: layout.title ?? '',
		confirmIcon: layout.confirmIcon ?? null,
	}
}
