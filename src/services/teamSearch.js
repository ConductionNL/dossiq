/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Find the teams (Nextcloud groups) a case can be handed to.
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/** The share type Nextcloud gives a group. */
const SHARE_TYPE_GROUP = 1

/**
 * The groups whose name matches the query, as select options.
 *
 * Read through the sharees search, which answers any signed-in user and
 * honours the instance's sharing restrictions, rather than the group
 * administration API, which answers administrators only.
 *
 * @param {string} query What was typed.
 * @return {Promise<Array<{id: string, label: string}>>} The matching groups.
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */
export async function searchTeams(query) {
	const search = String(query ?? '').trim()
	if (search === '') {
		return []
	}
	const { data } = await axios.get(generateOcsUrl('apps/files_sharing/api/v1/sharees'), {
		params: { search, itemType: 'file', shareType: [SHARE_TYPE_GROUP], perPage: 20, lookup: false },
	})
	const result = data?.ocs?.data ?? {}
	const groups = [...(result.exact?.groups ?? []), ...(result.groups ?? [])]

	return groups.map((group) => ({ id: String(group.value?.shareWith ?? ''), label: String(group.label ?? group.value?.shareWith ?? '') }))
		.filter((group) => group.id !== '')
}
