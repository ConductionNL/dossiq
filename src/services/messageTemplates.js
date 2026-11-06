/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's message templates, for any place a handler writes a message.
 *
 * @spec openspec/specs/case-messages/spec.md#requirement-choosing-a-message-template-fills-the-message
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Whether a template fits one of the channels a compose surface sends on. A
 * template without a channel fits every channel, as does a surface naming none.
 *
 * @param {object} template The template.
 * @param {Array<string>} channels The surface's channels.
 * @return {boolean} Whether it fits.
 */
export function fitsChannel(template, channels) {
	const channel = template && typeof template.channel === 'string' ? template.channel : ''
	return channel === '' || !Array.isArray(channels) || channels.length === 0 || channels.includes(channel)
}

/**
 * The active templates of a case type that fit the channels.
 *
 * @param {string} caseTypeId The case type.
 * @param {Array<string>} channels The surface's channels.
 * @return {Promise<Array<object>>} The templates.
 */
export async function listMessageTemplates(caseTypeId, channels = []) {
	const { data } = await axios.get(generateUrl(`/apps/dossiq/api/casetypes/${encodeURIComponent(caseTypeId)}/email-templates`))
	const results = Array.isArray(data?.results) ? data.results : []
	return results.filter((template) => template && template.id && fitsChannel(template, channels))
}

/**
 * A template rendered for a case: its subject and body with the case's values.
 *
 * @param {string} caseId The case.
 * @param {string} templateId The template.
 * @return {Promise<{subject: string, body: string}>} The draft.
 */
export async function draftFromTemplate(caseId, templateId) {
	const { data } = await axios.post(generateUrl(`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/email-templates/${encodeURIComponent(templateId)}/draft`))
	return { subject: String(data?.subject ?? ''), body: String(data?.body ?? '') }
}
