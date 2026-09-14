/**
 * The deleted side of a case: the lens, the restore and the destruction.
 *
 * Thin axios client over dossiq's recycle endpoints. OpenRegister owns the
 * soft delete, the window and the purge; dossiq publishes the window on the
 * case and decides who may destroy. All authorisation is enforced server-side.
 *
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (suffix = '') => generateUrl(`/apps/dossiq${suffix}`)

/**
 * The cases that were deleted and can still be recovered.
 *
 * @return {Promise<Array>} The rows, each with the date its window ends.
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
export async function listDeletedCases() {
	const { data } = await axios.get(base('/api/cases/deleted'))
	return (data && data.results) || []
}

/**
 * Delete a case into its recovery window.
 *
 * @param {string} caseId The case UUID.
 * @return {Promise<object>} What happened.
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
export async function deleteCase(caseId) {
	const { data } = await axios.post(
		base(`/api/case/${encodeURIComponent(caseId)}/delete`),
	)
	return data
}

/**
 * Get a deleted case back.
 *
 * @param {string} caseId The case UUID.
 * @return {Promise<object>} What was restored, and inside which window.
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
export async function restoreCase(caseId) {
	const { data } = await axios.post(
		base(`/api/case/${encodeURIComponent(caseId)}/restore`),
	)
	return data
}

/**
 * What a destruction would take with the case.
 *
 * @param {string} caseId The case UUID.
 * @return {Promise<object>} The scope, the window and both clocks.
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
export async function previewDestruction(caseId) {
	const { data } = await axios.get(
		base(`/api/case/${encodeURIComponent(caseId)}/destruction-preview`),
	)
	return data
}

/**
 * Destroy a deleted case. A second act, and it cannot be undone.
 *
 * @param {string} caseId The case UUID.
 * @param {boolean} waiveWindow Whether an open recovery window is waived.
 * @return {Promise<object>} What went, and the record of the act.
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
export async function destroyCase(caseId, waiveWindow = false) {
	const { data } = await axios.post(
		base(`/api/case/${encodeURIComponent(caseId)}/destroy`),
		{ waiveWindow },
	)
	return data
}

/**
 * The lawful-purpose clock and the archive clock of a case, read apart.
 *
 * @param {string} caseId The case UUID.
 * @return {Promise<object>} Both clocks, each labelled and naming its rule.
 * @spec openspec/changes/case-recycle-window/specs/case-management/spec.md
 */
export async function fetchRetentionClocks(caseId) {
	const { data } = await axios.get(
		base(`/api/case/${encodeURIComponent(caseId)}/retention-clocks`),
	)
	return data
}
