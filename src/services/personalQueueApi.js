/**
 * The one personal queue, read over one endpoint.
 *
 * Every call here answers for the signed-in reader and none of them takes a
 * user id. That is the same rule the controller keeps, written down on both
 * sides: a client that could ask for somebody else's queue would eventually be
 * pointed at one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The endpoint root. */
const BASE = '/apps/dossiq/api/personal-queue'

/**
 * Everything waiting on the reader, most pressing first.
 *
 * @return {Promise<object>} The queue: items, groups, hidden groups and unavailable sources.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function fetchQueue() {
	const { data } = await axios.get(generateUrl(BASE))

	return {
		items: (data?.items ?? []),
		groups: (data?.groups ?? []),
		groupBy: (data?.groupBy ?? 'source'),
		hiddenGroups: (data?.hiddenGroups ?? []),
		unavailable: (data?.unavailable ?? []),
		total: Number(data?.total ?? 0),
	}
}

/**
 * The cases and tasks the reader may close their day on.
 *
 * @return {Promise<{items: Array, unavailable: Array}>} The candidates.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function fetchEndOfDay() {
	const { data } = await axios.get(generateUrl(`${BASE}/end-of-day`))

	return { items: (data?.items ?? []), unavailable: (data?.unavailable ?? []) }
}

/**
 * Hide one group until tomorrow.
 *
 * This is the ONLY way an item leaves the reader's screen by their own hand,
 * and it is a group at a time on purpose. An item cannot be dismissed while
 * the work behind it stands, so there is no call here that would.
 *
 * @param {string} group The group key.
 * @return {Promise<Array>} The groups hidden after the call.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function hideGroupForToday(group) {
	const { data } = await axios.post(
		generateUrl(`${BASE}/groups/${encodeURIComponent(group)}/hide`),
		{},
	)

	return (data?.hiddenGroups ?? [])
}

/**
 * Show a group the reader hid.
 *
 * @param {string} group The group key.
 * @return {Promise<Array>} The groups hidden after the call.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function showGroupAgain(group) {
	const { data } = await axios.post(
		generateUrl(`${BASE}/groups/${encodeURIComponent(group)}/show`),
		{},
	)

	return (data?.hiddenGroups ?? [])
}

/**
 * Remember how the reader groups their queue.
 *
 * @param {string} groupBy One of source, priority, due.
 * @return {Promise<string>} The grouping now stored.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function saveGrouping(groupBy) {
	const { data } = await axios.post(generateUrl(`${BASE}/grouping`), { groupBy })

	return (data?.groupBy ?? 'source')
}

/**
 * Plan an item on the reader's own agenda, with no case behind it.
 *
 * @param {object} item The item.
 * @param {string} item.title What it is.
 * @param {string} item.startsAt When it starts.
 * @param {string} item.template Which template it came from.
 * @return {Promise<object>} What was planned.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function planItem({ title, startsAt, template = '' }) {
	const { data } = await axios.post(generateUrl(`${BASE}/planned-items`), {
		title,
		startsAt,
		template,
	})

	return data
}

/**
 * The reader's own stage on one case.
 *
 * @param {string} caseId The case.
 * @return {Promise<string>} The stage, or an empty string.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function fetchPersonalStage(caseId) {
	const { data } = await axios.get(
		generateUrl(`${BASE}/stages/${encodeURIComponent(caseId)}`),
	)

	return String(data?.stage ?? '')
}

/**
 * Set the reader's own stage on one case.
 *
 * @param {string} caseId The case.
 * @param {string} stage The stage, or an empty string to clear it.
 * @return {Promise<string>} The stage now stored.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function savePersonalStage(caseId, stage) {
	const { data } = await axios.post(
		generateUrl(`${BASE}/stages/${encodeURIComponent(caseId)}`),
		{ stage },
	)

	return String(data?.stage ?? '')
}

/**
 * When the reader wants their digest.
 *
 * @return {Promise<{enabled: boolean, hour: number}>} The settings.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function fetchDigestSettings() {
	const { data } = await axios.get(generateUrl(`${BASE}/digest`))

	return { enabled: (data?.enabled !== false), hour: Number(data?.hour ?? 8) }
}

/**
 * Save when the reader wants their digest.
 *
 * @param {boolean} enabled Whether they want one.
 * @param {number} hour The hour they want it in.
 * @return {Promise<{enabled: boolean, hour: number}>} The settings now stored.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function saveDigestSettings(enabled, hour) {
	const { data } = await axios.post(generateUrl(`${BASE}/digest`), { enabled, hour })

	return { enabled: (data?.enabled !== false), hour: Number(data?.hour ?? 8) }
}
