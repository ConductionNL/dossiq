/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case-assistant API service (case-assistant-via-hermiq).
 *
 * Thin axios wrapper for dossiq's case-assistant endpoints. Conversational
 * assistance is delegated to Hermiq (fleet rule: AI functionality lives in
 * Hermiq); dossiq's backend only enriches the request with the case context
 * the current user is authorized to see and forwards it.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/dossiq/api/assistant')

/**
 * Whether the case assistant is available (Hermiq installed + enabled).
 * The panel renders nothing when this is false.
 *
 * @return {Promise<boolean>} True when the assistant backend is available
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
export async function fetchAssistantAvailability() {
	try {
		const response = await axios.get(`${baseUrl}/availability`)
		return response.data?.available === true
	} catch (e) {
		// Fail closed: an erroring availability probe hides the panel.
		return false
	}
}

/**
 * Send one conversational message about a case.
 *
 * @param {string} caseId The case UUID
 * @param {string} message The user's message
 * @return {Promise<object>} `{reply, usage}`
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */
export async function converse(caseId, message) {
	const response = await axios.post(`${baseUrl}/converse`, { caseId, message })
	return response.data
}
