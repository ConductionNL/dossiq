// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * What dossiq needs and what this instance has, as CnReadinessChecklist items.
 *
 * Every row is read on the server at page load, from `Prerequisites::check()`
 * (initial state `prerequisites`): a PHP extension is not visible from a
 * browser, and an app the reader cannot see is not an app that is missing. A
 * missing prerequisite is reported, never worked around (ADR-102). Only a
 * required app blocks.
 *
 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
 */

/**
 * The groups the list is drawn in, in order.
 *
 * @param {(app: string, text: string) => string} t The app's translate.
 * @return {Array<{id: string, title: string}>} The groups.
 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
 */
export function prerequisiteGroups(t) {
	return [
		{ id: 'platform', title: t('dossiq', 'Platform') },
		{ id: 'extensions', title: t('dossiq', 'PHP extensions') },
		{ id: 'required', title: t('dossiq', 'Apps dossiq needs') },
		{ id: 'optional', title: t('dossiq', 'Apps dossiq works with') },
	]
}

/**
 * The checklist items for a server reading. An absent reading gives the
 * platform rows unmet and no lists, rather than throwing.
 *
 * @param {object} state The `prerequisites` initial state.
 * @param {(app: string, text: string) => string} t The app's translate.
 * @return {Array<object>} The items.
 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
 */
export function prerequisiteItems(state, t) {
	const reading = state && typeof state === 'object' ? state : {}
	const php = reading.php ?? { required: '', running: '', present: false }
	const nextcloud = reading.nextcloud ?? { min: '', max: '', running: '', present: false }
	const app = (group, blocking) => (row) => ({
		id: `app-${row.id}`,
		label: row.name || row.id,
		explanation: row.unlocks || '',
		met: row.present === true,
		group,
		blocking,
	})
	return [
		{
			id: 'php',
			label: `${t('dossiq', 'PHP')} ${php.required} ${t('dossiq', 'or higher')}`,
			explanation: `(${t('dossiq', 'this instance runs')} ${php.running})`,
			met: php.present === true,
			group: 'platform',
		},
		{
			id: 'nextcloud',
			label: `${t('dossiq', 'Nextcloud')} ${nextcloud.min} ${t('dossiq', 'to')} ${nextcloud.max}`,
			explanation: nextcloud.running ? `(${t('dossiq', 'this instance runs')} ${nextcloud.running})` : '',
			met: nextcloud.present === true,
			group: 'platform',
		},
		...(reading.extensions ?? []).map((row) => ({
			id: `ext-${row.name}`,
			label: row.name,
			explanation: row.why || '',
			met: row.present === true,
			group: 'extensions',
		})),
		...(reading.apps?.required ?? []).map(app('required', true)),
		...(reading.apps?.optional ?? []).map(app('optional', false)),
	]
}
