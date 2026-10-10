/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Nextcloud groups an administrator can name as a case type's handling teams.
 *
 * @spec openspec/changes/case-type-handling-teams/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Read the instance's groups, id and display name, sorted by name.
 *
 * Nextcloud answers this for administrators and group admins, which is who
 * edits a case type.
 *
 * @return {Promise<Array<{id: string, label: string}>>} The groups.
 * @spec openspec/changes/case-type-handling-teams/specs/case-types/spec.md#requirement-a-case-type-names-the-teams-that-handle-it-req-ct-44
 */
export async function fetchNextcloudGroups() {
	const { data } = await axios.get(generateOcsUrl('cloud/groups/details'), {
		params: { limit: 500 },
	})
	const groups = data?.ocs?.data?.groups ?? []

	return groups
		.map((group) => ({ id: group.id, label: group.displayname || group.id }))
		.sort((left, right) => left.label.localeCompare(right.label))
}
