// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Cell-formatter registry for procest's manifest-driven index pages.
//
// Each entry is `(value, row, property) => string|number` — pure data
// shaping, referenced by id from `pages[].config.columns[].formatter`
// in src/manifest.json (resolved by CnDataTable / CnCellRenderer via the
// `formatters` prop passed to CnAppRoot, see @conduction/nextcloud-vue →
// docs/migrating-to-manifest.md "Column formatters"). Keep this file to
// pure functions — the Vue layer stays the library's abstract
// CnIndexPage / CnDataTable; only the app-specific per-row logic lives
// here. (`mapFormatters.js` is the separate registry for `type:"map"`
// marker formatting.)

import { translate as t } from '@nextcloud/l10n'

const VOORSTEL_STATUS_LABELS = {
	concept: 'Concept',
	in_parafering: 'In parafering',
	ter_accordering: 'Ter accordering',
	geaccordeerd: 'Geaccordeerd',
	aangeboden: 'Aangeboden',
	besloten: 'Besloten',
	gearchiveerd: 'Gearchiveerd',
	teruggestuurd: 'Teruggestuurd',
}

const VOORSTEL_TYPE_LABELS = {
	dt_advies: 'DT-advies',
	collegeadvies: 'Collegeadvies',
	raadsvoorstel: 'Raadsvoorstel',
}

/**
 * Parse a voorstel's parafeerroute snapshot into a step array.
 *
 * @param {object} row The voorstel object.
 * @return {Array<object>} The steps (possibly empty).
 */
function voorstelSteps(row) {
	const snap = row && row.routeSnapshot
	if (!snap) return []
	try {
		return typeof snap === 'string' ? JSON.parse(snap) : snap
	} catch {
		return []
	}
}

/**
 * Metadata `updated` timestamp for a row, tolerating both `@self.updated`
 * (OpenRegister metadata envelope) and the older `_self.updated` /
 * `updatedAt` shapes.
 *
 * @param {object} row The object.
 * @return {string|undefined} ISO timestamp, or undefined.
 */
function rowUpdated(row) {
	if (!row) return undefined
	return (row['@self'] && row['@self'].updated)
		|| (row._self && row._self.updated)
		|| row.updatedAt
		|| undefined
}

export default {
	/**
	 * Human label for a voorstel `type` enum value.
	 *
	 * @param {string} value The raw `type`.
	 * @return {string}
	 */
	voorstelType: (value) => t('procest', VOORSTEL_TYPE_LABELS[value] || value || '-'),

	/**
	 * Human label for a voorstel `status` enum value (also rendered as a
	 * `widget: "badge"` pill).
	 *
	 * @param {string} value The raw `status`.
	 * @return {string}
	 */
	voorstelStatus: (value) => t('procest', VOORSTEL_STATUS_LABELS[value] || value || '-'),

	/**
	 * `currentStep / totalSteps` progress for a voorstel's parafeerroute.
	 *
	 * @param {*} value Unused (the column key is `currentStep`).
	 * @param {object} row The voorstel object.
	 * @return {string}
	 */
	voorstelStepProgress: (value, row) => {
		const steps = voorstelSteps(row)
		if (!steps.length || !row || !row.currentStep) return '-'
		return `${row.currentStep}/${steps.length}`
	},

	/**
	 * Label / actor of the step the voorstel is currently waiting on.
	 *
	 * @param {*} value Unused.
	 * @param {object} row The voorstel object.
	 * @return {string}
	 */
	voorstelWaitingActor: (value, row) => {
		const steps = voorstelSteps(row)
		if (!steps.length || !row || !row.currentStep) return '-'
		const current = steps.find((s) => s.order === row.currentStep)
		return current ? (current.label || current.actor || '-') : '-'
	},

	/**
	 * Number of days the voorstel has been in its current step.
	 *
	 * @param {*} value Unused (the column key is `@self.updated`).
	 * @param {object} row The voorstel object.
	 * @return {string}
	 */
	voorstelDaysInStep: (value, row) => {
		const updated = rowUpdated(row)
		if (!updated) return '-'
		const days = Math.floor((Date.now() - new Date(updated).getTime()) / 86400000)
		return `${days}d`
	},
}
