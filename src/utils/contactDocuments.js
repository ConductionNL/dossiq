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
 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md
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
 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md
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

/**
 * The address that opens the document a dispatch row names.
 *
 * A document backed by a Nextcloud file opens at `/f/{fileId}`, which Files
 * resolves to the file in its folder for a reader who may see it and refuses
 * for one who may not. A document that only points elsewhere opens its
 * `link`, and only an http(s) one, because the field is free text and a
 * `javascript:` value must not become a navigation. A row whose document was
 * not inlined (extend answered the bare uuid) names no file, so it answers
 * the empty string rather than guessing.
 *
 * @param {object} row One `dispatch` row.
 * @return {string} The address, or the empty string.
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/party-pages/spec.md#requirement-the-contact-page-lists-the-contacts-documents-and-communication-req-crs-04
 */
export function documentUrlOfDispatch(row) {
	const doc = row && row.document
	if (doc === null || typeof doc !== 'object') {
		return ''
	}
	const fileId = Number(doc.fileId)
	if (Number.isInteger(fileId) && fileId > 0) {
		return generateUrl(`/f/${fileId}`)
	}
	const link = typeof doc.link === 'string' ? doc.link.trim() : ''
	if (/^https?:\/\//i.test(link)) {
		return link
	}
	return ''
}

/**
 * Row-action handler for the contact and organisation documents panels: open
 * the document the row names, where `openCaseOfDocument` opens its case.
 *
 * A function for the same reason that one is: the row is a dispatch, and what
 * it names lives in a field of the row rather than in the row's own id.
 *
 * @param {...object} args The dispatch arguments, read as `openCaseOfDocument`
 *   reads them.
 * @return {void}
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/party-pages/spec.md#requirement-the-contact-page-lists-the-contacts-documents-and-communication-req-crs-04
 */
export function openDocumentOfDispatch(...args) {
	let row = null
	for (const arg of args) {
		if (arg === null || typeof arg !== 'object') {
			continue
		}
		row = arg.item && typeof arg.item === 'object' ? arg.item : arg
	}

	const url = documentUrlOfDispatch(row)
	if (url === '') {
		return
	}

	window.location.assign(url)
}
