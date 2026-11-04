// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The search index status as label and value rows, in the two forms the admin
 * tabs draw: the run (when, how long, what it indexed) on Integrations, and the
 * rows against the index with the rebuild command on Advanced. A value is never
 * empty: a run that never happened reads Never, not a blank.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { getCanonicalLocale, translate as t } from '@nextcloud/l10n'
import { SEARCH_INDEX_COMMAND } from '../services/searchIndexApi.js'

/**
 * A number in the reader's language.
 *
 * @param {number} value The number.
 * @return {string} The number, grouped.
 */
function formatCount(value) {
	return Number(value).toLocaleString(getCanonicalLocale())
}

/**
 * When the last run finished, or Never.
 *
 * @param {string|null} lastRun An ISO 8601 time, or null.
 * @return {string} The moment in words.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function lastRunText(lastRun) {
	if (!lastRun) {
		return t('dossiq', 'Never')
	}
	const when = new Date(lastRun)
	if (Number.isNaN(when.getTime())) {
		return String(lastRun)
	}
	return when.toLocaleString(getCanonicalLocale(), { dateStyle: 'long', timeStyle: 'short' })
}

/**
 * A duration in minutes and seconds ("4 min 12 s").
 *
 * @param {number} seconds Whole seconds.
 * @return {string} The duration.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function durationText(seconds) {
	const minutes = Math.floor(seconds / 60)
	const rest = seconds % 60
	return minutes > 0
		? t('dossiq', '{minutes} min {seconds} s', { minutes, seconds: rest })
		: t('dossiq', '{seconds} s', { seconds: rest })
}

/**
 * What the last run indexed, per kind ("12.418 cases, 31.902 documents").
 *
 * @param {object|null} indexed Counts by kind (`cases`, `documents`).
 * @return {string} The counts, or "nothing yet".
 */
function indexedText(indexed) {
	const parts = []
	if (indexed && Number.isFinite(Number(indexed.cases))) {
		parts.push(t('dossiq', '{count} cases', { count: formatCount(indexed.cases) }))
	}
	if (indexed && Number.isFinite(Number(indexed.documents))) {
		parts.push(t('dossiq', '{count} documents', { count: formatCount(indexed.documents) }))
	}
	return parts.length > 0 ? parts.join(', ') : t('dossiq', 'nothing yet')
}

/**
 * The run rows of the Integrations tab: last run, duration and what was indexed.
 *
 * @param {object} status What `searchIndexStatus()` answered.
 * @return {Array<{label: string, value: string, muted: boolean, strong?: boolean}>} The rows.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
export function runFacts(status) {
	const ran = Boolean(status.lastRun)
	return [
		{ label: t('dossiq', 'Latest run'), value: lastRunText(status.lastRun), muted: false, strong: true },
		{
			label: t('dossiq', 'Duration'),
			value: status.lastRunSeconds === null ? t('dossiq', 'no run yet') : durationText(status.lastRunSeconds),
			muted: status.lastRunSeconds === null,
		},
		{ label: t('dossiq', 'Indexed'), value: indexedText(ran ? status.indexed : null), muted: !ran || !status.indexed },
	]
}

/**
 * The row rows of the Advanced tab: rows in the tables and in the index, the
 * last run with its duration, and the command that rebuilds. A count
 * openregister does not report is left out.
 *
 * @param {object} status What `searchIndexStatus()` answered.
 * @return {Array<{label: string, value: string, code?: boolean, hint?: string}>} The rows.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
export function indexRowFacts(status) {
	const rows = []
	if (status.rowsInTables !== null) {
		rows.push({ label: t('dossiq', 'Rows in the tables'), value: formatCount(status.rowsInTables) })
	}
	if (status.rowsInIndex !== null) {
		rows.push({ label: t('dossiq', 'Rows in the index'), value: formatCount(status.rowsInIndex) })
	}
	const lastRun = lastRunText(status.lastRun)
	rows.push({
		label: t('dossiq', 'Latest run'),
		value: status.lastRunSeconds === null ? lastRun : `${lastRun} · ${durationText(status.lastRunSeconds)}`,
	})
	rows.push({
		label: t('dossiq', 'Rebuild with'),
		value: `${SEARCH_INDEX_COMMAND} rebuild`,
		code: true,
		hint: t('dossiq', 'With snapshot, restore and status as the other verbs of the same command.'),
	})
	return rows
}
