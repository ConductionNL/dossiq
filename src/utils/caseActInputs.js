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
 * @spec openspec/specs/case-management/spec.md
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
 * @spec openspec/specs/case-management/spec.md
 */
function actLayouts() {
	return {
		suspend: {
			columns: 2,
			pauseReasons: true,
			reasonWidget: 'text',
			reasonNote: '',
		},
		archive: archiveLayout(),
	}
}

/**
 * The archive act's layout (board DqArchiveren): its title and confirm icon,
 * the case with its retention, and the read-only notice.
 *
 * @return {object} The layout.
 */
function archiveLayout() {
	return {
		title: t('dossiq', 'Archive this case'),
		confirmIcon: ArchiveOutline,
		reasonNote: t('dossiq', 'Goes into the history of the case. To bring it back, use Restore from the archive under Next step.'),
		notifyRequester: true,
		summary: archiveSummary(),
		notice: {
			type: 'info',
			icon: 'LockOutline',
			heading: t('dossiq', 'Read only afterwards'),
			text: t('dossiq', 'Once archived, the documents, tasks and messages of this case can no longer be changed. The case stays findable until its archive action date.'),
		},
	}
}

/**
 * The case being archived, with the retention its case type gives it.
 *
 * @return {object} The summary.
 */
function archiveSummary() {
	return {
		when: 'kase',
		testid: 'case-act-archive',
		card: {
			icon: FolderOutline,
			title: 'kase.title',
			meta: ['kase.identifier', 'kase.initiatorDisplayName'],
			badge: 'kase.resultPublicLabel',
		},
		title: t('dossiq', 'Retention under the case type'),
		facts: retentionFacts(),
	}
}

/**
 * The retention facts shown while archiving.
 *
 * @return {Array<object>} The facts.
 */
function retentionFacts() {
	return [
		{ label: t('dossiq', 'Archive nomination'), path: 'kase.archiveNomination', fallback: t('dossiq', 'Not set') },
		{ label: t('dossiq', 'Destruction date'), path: 'kase.archiveActionDate', fallback: t('dossiq', 'Not set') },
		{ label: t('dossiq', 'Archive status afterwards'), fallback: t('dossiq', 'Archived') },
	]
}

/**
 * What a form shows when the act's layout does not say, in form order.
 */
const FORM_DEFAULTS = Object.freeze({
	columns: 1,
	summary: null,
	notice: null,
	title: '',
	confirmIcon: null,
})

/**
 * The form around its inputs, laid out as the act's layout says.
 *
 * @param {object} layout The act's layout from actLayouts.
 * @param {Array<object>} inputs The form's inputs.
 * @return {object} The form.
 */
function formOf(layout, inputs) {
	const form = { inputs }
	for (const [key, fallback] of Object.entries(FORM_DEFAULTS)) {
		form[key] = layout[key] ?? fallback
	}
	return form
}

/**
 * The result template picker of an ending act.
 *
 * @param {object} run The form being built: `entry`, `asks`, `layout`, `scope`.
 * @return {object} The input.
 */
function resultTemplateInput(run) {
	// A template presets the outcome text and never writes over it.
	return {
		field: 'resultTemplate',
		widget: 'template',
		url: '/apps/dossiq/api/content-templates/result',
		params: { caseType: run.scope.caseType, case: run.scope.caseId },
		mapOption: contentTemplateOption,
		label: t('dossiq', 'Result template'),
		placeholder: t('dossiq', 'Start from a template'),
		applyTo: 'reason',
		testid: 'template-picker',
	}
}

/**
 * The pause reason picker: the case type's reasons, then Other for free
 * text. Without declared reasons the picker is hidden and the text alone
 * is asked.
 *
 * @return {object} The input.
 */
function pauseReasonInput() {
	return {
		field: 'pauseReason',
		optionsSource: 'pauseReasons',
		optionValue: 'key',
		optionLabel: 'name',
		required: true,
		label: t('dossiq', 'Reason'),
		other: { label: t('dossiq', 'Other'), input: 'reason' },
		testid: 'case-act-pause-reason-picker',
	}
}

/**
 * The reason field, in the widget and with the note the act's layout asks.
 *
 * @param {object} run The form being built.
 * @return {object} The input.
 */
function reasonInput(run) {
	return {
		field: 'reason',
		widget: run.layout.reasonWidget ?? 'textarea',
		required: true,
		label: t('dossiq', 'Reason'),
		labelOutside: true,
		id: 'case-act-reason',
		note: run.layout.reasonNote ?? t('dossiq', 'Goes into the history of the case.'),
		rows: 2,
		testid: 'case-act-reason-input',
	}
}

/**
 * The result picker. A closing move or an ending act picks from the case
 * type's own result types. There is no free field: a typed name is not an
 * id, and a case type with no results needs none.
 *
 * @return {object} The input.
 */
function resultTypeInput() {
	return {
		field: 'resultTypeId',
		optionsSource: 'resultTypes',
		optionValue: 'id',
		optionLabel: 'name',
		required: true,
		label: t('dossiq', 'Result'),
		testid: 'case-act-result-picker',
	}
}

