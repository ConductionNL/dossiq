/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Federated case-share/transfer/activity helpers — the pure logic behind
 * CaseSharingTab.vue, CreateFederatedShareDialog.vue and
 * PublicFederatedTransferPage.vue: endpoint URL builders, payload shaping,
 * and form validation. Extracted so it can be unit-tested directly (this
 * app's Vitest project has no Vue mount harness — see
 * tests/vitest/caseListExportAction.spec.js for the same constraint).
 *
 * Mirrors CaseSharingService::FEDERATION_ALLOWED_FIELDS (PHP is the source
 * of truth; the server independently re-validates every field/document
 * regardless of what this list contains — see design.md §2).
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

/** Mirrors CaseSharingService::FEDERATION_ALLOWED_FIELDS. */
export const FEDERATION_ALLOWED_FIELDS = [
	'title',
	'description',
	'status',
	'caseType',
	'priority',
	'dueDate',
	'requestedDate',
]

/**
 * Build the local (session-authenticated) federated-shares list endpoint.
 *
 * @return {string} the endpoint path.
 */
export function federatedSharesListEndpoint() {
	return '/apps/openregister/api/objects/dossiq/caseFederatedShare'
}

/**
 * Build the create-federated-share endpoint.
 *
 * @return {string} the endpoint path.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#requirement-federated-case-share-is-a-redacted-snapshot-never-the-live-case
 */
export function createFederatedShareEndpoint() {
	return '/apps/dossiq/api/federation/shares'
}

/**
 * Build the revoke-federated-share endpoint for one share.
 *
 * @param {string} shareId the caseFederatedShare UUID.
 * @return {string} the endpoint path.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#requirement-federated-share-revocation-is-immediate-and-single-sourced
 */
export function revokeFederatedShareEndpoint(shareId) {
	return `/apps/dossiq/api/federation/shares/${encodeURIComponent(shareId)}`
}

/**
 * Build the local activity list/post endpoint for one federated share.
 *
 * @param {string} federatedShareId the caseFederatedShare UUID.
 * @return {string} the endpoint path.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#requirement-shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
 */
export function federatedActivityEndpoint(federatedShareId) {
	return `/apps/dossiq/api/federation/activity/${encodeURIComponent(federatedShareId)}`
}

/**
 * Build the public (remote, token-authenticated) transfer accept/reject
 * endpoint.
 *
 * @param {string} shareToken the transfer-scoped OR federated-share bearer token.
 * @param {string} transferId the transfer UUID.
 * @return {string} the endpoint path.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#requirement-case-transfer-extends-across-federation-with-idempotent-accept-reject-and-a-custody-audit-trail
 */
export function publicFederatedTransferEndpoint(shareToken, transferId) {
	return `/apps/dossiq/api/public/federation/transfers/${shareToken}/${transferId}`
}

/**
 * Shape a federated-share creation payload from dialog form state: trims
 * the cloud id and de-duplicates/copies the field/document arrays so the
 * emitted payload never aliases the dialog's own reactive arrays.
 *
 * @param {object} form the raw dialog form state.
 * @param {string} caseId the case UUID.
 * @return {object} the payload to POST.
 */
export function shapeFederatedSharePayload(form, caseId) {
	return {
		caseId,
		remoteCloudId: (form.remoteCloudId || '').trim(),
		sharedFields: Array.from(new Set(form.sharedFields || [])),
		sharedDocuments: Array.from(new Set(form.sharedDocuments || [])),
	}
}

/**
 * Validate a federated-share creation form: requires a non-empty cloud id
 * and at least one selected field. This is a client-side convenience check
 * only — the server independently re-validates against the allow-list and
 * against the case's actual attached documents (fail closed either way).
 *
 * @param {object} form the raw dialog form state.
 * @return {boolean} whether the form may be submitted.
 */
export function isFederatedShareFormValid(form) {
	const cloudId = (form.remoteCloudId || '').trim()
	const fields = form.sharedFields || []
	return cloudId.length > 0 && fields.length > 0
}
