/**
 * Deelzaak (sub-case) API service for Dossiq.
 *
 * Wraps the parent-child case relation endpoints exposed by
 * `lib/Controller/DeelzaakController.php`:
 *   GET    /apps/dossiq/api/deelzaken/{caseId}/children
 *   GET    /apps/dossiq/api/deelzaken/{caseId}/parent
 *   GET    /apps/dossiq/api/deelzaken/counts?ids=uuid1,uuid2,...
 *   POST   /apps/dossiq/api/deelzaken/validate
 *   POST   /apps/dossiq/api/deelzaken/{caseId}/unlink
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl(`/apps/dossiq/api/deelzaken${path}`)

/**
 * Fetch the sub-cases for a parent case.
 *
 * @param {string} parentCaseUuid Parent case UUID.
 * @return {Promise<Array>} Sub-case rows.
 */
export async function fetchSubCases(parentCaseUuid) {
	const { data } = await axios.get(
		base(`/${encodeURIComponent(parentCaseUuid)}/children`),
	)
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
		const { data } = await axios.get(
			base(`/${encodeURIComponent(parentCaseUuid)}/parent`),
		)
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
		const { data } = await axios.post(base('/validate'), {
			parentCaseUuid,
			childCaseTypeId,
		})
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
 * ⚠️ Returns the whole result, not a bare count. The endpoint used to answer
 * `200 OK` with a count that silently under-reported when some sub-cases could
 * not be detached, and the caller went on to delete the parent — orphaning the
 * rest under a dead reference (procest#793). `complete` is the field that
 * decides whether deleting the parent is safe; a `207` carries `complete: false`.
 *
 * `complete` defaults to false when the field is absent, so an older server
 * that still answers with a bare count blocks the delete rather than silently
 * permitting the failure mode this change exists to close.
 *
 * The requirement is explicit that it is ALL child cases — "The system MUST
 * clear the `parentCase` field on all child cases before proceeding with
 * deletion (orphan cleanup)" — which a 200-record page and a swallowed failure
 * did not satisfy.
 *
 * @param {string} parentCaseUuid Parent UUID.
 * @return {Promise<{unlinked: number, failed: number, total: number, complete: boolean}>} The unlink outcome.
 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-deletion-protection
 */
export async function unlinkSubCases(parentCaseUuid) {
	const { data } = await axios.post(
		base(`/${encodeURIComponent(parentCaseUuid)}/unlink`),
	)
	return {
		unlinked: data?.unlinked || 0,
		failed: data?.failed || 0,
		total: data?.total || 0,
		complete: data?.complete === true,
	}
}
