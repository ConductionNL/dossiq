// SPDX-License-Identifier: EUPL-1.2

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/advice')

export async function getAdviceForCase(caseId) {
	const response = await axios.get(baseUrl, { params: { case: caseId } })
	return response.data
}

export async function createAdvice(data) {
	const { caseId, ...rest } = data
	const response = await axios.post(baseUrl, rest, { params: { case: caseId } })
	return response.data
}

export async function getAdvice(id) {
	const response = await axios.get(`${baseUrl}/${id}`)
	return response.data
}

export async function updateAdvice(id, data) {
	const response = await axios.put(`${baseUrl}/${id}`, data)
	return response.data
}

export async function deleteAdvice(id) {
	const response = await axios.delete(`${baseUrl}/${id}`)
	return response.data
}

export async function sendReminder(id) {
	const response = await axios.post(`${baseUrl}/${id}/remind`)
	return response.data
}
