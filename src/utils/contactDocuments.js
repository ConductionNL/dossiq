// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import { generateUrl } from '@nextcloud/router'

/**
 * The case id a dispatch row belongs to.
 *
 * A dispatch is one correspondent of one document on one case, so the case is
 * a field of the row rather than the row's own id. `content.extend` may have
 * inlined it as an object, and it may equally still be the bare uuid, because
 * extend is a request the endpoint may answer either way. Both shapes are read
 * here rather than assumed, which is the same rule
 * `BulkDocumentActionDialog.resolveDocumentIds` follows for `informatieobject`.
 *
 * @param {object} row One `dispatch` row.
 * @return {string} The case uuid, or the empty string.
 * @spec openspec/changes/archive/2026-09-20-the-contact-360-shows-documents/specs/kcc-klantcontact-integratie/spec.md
 */
export function caseIdOfDispatch(row) {
	const value = row && row.case
	if (typeof value === 'string') {
		return value
	}
	if (value !== null && typeof value === 'object') {
		return String(value.id || value['@self']?.id || '')
	}
	return ''
}

/**
 * Row-action handler for the contact and organisation documents panels: open
 * the case this document is on.
 *
 * A FUNCTION handler, for a reason `claimCase` in `utils/caseClaim.js` already
 * records and one of its own. The row dispatcher's vocabulary is `navigate`,
 * `open-page` and a handler name, and none of the three can read an id OUT of
 * the row: `rowRoute` pushes the row's own id, which here is the id of a
 * dispatch record and not of a case, so a declared route would navigate to a
 * case page for a uuid no case has. That failure looks exactly like a deleted
 * case, which is why it is worth a function.
 *
 * `window.location.assign` rather than a router push, the same way
 * `openIntegriqConnections` leaves the app: a bare handler is called outside a
 * component and has no `$router` to push onto.
 *
 * @param {...object} args The dispatch arguments. CnIndexPage calls a handler with
 *   `{ actionId, item }`; CnObjectListWidget appends the row as the last
 *   argument. Both shapes are read, because the panels this serves are
 *   rendered by the second and the first is what every other handler here
 *   receives.
 * @return {void}
 *
 * @spec openspec/changes/archive/2026-09-20-the-contact-360-shows-documents/specs/kcc-klantcontact-integratie/spec.md
 */
export function openCaseOfDocument(...args) {
	let row = null
	for (const arg of args) {
		if (arg === null || typeof arg !== 'object') {
			continue
		}
		row = arg.item && typeof arg.item === 'object' ? arg.item : arg
	}

	const caseId = caseIdOfDispatch(row)
	if (caseId === '') {
		return
	}

	window.location.assign(
		generateUrl(`/apps/dossiq/cases/${encodeURIComponent(caseId)}`),
	)
}
