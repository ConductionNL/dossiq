/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A case's grants to Nextcloud users and groups, as OpenRegister's
 * object-level sharing keeps them: where they live, which of them the case
 * page lists, and the permissions a grant asks for.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md
 */

/** Nextcloud share permission bits a case grant uses. */
const PERMISSION_READ = 1
const PERMISSION_UPDATE = 2

/** The grant types a person or a group on this instance has. */
const PERSONAL_TYPES = ['user', 'group']

/**
 * The OpenRegister endpoint for a case's grants, or for one of them.
 *
 * @param {string} caseId The case UUID.
 * @param {string} [shareId] The grant's id, as OpenRegister listed it (`ocinternal:7`).
 * @return {string} The path, for generateUrl.
 */
export function caseGrantsEndpoint(caseId, shareId) {
	const base = `/apps/openregister/api/objects/dossiq/case/${encodeURIComponent(String(caseId))}/shares`
	return shareId === undefined ? base : `${base}/${encodeURIComponent(String(shareId))}`
}

/**
 * The permissions a grant asks for: reading, or reading and editing.
 *
 * @param {boolean} canEdit Whether the grantee may edit the case.
 * @return {number} The Nextcloud permission bitmask.
 */
export function grantPermissions(canEdit) {
	return canEdit === true ? (PERMISSION_READ | PERMISSION_UPDATE) : PERMISSION_READ
}

/**
 * The grants to users and groups, each saying whether it allows editing.
 * Remote and link grants are left out: the sharing tab lists those itself.
 *
 * @param {Array<object>} results The grants OpenRegister listed.
 * @return {Array<{id: string, type: string, sharedWith: string, canEdit: boolean}>}
 */
export function personalGrants(results) {
	return (Array.isArray(results) ? results : [])
		.filter((grant) => grant && PERSONAL_TYPES.includes(grant.type) && grant.id)
		.map((grant) => ({
			id: String(grant.id),
			type: grant.type,
			sharedWith: String(grant.sharedWith ?? ''),
			canEdit: (Number(grant.permissions) & PERMISSION_UPDATE) !== 0,
		}))
}
