/**
 * Deelzaak (sub-case) API service for Procest.
 *
 * Wraps the parent-child case relation endpoints exposed by
 * `lib/Controller/DeelzaakController.php`:
 *   GET    /apps/procest/api/deelzaken/{caseId}/children
 *   GET    /apps/procest/api/deelzaken/{caseId}/parent
 *   GET    /apps/procest/api/deelzaken/counts?ids=uuid1,uuid2,...
 *   POST   /apps/procest/api/deelzaken/validate
 *   POST   /apps/procest/api/deelzaken/{caseId}/unlink
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl(`/apps/procest/api/deelzaken${path}`)

/**
 * Fetch the sub-cases for a parent case.
 *
 * @param {string} parentCaseUuid Parent case UUID.
 * @return {Promise<Array>} Sub-case rows.
 */
export async function fetchSubCases(parentCaseUuid) {
	const { data } = await axios.get(base(`/${encodeURIComponent(parentCaseUuid)}/children`))
	return data.results || []
}

/**
 * Fetch the parent case object.
 *
 * @param {string} parentCaseUuid Parent case UUID.
 * @return {Promise<object|null>} Parent case or null when not found.
 */
export async function fetchParentCase(parentCaseUuid) {
	try {
		const { data } = await axios.get(base(`/${encodeURIComponent(parentCaseUuid)}/parent`))
		return data
	} catch (err) {
		if (err?.response?.status === 404) {
			return null
		}
		throw err
	}
}

/**
 * Batch sub-case counts for a list of parent UUIDs.
 *
 * @param {Array<string>} caseUuidArray UUIDs for which to count children.
 * @return {Promise<object>} Map keyed by parent UUID.
 */
export async function fetchSubCaseCounts(caseUuidArray) {
	if (!Array.isArray(caseUuidArray) || caseUuidArray.length === 0) {
		return {}
	}
	const { data } = await axios.get(base('/counts'), {
		params: { ids: caseUuidArray.join(',') },
	})
	return data.counts || {}
}

/**
 * Validate a sub-case creation request against the parent caseType.
 *
 * @param {object} params Validation params.
 * @param {string} params.parentCaseUuid Parent UUID.
 * @param {string} params.childCaseTypeId Child caseType id or slug.
 * @return {Promise<{ok: boolean, reason?: string}>}
 */
export async function validateSubCase({ parentCaseUuid, childCaseTypeId }) {
	try {
		const { data } = await axios.post(base('/validate'), { parentCaseUuid, childCaseTypeId })
		return data
	} catch (err) {
		if (err?.response?.data) {
			return err.response.data
		}
		return { ok: false, reason: 'unknown_error' }
	}
}

/**
 * Unlink every sub-case of the given parent.
 *
 * @param {string} parentCaseUuid Parent UUID.
 * @return {Promise<number>} Number of records unlinked.
 */
export async function unlinkSubCases(parentCaseUuid) {
	const { data } = await axios.post(base(`/${encodeURIComponent(parentCaseUuid)}/unlink`))
	return data.unlinked || 0
}
