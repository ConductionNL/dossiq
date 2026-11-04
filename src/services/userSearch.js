/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * People in this Nextcloud, found through its sharee search.
 *
 * @spec openspec/specs/advice-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const SHARE_TYPE_USER = 0

/**
 * The users whose name or id matches what was typed.
 *
 * Fewer than two characters ask nothing and find nobody; a failed search
 * finds nobody too, so a picker shows an empty list rather than an error.
 *
 * @param {string} term What was typed.
 * @return {Promise<Array<{id: string, label: string}>>} The users.
 */
export async function searchUsers(term) {
	const search = String(term ?? '').trim()
	if (search.length < 2) {
		return []
	}
	try {
		const { data } = await axios.get(generateOcsUrl('apps/files_sharing/api/v1/sharees'), {
			params: { search, itemType: 'file', perPage: 20, shareType: [SHARE_TYPE_USER], format: 'json' },
		})
		const result = data?.ocs?.data ?? {}
		const users = [...(result.exact?.users ?? []), ...(result.users ?? [])]

		return users
			.filter((entry) => entry?.value?.shareType === SHARE_TYPE_USER && entry.value.shareWith)
			.map((entry) => ({ id: String(entry.value.shareWith), label: String(entry.label || entry.value.shareWith) }))
	} catch {
		return []
	}
}
