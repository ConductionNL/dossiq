// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Table and fact configuration for the admin cards that report rather than
 * edit: the Woo refusal grounds on Integrations, and the advice and
 * publication cards on VTH.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

/** Where the full list of Woo refusal grounds is kept and a ground is added. */
export const WOO_REFUSAL_GROUNDS_PATH = '/apps/dossiq/settings/woo-refusal-grounds'

/**
 * The Woo refusal grounds table's source: dossiq's grounds, by code.
 *
 * @return {object} The table source.
 * @spec openspec/specs/woo-refusal-grounds/spec.md
 */
export function wooRefusalGroundSource() {
	return { register: 'dossiq', schema: 'wooRefusalGround', sort: { field: 'code', dir: 'asc' } }
}

/**
 * The Woo refusal grounds table's columns: code, label, article, kind and
 * whether the ground can still be cited.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @return {Array<object>} The columns.
 * @spec openspec/specs/woo-refusal-grounds/spec.md
 */
export function wooRefusalGroundColumns(t) {
	return [
		{ key: 'code', label: t('dossiq', 'Code'), cellClass: 'cn-cell--strong' },
		{ key: 'label', label: t('dossiq', 'Label') },
		{ key: 'article', label: t('dossiq', 'Article') },
		{
			key: 'kind',
			label: t('dossiq', 'Kind'),
			enum: ['absolute', 'relative', 'temporary'],
			enumLabels: { absolute: 'Absolute', relative: 'Relative', temporary: 'Temporary' },
		},
		{
			key: 'status',
			label: t('dossiq', 'Active'),
			enum: ['active', 'retired'],
			enumLabels: { active: 'Yes', retired: 'No' },
		},
	]
}

/**
 * Whole weeks when a number of days is a whole number of weeks, else days.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @param {number} days The days.
 * @return {string} The term in words.
 */
function termText(t, days) {
	return days > 0 && days % 7 === 0
		? t('dossiq', '{weeks} weeks', { weeks: days / 7 })
		: t('dossiq', '{days} days', { days })
}

/**
 * The advice card's rows: the standard term, who is asked and when a reminder goes out.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @param {object} settings The `consultationSettings` initial state.
 * @param {Array<object>} bodies The active advisory bodies (`name`, `type`, `email`).
 * @return {Array<{label: string, value: string}>} The rows.
 * @spec openspec/specs/vth-admin-settings/spec.md
 */
export function adviceRows(t, settings, bodies) {
	const deadline = Number(settings.defaultDeadlineDays ?? 28)
	const warning = Number(settings.warningOffsetDays ?? 5)
	const advisers = bodies.map((body) => {
		if (body.type === 'external') {
			return body.email
				? t('dossiq', '{name} (external, by email)', { name: body.name })
				: t('dossiq', '{name} (external)', { name: body.name })
		}
		return t('dossiq', '{name} (internal)', { name: body.name })
	})
	return [
		{ label: t('dossiq', 'Standard term'), value: t('dossiq', '{term} after the request, adjustable per adviser', { term: termText(t, deadline) }) },
		{ label: t('dossiq', 'Advisers'), value: advisers.length > 0 ? advisers.join(', ') : t('dossiq', 'None yet'), muted: advisers.length === 0 },
		{ label: t('dossiq', 'Reminder'), value: termText(t, warning) },
	]
}

/**
 * The publication card's rows: the template, the publication date and the
 * objection period that runs from it.
 *
 * @param {(app: string, text: string, vars?: object) => string} t The app's translate.
 * @param {object} settings The `publicationSettings` initial state (`template`, `dateRule`).
 * @return {Array<{label: string, value: string}>} The rows.
 * @spec openspec/specs/vth-admin-settings/spec.md
 */
export function publicationRows(t, settings) {
	const dateRules = {
		'first-wednesday': t('dossiq', 'First Wednesday after the decision'),
		'decision-date': t('dossiq', 'The day of the decision'),
	}
	const notSet = t('dossiq', 'Not set')
	return [
		{ label: t('dossiq', 'Template'), value: settings.template || notSet, muted: !settings.template },
		{ label: t('dossiq', 'Publication date'), value: dateRules[settings.dateRule] ?? notSet, muted: !dateRules[settings.dateRule] },
		{ label: t('dossiq', 'Objection period'), value: t('dossiq', '6 weeks after the publication date, unless the case type sets its own term; a reminder goes out one week before') },
	]
}
