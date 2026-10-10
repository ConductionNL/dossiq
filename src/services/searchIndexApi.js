/**
 * OpenRegister search index client for dossiq.
 *
 * The indexes behind object search are openregister's (decision D22, the query
 * layer is openregister's). dossiq reads their state and keeps no copy: a
 * second record of how many indexes exist is one an administrator can read
 * while the real ones are missing.
 *
 *   GET /apps/openregister/api/settings/search-index
 *       — the tables in scope, the indexes on each, whether the platform can
 *         rebuild concurrently, and when maintenance last ran.
 *
 * The acts belong to `occ openregister:tables:search-index`, which takes
 * `rebuild`, `snapshot`, `restore` and `status`, and does nothing without
 * `--apply`. They are named on the panel rather than offered as buttons: a
 * rebuild takes every magic table in scope and there is no way to stop one
 * from a settings page.
 *
 * A failed read throws. An outage and an instance with no indexes at all look
 * identical from the browser, and only one of them is somebody's problem.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The occ command that rebuilds, snapshots, restores and reports the indexes. */
export const SEARCH_INDEX_COMMAND = 'occ openregister:tables:search-index'

/**
 * What openregister says about the indexes behind object search.
 *
 * @return {Promise<object>} `{concurrentRebuildSupported, tables, tableCount, indexCount, lastRun}`,
 *     `lastRun` being an ISO 8601 time or null.
 * @throws {Error} When openregister cannot be read.
 *
 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
 */
export async function searchIndexStatus() {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/settings/search-index'),
	)

	if (data === null || typeof data !== 'object' || data.error !== undefined) {
		throw new Error(
			String(data?.error ?? 'The search index status could not be read.'),
		)
	}

	return {
		concurrentRebuildSupported: data.concurrentRebuildSupported === true,
		tables: data.tables && typeof data.tables === 'object' ? data.tables : {},
		tableCount: Number(data.tableCount ?? 0),
		indexCount: Number(data.indexCount ?? 0),
		lastRun: lastRunTime(data.lastRun),
	}
}

/**
 * When the last stored run finished, or started, or null when none ran.
 *
 * OpenRegister answers `lastRun` as the REPORT of that run, an object with
 * `startedAt` and (for a run that got that far) `finishedAt`, and as `[]`
 * when nothing ever ran. Printing the report itself drew an empty "Last run"
 * for the never-ran case and "[object Object]" for the ran case.
 *
 * @param {object|Array|string|null|undefined} report What openregister answered as `lastRun`.
 * @return {string|null} An ISO 8601 time, or null when no run is on record.
 *
 * @spec openspec/changes/r5-admin-settings-and-tour-tell-the-truth/specs/admin-settings/spec.md
 */
export function lastRunTime(report) {
	if (typeof report === 'string') {
		return report === '' ? null : report
	}

	if (report === null || typeof report !== 'object' || Array.isArray(report)) {
		return null
	}

	const time = report.finishedAt || report.startedAt || ''

	return typeof time === 'string' && time !== '' ? time : null
}
