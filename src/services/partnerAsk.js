/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Ask a partner (an advisory body, a supplier) for something from a case,
 * through portaliq's `POST /api/partner-tasks/ask`. Portaliq checks that the
 * handler may write on the case, finds or pre-provisions the partner's portal
 * account from the KvK number and e-mail address, and puts the task in the
 * partner's portal inbox with the due date and upload rules frozen on it.
 *
 * @spec openspec/specs/partner-tasks-in-the-portal/spec.md (portaliq, REQ-PTP-002)
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The register and schema a dossiq case lives in. */
export const CASE_REGISTER = 'dossiq'
export const CASE_SCHEMA = 'case'

/** The upload rule of "a PDF must be uploaded". */
export const PDF_REQUIRED = Object.freeze({ mimeType: 'application/pdf', required: true })

/**
 * An empty ask form.
 *
 * @return {{title: string, description: string, dueAt: string, kvk: string, email: string, name: string, pdfRequired: boolean}}
 */
export function emptyAsk() {
	return { title: '', description: '', dueAt: '', kvk: '', email: '', name: '', pdfRequired: false }
}

/**
 * Why the ask cannot be sent yet, or '' when it can.
 *
 * @param {object} form The ask form.
 * @param {string} caseId The case the ask hangs on.
 * @return {string} The sentence.
 */
export function askRefusal(form, caseId) {
	if (String(caseId || '') === '') {
		return t('dossiq', 'Open a case first.')
	}
	if (String(form.title || '').trim() === '') {
		return t('dossiq', 'Say what the partner is asked for.')
	}
	if (/^\d{8}$/.test(String(form.kvk || '').trim()) === false) {
		return t('dossiq', 'Enter the partner\'s KvK number of eight digits.')
	}
	if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(form.email || '').trim()) === false) {
		return t('dossiq', 'Enter the partner\'s e-mail address.')
	}
	if (form.dueAt && /^\d{4}-\d{2}-\d{2}$/.test(String(form.dueAt)) === false) {
		return t('dossiq', 'Choose the date the answer is wanted by.')
	}
	return ''
}

/**
 * The request body portaliq's ask route takes.
 *
 * @param {object} form The ask form.
 * @param {string} caseId The case the ask hangs on.
 * @return {object} The body.
 */
export function askPayload(form, caseId) {
	const name = String(form.name || '').trim()
	const partner = { kvk: String(form.kvk).trim(), email: String(form.email).trim() }
	if (name !== '') {
		partner.name = name
	}

	return {
		register: CASE_REGISTER,
		schema: CASE_SCHEMA,
		caseId: String(caseId),
		title: String(form.title).trim(),
		description: String(form.description || '').trim(),
		dueAt: String(form.dueAt || ''),
		partner,
		uploadRules: form.pdfRequired === true ? [{ ...PDF_REQUIRED }] : [],
	}
}

/**
 * The sentence for a refused or failed ask.
 *
 * @param {number|undefined} status The HTTP status, undefined when there was no answer.
 * @return {string} The sentence.
 */
export function askFailure(status) {
	if (status === 403) {
		return t('dossiq', 'You may not ask a partner on this case.')
	}
	if (status === 400) {
		return t('dossiq', 'The ask was not raised. Check the partner\'s KvK number and e-mail address.')
	}
	if (status === 404) {
		return t('dossiq', 'Portaliq is not installed here, so a partner cannot be asked.')
	}
	return t('dossiq', 'The ask could not be sent. Try again in a moment.')
}

/**
 * Send the ask to portaliq.
 *
 * @param {object} payload The body from askPayload().
 * @return {Promise<{ok: boolean, data: object|null, error: string}>} The outcome.
 */
export async function sendAsk(payload) {
	try {
		const { data } = await axios.post(generateUrl('/apps/portaliq/api/partner-tasks/ask'), payload)
		return { ok: true, data: data || null, error: '' }
	} catch (e) {
		return { ok: false, data: null, error: askFailure(e?.response?.status) }
	}
}
