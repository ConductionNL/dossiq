/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure validation + payload-shaping helpers for the "Mijn gemeente" citizen
 * portal forms (DocumentList, MessagingWidget, BezwaarForm, KlachtForm). The
 * components stay thin: they bind inputs and delegate every validation and the
 * exact request body to these functions, which are unit-tested in isolation
 * (node env) so the wire shape matches the backend services
 * (PortalMessageService / PortalRequestService) exactly. No DOM, no network.
 *
 * @spec openspec/specs/zaakportaal-mijngemeente/spec.md
 */

/**
 * Recognised klacht (complaint) categories. Must match
 * PortalRequestService::KLACHT_CATEGORIES on the backend; an unmatched
 * category is rejected server-side with "Ongeldige categorie".
 *
 * @type {string[]}
 */
export const KLACHT_CATEGORIES = [
	'Bejegening',
	'Doorlooptijd',
	'Communicatie',
	'Medische/Zorgkwaliteit',
	'Andere',
]

/** Maximum message body length accepted by PortalMessageService. */
export const MAX_MESSAGE_LENGTH = 5000

/**
 * Validate the message composer state (REQ-POR-007).
 *
 * @param {object} state           The composer state.
 * @param {string} state.caseId    The case the message belongs to.
 * @param {string} state.content   The message body.
 * @return {{ valid: boolean, errors: object }} Per-field error keys (i18n
 *   English source strings), empty when valid.
 */
export function validateMessage({ caseId = '', content = '' } = {}) {
	const errors = {}
	if (!String(caseId).trim()) {
		errors.caseId = 'No case selected'
	}
	if (!String(content).trim()) {
		errors.content = 'Message cannot be empty'
	} else if (String(content).length > MAX_MESSAGE_LENGTH) {
		errors.content = 'Message is too long'
	}
	return { valid: Object.keys(errors).length === 0, errors }
}

/**
 * Shape the POST /messages body (REQ-POR-007). Never includes any identity
 * field — the backend derives the pseudonymous senderRef from the session.
 *
 * @param {object} state The composer state.
 * @return {object} The request body.
 */
export function buildMessagePayload({ caseId = '', caseReference = '', subject = '', content = '' } = {}) {
	const body = {
		caseId: String(caseId).trim(),
		content: String(content).trim(),
	}
	if (String(caseReference).trim()) body.caseReference = String(caseReference).trim()
	if (String(subject).trim()) body.subject = String(subject).trim()
	return body
}

/**
 * Validate the bezwaar (objection) form (REQ-POR-008). The authoritative
 * deadline check is server-side; here we guard the obvious client preconditions
 * and the consent checkbox.
 *
 * @param {object}  state                The form state.
 * @param {string}  state.tegenZaakId    The contested case id.
 * @param {string}  state.decisionDate   The decision date (ISO yyyy-mm-dd).
 * @param {string}  state.motivering     The grounds for objection.
 * @param {boolean} state.consent        Whether the data-use consent is given.
 * @param {boolean} state.binnenTermijn  Whether the deadline is still open
 *   (from the validate-deadline probe); when explicitly false, blocks submit.
 * @return {{ valid: boolean, errors: object }}
 */
export function validateBezwaar({ tegenZaakId = '', decisionDate = '', motivering = '', consent = false, binnenTermijn = null } = {}) {
	const errors = {}
	if (!String(tegenZaakId).trim()) {
		errors.tegenZaakId = 'No case to object against'
	}
	if (!String(decisionDate).trim()) {
		errors.decisionDate = 'Decision date is required'
	}
	if (!String(motivering).trim()) {
		errors.motivering = 'Please state your grounds for objection'
	}
	if (!consent) {
		errors.consent = 'You must agree to the use of your data for this procedure'
	}
	if (binnenTermijn === false) {
		errors.deadline = 'The objection deadline has passed'
	}
	return { valid: Object.keys(errors).length === 0, errors }
}

/**
 * Shape the POST /objections body (REQ-POR-008).
 *
 * @param {object} state The form state.
 * @return {object} The request body.
 */
export function buildBezwaarPayload({ tegenZaakId = '', tegenBeschikkingId = '', decisionDate = '', onderwerp = '', motivering = '', attachments = [] } = {}) {
	const body = {
		tegenZaakId: String(tegenZaakId).trim(),
		decisionDate: String(decisionDate).trim(),
		motivering: String(motivering).trim(),
	}
	if (String(tegenBeschikkingId).trim()) body.tegenBeschikkingId = String(tegenBeschikkingId).trim()
	if (String(onderwerp).trim()) body.onderwerp = String(onderwerp).trim()
	if (Array.isArray(attachments) && attachments.length) {
		body.attachments = attachments.map(String)
	}
	return body
}

/**
 * Validate the klacht (complaint) form (REQ-POR-009).
 *
 * @param {object} state              The form state.
 * @param {string} state.categorie    The complaint category.
 * @param {string} state.omschrijving The complaint description.
 * @return {{ valid: boolean, errors: object }}
 */
export function validateKlacht({ categorie = '', omschrijving = '' } = {}) {
	const errors = {}
	if (!KLACHT_CATEGORIES.includes(categorie)) {
		errors.categorie = 'Please choose a valid category'
	}
	if (!String(omschrijving).trim()) {
		errors.omschrijving = 'Please describe your complaint'
	}
	return { valid: Object.keys(errors).length === 0, errors }
}

/**
 * Shape the POST /complaints body (REQ-POR-009).
 *
 * @param {object} state The form state.
 * @return {object} The request body.
 */
export function buildKlachtPayload({ categorie = '', omschrijving = '', betrokkenMedewerker = '', onderwerp = '' } = {}) {
	const body = {
		categorie: String(categorie),
		omschrijving: String(omschrijving).trim(),
	}
	if (String(betrokkenMedewerker).trim()) body.betrokkenMedewerker = String(betrokkenMedewerker).trim()
	if (String(onderwerp).trim()) body.onderwerp = String(onderwerp).trim()
	return body
}

/**
 * Normalise a raw documents array (as returned on the case detail) to the
 * shape DocumentList renders (REQ-POR-005). The backend has already applied
 * the downloadbaarVoor ACL; this only guards against missing fields so the
 * list never throws on partial data.
 *
 * @param {Array} documents The documents from the case detail response.
 * @return {Array<{ id: string, naam: string, soort: string, datum: string }>}
 */
export function normaliseDocuments(documents) {
	if (!Array.isArray(documents)) return []
	return documents
		.filter(d => d && typeof d === 'object')
		.map(d => ({
			id: String(d.id || ''),
			naam: String(d.naam || d.title || ''),
			soort: String(d.soort || d.documentType || ''),
			datum: String(d.datum || d.creationDate || ''),
		}))
		.filter(d => d.id !== '')
}
