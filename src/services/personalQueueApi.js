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
 * Remember how the reader groups their queue.
 *
 * @param {string} groupBy One of source, priority, due.
 * @return {Promise<string>} The grouping now stored.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export async function saveGrouping(groupBy) {
	const { data } = await axios.post(generateUrl(`${BASE}/grouping`), { groupBy })

	return data?.groupBy ?? 'source'
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

