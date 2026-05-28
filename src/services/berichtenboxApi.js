import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/berichtenbox')

/**
 * @param data
 * @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md
 */
export async function sendMessage(data) {
	const response = await axios.post(`${baseUrl}/send`, data)
	return response.data
}

/**
 * @param caseId
 * @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md
 */
export async function listMessages(caseId) {
	const response = await axios.get(`${baseUrl}/messages`, { params: { caseId } })
	return response.data
}

/** @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md */
export async function getTypeCodes() {
	const response = await axios.get(`${baseUrl}/types`)
	return response.data
}

/**
 * @param messageId
 * @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md
 */
export async function pollReadStatus(messageId) {
	const response = await axios.post(`${baseUrl}/poll/${messageId}`)
	return response.data
}