/**
 * The date a held case comes back on.
 *
 * @return {object} The input.
 */
function untilInput() {
	return {
		field: 'until',
		widget: 'date',
		required: true,
		label: t('dossiq', 'The case comes back on'),
		testid: 'case-act-until-input',
	}
}

/**
 * The days the applicant is given, with the date the case comes back on.
 *
 * @return {object} The input.
 */
function daysInput() {
	return {
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
		dateField: comesBackField(),
		testid: 'case-act-days-input',
	}
}

/**
 * The date shown beside the days: when the suspended case comes back.
 *
 * @return {object} The date field.
 */
function comesBackField() {
	return {
		label: t('dossiq', 'The case comes back on'),
		note: t('dossiq', 'You pick the case up earlier if the answer comes in earlier.'),
		testid: 'case-act-comes-back',
	}
}

/**
 * The inputs an act's form can hold, in form order, each with when the
 * act asks it. The requester checkboxes follow them.
 */
const ACT_INPUTS = [
	{ when: (run) => run.asks.result, build: resultTemplateInput },
	{ when: (run) => run.asks.reason && run.layout.pauseReasons, build: pauseReasonInput },
	{ when: (run) => run.asks.reason, build: reasonInput },
	{ when: (run) => run.asks.result, build: resultTypeInput },
	{ when: (run) => run.asks.until, build: untilInput },
	{ when: (run) => run.asks.days, build: daysInput },
]

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
 * @param {object|null} [scope.reminder] The case type's reminder policy from `/lifecycle`.
 * @return {{inputs: Array<object>, columns: number, summary: object|null, notice: object|null, title: string, confirmIcon: object|null}} The form.
 * @spec openspec/specs/case-management/spec.md
 */
export function actForm(entry, { caseType = '', caseId = '', requester = '', reminder = null } = {}) {
	if (!entry) {
		return formOf({}, [])
	}
	const run = formRun(entry, { caseType, caseId, requester, reminder })
	const inputs = ACT_INPUTS.filter((row) => row.when(run)).map((row) => row.build(run))

	inputs.push(...requesterInputs(run))

	return formOf(run.layout, inputs)
}

/**
 * The form being built for one act: the act, what it asks, its layout and
 * the scope it was opened in.
 *
 * @param {object} entry The chosen act, a menu entry.
 * @param {object} scope The case type, case id, requester and reminder policy.
 * @return {{entry: object, asks: object, layout: object, scope: object}} The run.
 */
function formRun(entry, scope) {
	return {
		entry,
		asks: inputsFor(entry),
		layout: actLayouts()[String(entry.id ?? '')] ?? {},
		scope,
	}
}

/**
 * The checkboxes that tell or remind the requester, last in the form: the
 * notice on archiving, and on Suspend the reminder when the case type sets
 * one (board DqVolgendeStap). Without the server's reminder policy the
 * reminder is not offered, so a handler never ticks a reminder nobody sends.
 *
 * @param {object} run The form being built: the chosen act, its layout from
 *   actLayouts, and the scope with the requester's name and the reminder
 *   policy (`{ afterDays, maxCount }` from `/lifecycle`).
 * @return {Array<object>} The inputs.
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
function requesterInputs(run) {
	if (run.layout.notifyRequester) {
		return [notifyRequesterInput(run.scope.requester)]
	}
	const policy = reminderPolicy(run.scope.reminder)
	if (String(run.entry?.id ?? '') !== 'suspend' || policy === null) {
		return []
	}

	return [remindRequesterInput(policy)]
}

/**
 * The ticked checkbox that tells the requester the case is completed.
 *
 * @param {string} requester The requester's name, '' when unknown.
 * @return {object} The input.
 */
function notifyRequesterInput(requester) {
	return {
		field: 'notifyRequester',
		widget: 'checkbox',
		default: true,
		required: false,
		label: requester === ''
			? t('dossiq', 'Let the requester know the case is completed')
			: t('dossiq', 'Let {initiatorDisplayName} know the case is completed', { initiatorDisplayName: requester }),
		testid: 'case-act-notify-requester',
	}
}

/**
 * The reminder policy as numbers, null unless both are positive.
 *
 * @param {object|null} reminder `{ afterDays, maxCount }` from `/lifecycle`.
 * @return {{days: number, count: number}|null} The policy.
 */
function reminderPolicy(reminder) {
	const days = Number(reminder?.afterDays)
	const count = Number(reminder?.maxCount)
	return days > 0 && count > 0 ? { days, count } : null
}

/**
 * The checkbox that reminds the requester while the case is suspended.
 *
 * @param {{days: number, count: number}} policy The reminder policy.
 * @return {object} The input.
 */
function remindRequesterInput({ days, count }) {
	return {
		field: 'remindRequester',
		widget: 'checkbox',
		default: false,
		required: false,
		label: t('dossiq', 'Remind the requester if the answer does not come'),
		note: t('dossiq', 'After {days} days, at most {count} times, to the address the request went to. Each reminder goes on the timeline with a count. If it stays quiet after the last one, the case tells the team lead once.', { days, count }),
		testid: 'case-act-remind-requester',
	}
}
