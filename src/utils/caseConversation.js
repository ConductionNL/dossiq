// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Starting a live conversation on a case and declaring a case major: the one
 * implementation behind the live-conversation panel's buttons and the
 * Contact tab's card buttons ("Gesprek starten", "Groot incident melden").
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { caseIdFromLocation } from './savedMail.js'

/**
 * Start a conversation in Talk from a case and open the room it created.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<object|null>} The conversation the case recorded, or null.
 */
export async function startConversation(caseId) {
	const { data } = await axios.post(
		generateUrl('/apps/dossiq/api/cases/{caseId}/conversations', { caseId }),
		{},
	)
	const conversation = data?.conversation || null
	if (conversation?.roomUrl) {
		window.open(conversation.roomUrl, '_blank', 'noopener')
	}
	return conversation
}

/**
 * Declare a case major, which opens its one working channel, and open it.
 *
 * @param {string} caseId The case uuid.
 * @return {Promise<string>} The working channel's address, or ''.
 */
export async function declareMajor(caseId) {
	const { data } = await axios.post(
		generateUrl('/apps/dossiq/api/cases/{caseId}/major', { caseId }),
		{},
	)
	const channelUrl = data?.channel?.roomUrl || ''
	if (channelUrl) {
		window.open(channelUrl, '_blank', 'noopener')
	}
	return channelUrl
}

/**
 * The sentence a refused start or declaration reads.
 *
 * @param {unknown} error What the request threw.
 * @return {string} The sentence.
 */
export function conversationError(error) {
	const unresolved = error?.response?.data?.unresolved
	if (Array.isArray(unresolved) && unresolved.length > 0) {
		return t('dossiq', 'We could not find these responders: {names}', { names: unresolved.join(', ') })
	}
	return error?.response?.data?.reason || t('dossiq', 'Unknown error')
}

/**
 * Handler for the card button "Gesprek starten": start a conversation on the
 * open case, then let the page read the case again so the panel lists it.
 *
 * @return {Promise<void>}
 */
export async function startCaseConversation() {
	const caseId = caseIdFromLocation()
	if (caseId === '') {
		return
	}
	try {
		await startConversation(caseId)
		emit('cn:page:refresh')
	} catch (error) {
		showError(conversationError(error))
	}
}

/**
 * Handler for the card button "Groot incident melden": declare the open case
 * major, then let the page read the case again.
 *
 * @return {Promise<void>}
 */
export async function declareCaseMajor() {
	const caseId = caseIdFromLocation()
	if (caseId === '') {
		return
	}
	try {
		await declareMajor(caseId)
		emit('cn:page:refresh')
	} catch (error) {
		showError(conversationError(error))
	}
}
