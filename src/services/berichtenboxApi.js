import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/berichtenbox')

export async function sendMessage(data) {
	const response = await axios.post(`${baseUrl}/send`, data)
	return response.data
}

export async function listMessages(caseId) {
	const response = await axios.get(`${baseUrl}/messages`, { params: { caseId } })
	return response.data
}

export async function getTypeCodes() {
	const response = await axios.get(`${baseUrl}/types`)
	return response.data
}

export async function pollReadStatus(messageId) {
	const response = await axios.post(`${baseUrl}/poll/${messageId}`)
	return response.data
}
